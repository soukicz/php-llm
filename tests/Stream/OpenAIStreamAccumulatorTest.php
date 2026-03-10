<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Stream;

use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Stream\CallableStreamListener;
use Soukicz\Llm\Stream\OpenAIStreamAccumulator;
use Soukicz\Llm\Stream\StreamEvent;
use Soukicz\Llm\Stream\StreamEventType;

class OpenAIStreamAccumulatorTest extends TestCase {
    public function testTextOnlyResponse(): void {
        $sse = $this->buildSse([
            ['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Hello'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => ['content' => ' world'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
        ]);

        $events = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        });

        $result = OpenAIStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        // Verify reconstructed response
        $this->assertEquals('Hello world', $result['choices'][0]['message']['content']);
        $this->assertEquals('stop', $result['choices'][0]['finish_reason']);
        $this->assertEquals(10, $result['usage']['prompt_tokens']);
        $this->assertEquals(5, $result['usage']['completion_tokens']);

        // Verify listener events
        $textDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TEXT_DELTA));
        $this->assertCount(2, $textDeltas);
        $this->assertEquals('Hello', $textDeltas[0]->delta);
        $this->assertEquals(' world', $textDeltas[1]->delta);

        $this->assertEquals(StreamEventType::MESSAGE_START, $events[0]->type);
        $this->assertEquals(StreamEventType::MESSAGE_COMPLETE, $events[array_key_last($events)]->type);
    }

    public function testToolCallResponse(): void {
        $sse = $this->buildSse([
            ['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [['index' => 0, 'id' => 'call_abc', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '']]]], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"que']]]], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'ry": "test"}']]]], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10]],
        ]);

        $events = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        });

        $result = OpenAIStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        // Verify reconstructed response
        $this->assertArrayNotHasKey('content', $result['choices'][0]['message']);
        $this->assertCount(1, $result['choices'][0]['message']['tool_calls']);
        $tc = $result['choices'][0]['message']['tool_calls'][0];
        $this->assertEquals('call_abc', $tc['id']);
        $this->assertEquals('function', $tc['type']);
        $this->assertEquals('search', $tc['function']['name']);
        $this->assertEquals('{"query": "test"}', $tc['function']['arguments']);
        $this->assertEquals('tool_calls', $result['choices'][0]['finish_reason']);

        // Verify tool events
        $toolStarts = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_USE_START));
        $this->assertCount(1, $toolStarts);
        $this->assertEquals('search', $toolStarts[0]->toolName);
        $this->assertEquals('call_abc', $toolStarts[0]->toolId);

        $toolDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_INPUT_DELTA));
        $this->assertCount(2, $toolDeltas);
        $this->assertEquals('{"que', $toolDeltas[0]->delta);
        $this->assertEquals('ry": "test"}', $toolDeltas[1]->delta);
    }

    public function testMultipleToolCalls(): void {
        $sse = $this->buildSse([
            ['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [
                ['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'tool_a', 'arguments' => '']],
            ]], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => '{"x":1}']],
            ]], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [
                ['index' => 1, 'id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'tool_b', 'arguments' => '{"y":2}']],
            ]], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 15]],
        ]);

        $listener = new CallableStreamListener(function (StreamEvent $event) {});

        $result = OpenAIStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        $this->assertCount(2, $result['choices'][0]['message']['tool_calls']);
        $this->assertEquals('call_1', $result['choices'][0]['message']['tool_calls'][0]['id']);
        $this->assertEquals('{"x":1}', $result['choices'][0]['message']['tool_calls'][0]['function']['arguments']);
        $this->assertEquals('call_2', $result['choices'][0]['message']['tool_calls'][1]['id']);
        $this->assertEquals('{"y":2}', $result['choices'][0]['message']['tool_calls'][1]['function']['arguments']);
    }

    public function testTextWithToolCalls(): void {
        $sse = $this->buildSse([
            ['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Let me help.'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_x', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{}']]]], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3]],
        ]);

        $listener = new CallableStreamListener(function (StreamEvent $event) {});

        $result = OpenAIStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        $this->assertEquals('Let me help.', $result['choices'][0]['message']['content']);
        $this->assertCount(1, $result['choices'][0]['message']['tool_calls']);
        $this->assertEquals('lookup', $result['choices'][0]['message']['tool_calls'][0]['function']['name']);
    }

    public function testRefusalResponse(): void {
        $sse = $this->buildSse([
            ['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'refusal' => ''], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => ['refusal' => "I can't"], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => ['refusal' => ' help with that.'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 4]],
        ]);

        $events = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        });

        $result = OpenAIStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        $this->assertEquals("I can't help with that.", $result['choices'][0]['message']['refusal']);
        $this->assertArrayNotHasKey('content', $result['choices'][0]['message']);

        // Refusal text should be emitted as TEXT_DELTA events
        $textDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TEXT_DELTA));
        $this->assertCount(2, $textDeltas);
    }

    public function testContentFilterFinishReason(): void {
        $sse = $this->buildSse([
            ['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Some text'], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'content_filter']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2]],
        ]);

        $listener = new CallableStreamListener(function (StreamEvent $event) {});

        $result = OpenAIStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        $this->assertEquals('content_filter', $result['choices'][0]['finish_reason']);
    }

    private function buildSse(array $chunks): string {
        $sse = '';
        foreach ($chunks as $chunk) {
            $sse .= 'data: ' . json_encode($chunk, JSON_THROW_ON_ERROR) . "\n\n";
        }
        $sse .= "data: [DONE]\n\n";

        return $sse;
    }
}
