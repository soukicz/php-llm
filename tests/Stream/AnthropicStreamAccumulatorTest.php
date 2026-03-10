<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Stream;

use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Stream\AnthropicStreamAccumulator;
use Soukicz\Llm\Stream\CallableStreamListener;
use Soukicz\Llm\Stream\StreamEvent;
use Soukicz\Llm\Stream\StreamEventType;

class AnthropicStreamAccumulatorTest extends TestCase {
    public function testTextOnlyResponse(): void {
        $sse = $this->buildSse([
            ['event' => 'message_start', 'data' => ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 25, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0]]]],
            ['event' => 'content_block_start', 'data' => ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']]],
            ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => ' world']]],
            ['event' => 'content_block_stop', 'data' => ['type' => 'content_block_stop', 'index' => 0]],
            ['event' => 'message_delta', 'data' => ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 12]]],
            ['event' => 'message_stop', 'data' => ['type' => 'message_stop']],
        ]);

        $events = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        });

        $result = AnthropicStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        // Verify reconstructed response
        $this->assertCount(1, $result['content']);
        $this->assertEquals('text', $result['content'][0]['type']);
        $this->assertEquals('Hello world', $result['content'][0]['text']);
        $this->assertEquals('end_turn', $result['stop_reason']);
        $this->assertEquals(25, $result['usage']['input_tokens']);
        $this->assertEquals(12, $result['usage']['output_tokens']);

        // Verify listener received events
        $textDeltas = array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TEXT_DELTA);
        $this->assertCount(2, $textDeltas);
        $deltas = array_values($textDeltas);
        $this->assertEquals('Hello', $deltas[0]->delta);
        $this->assertEquals(' world', $deltas[1]->delta);

        // Verify message lifecycle events
        $this->assertEquals(StreamEventType::MESSAGE_START, $events[0]->type);
        $this->assertEquals(StreamEventType::MESSAGE_COMPLETE, $events[array_key_last($events)]->type);
    }

    public function testToolUseResponse(): void {
        $sse = $this->buildSse([
            ['event' => 'message_start', 'data' => ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 50]]]],
            ['event' => 'content_block_start', 'data' => ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Let me search.']]],
            ['event' => 'content_block_stop', 'data' => ['type' => 'content_block_stop', 'index' => 0]],
            ['event' => 'content_block_start', 'data' => ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'search', 'input' => []]]],
            ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"que']]],
            ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'ry": "test"}']]],
            ['event' => 'content_block_stop', 'data' => ['type' => 'content_block_stop', 'index' => 1]],
            ['event' => 'message_delta', 'data' => ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 30]]],
            ['event' => 'message_stop', 'data' => ['type' => 'message_stop']],
        ]);

        $events = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        });

        $result = AnthropicStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        // Verify reconstructed response
        $this->assertCount(2, $result['content']);
        $this->assertEquals('text', $result['content'][0]['type']);
        $this->assertEquals('Let me search.', $result['content'][0]['text']);
        $this->assertEquals('tool_use', $result['content'][1]['type']);
        $this->assertEquals('toolu_123', $result['content'][1]['id']);
        $this->assertEquals('search', $result['content'][1]['name']);
        $this->assertEquals(['query' => 'test'], $result['content'][1]['input']);
        $this->assertEquals('tool_use', $result['stop_reason']);

        // Verify tool use events
        $toolStarts = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_USE_START));
        $this->assertCount(1, $toolStarts);
        $this->assertEquals('search', $toolStarts[0]->toolName);
        $this->assertEquals('toolu_123', $toolStarts[0]->toolId);

        $toolDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_INPUT_DELTA));
        $this->assertCount(2, $toolDeltas);
        $this->assertEquals('{"que', $toolDeltas[0]->delta);
        $this->assertEquals('search', $toolDeltas[0]->toolName);
    }

    public function testThinkingResponse(): void {
        $sse = $this->buildSse([
            ['event' => 'message_start', 'data' => ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 10]]]],
            ['event' => 'content_block_start', 'data' => ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']]],
            ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Let me think...']]],
            ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig_abc123']]],
            ['event' => 'content_block_stop', 'data' => ['type' => 'content_block_stop', 'index' => 0]],
            ['event' => 'content_block_start', 'data' => ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'The answer is 42.']]],
            ['event' => 'content_block_stop', 'data' => ['type' => 'content_block_stop', 'index' => 1]],
            ['event' => 'message_delta', 'data' => ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 20]]],
            ['event' => 'message_stop', 'data' => ['type' => 'message_stop']],
        ]);

        $events = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        });

        $result = AnthropicStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        // Verify reconstructed response
        $this->assertCount(2, $result['content']);
        $this->assertEquals('thinking', $result['content'][0]['type']);
        $this->assertEquals('Let me think...', $result['content'][0]['thinking']);
        $this->assertEquals('sig_abc123', $result['content'][0]['signature']);
        $this->assertEquals('text', $result['content'][1]['type']);
        $this->assertEquals('The answer is 42.', $result['content'][1]['text']);

        // Verify thinking events
        $thinkingDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::THINKING_DELTA));
        $this->assertCount(1, $thinkingDeltas);
        $this->assertEquals('Let me think...', $thinkingDeltas[0]->delta);
    }

    public function testToolUseWithEmptyInput(): void {
        $sse = $this->buildSse([
            ['event' => 'message_start', 'data' => ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 10]]]],
            ['event' => 'content_block_start', 'data' => ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_456', 'name' => 'get_time', 'input' => []]]],
            ['event' => 'content_block_stop', 'data' => ['type' => 'content_block_stop', 'index' => 0]],
            ['event' => 'message_delta', 'data' => ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 5]]],
            ['event' => 'message_stop', 'data' => ['type' => 'message_stop']],
        ]);

        $listener = new CallableStreamListener(function (StreamEvent $event) {});

        $result = AnthropicStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        $this->assertCount(1, $result['content']);
        $this->assertEquals('tool_use', $result['content'][0]['type']);
        $this->assertEquals([], $result['content'][0]['input']);
    }

    public function testCacheTokensArePreserved(): void {
        $sse = $this->buildSse([
            ['event' => 'message_start', 'data' => ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 100, 'cache_creation_input_tokens' => 50, 'cache_read_input_tokens' => 30]]]],
            ['event' => 'content_block_start', 'data' => ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hi']]],
            ['event' => 'content_block_stop', 'data' => ['type' => 'content_block_stop', 'index' => 0]],
            ['event' => 'message_delta', 'data' => ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]]],
            ['event' => 'message_stop', 'data' => ['type' => 'message_stop']],
        ]);

        $listener = new CallableStreamListener(function (StreamEvent $event) {});

        $result = AnthropicStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        $this->assertEquals(100, $result['usage']['input_tokens']);
        $this->assertEquals(50, $result['usage']['cache_creation_input_tokens']);
        $this->assertEquals(30, $result['usage']['cache_read_input_tokens']);
        $this->assertEquals(1, $result['usage']['output_tokens']);
    }

    public function testErrorEventThrowsException(): void {
        $sse = $this->buildSse([
            ['event' => 'message_start', 'data' => ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 10]]]],
            ['event' => 'content_block_start', 'data' => ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Partial']]],
            ['event' => 'error', 'data' => ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]],
        ]);

        $listener = new CallableStreamListener(function (StreamEvent $event) {});

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Anthropic stream error (overloaded_error): Overloaded');

        AnthropicStreamAccumulator::consume(Utils::streamFor($sse), $listener);
    }

    public function testPingEventsAreIgnored(): void {
        $sse = $this->buildSse([
            ['event' => 'message_start', 'data' => ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 5]]]],
            ['event' => 'ping', 'data' => ['type' => 'ping']],
            ['event' => 'content_block_start', 'data' => ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Ok']]],
            ['event' => 'content_block_stop', 'data' => ['type' => 'content_block_stop', 'index' => 0]],
            ['event' => 'message_delta', 'data' => ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]]],
            ['event' => 'message_stop', 'data' => ['type' => 'message_stop']],
        ]);

        $listener = new CallableStreamListener(function (StreamEvent $event) {});

        $result = AnthropicStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        $this->assertEquals('Ok', $result['content'][0]['text']);
    }

    public function testReplayTextResponse(): void {
        $responseData = [
            'content' => [
                ['type' => 'text', 'text' => 'Hello world'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 25, 'output_tokens' => 12],
        ];

        $events = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        });

        AnthropicStreamAccumulator::replay($responseData, $listener);

        $this->assertEquals(StreamEventType::MESSAGE_START, $events[0]->type);
        $this->assertEquals(StreamEventType::MESSAGE_COMPLETE, $events[array_key_last($events)]->type);

        $textDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TEXT_DELTA));
        $this->assertCount(1, $textDeltas);
        $this->assertEquals('Hello world', $textDeltas[0]->delta);
        $this->assertEquals(0, $textDeltas[0]->blockIndex);

        $blockStops = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::CONTENT_BLOCK_STOP));
        $this->assertCount(1, $blockStops);
    }

    public function testReplayToolUseResponse(): void {
        $responseData = [
            'content' => [
                ['type' => 'text', 'text' => 'Let me search.'],
                ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'search', 'input' => ['query' => 'test']],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 30],
        ];

        $events = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        });

        AnthropicStreamAccumulator::replay($responseData, $listener);

        $toolStarts = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_USE_START));
        $this->assertCount(1, $toolStarts);
        $this->assertEquals('search', $toolStarts[0]->toolName);
        $this->assertEquals('toolu_123', $toolStarts[0]->toolId);
        $this->assertEquals(1, $toolStarts[0]->blockIndex);

        $toolDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_INPUT_DELTA));
        $this->assertCount(1, $toolDeltas);
        $this->assertEquals('{"query":"test"}', $toolDeltas[0]->delta);
        $this->assertEquals('search', $toolDeltas[0]->toolName);
        $this->assertEquals('toolu_123', $toolDeltas[0]->toolId);
    }

    public function testReplayToolUseWithEmptyInput(): void {
        $responseData = [
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_456', 'name' => 'get_time', 'input' => []],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];

        $events = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        });

        AnthropicStreamAccumulator::replay($responseData, $listener);

        $toolStarts = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_USE_START));
        $this->assertCount(1, $toolStarts);
        $this->assertEquals('get_time', $toolStarts[0]->toolName);

        // Empty input should NOT produce a TOOL_INPUT_DELTA (matching consume() behavior)
        $toolDeltas = array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_INPUT_DELTA);
        $this->assertCount(0, $toolDeltas);
    }

    public function testReplayThinkingResponse(): void {
        $responseData = [
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Let me think...', 'signature' => 'sig_abc123'],
                ['type' => 'text', 'text' => 'The answer is 42.'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ];

        $events = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        });

        AnthropicStreamAccumulator::replay($responseData, $listener);

        $thinkingDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::THINKING_DELTA));
        $this->assertCount(1, $thinkingDeltas);
        $this->assertEquals('Let me think...', $thinkingDeltas[0]->delta);
        $this->assertEquals(0, $thinkingDeltas[0]->blockIndex);

        $textDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TEXT_DELTA));
        $this->assertCount(1, $textDeltas);
        $this->assertEquals('The answer is 42.', $textDeltas[0]->delta);
        $this->assertEquals(1, $textDeltas[0]->blockIndex);

        $blockStops = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::CONTENT_BLOCK_STOP));
        $this->assertCount(2, $blockStops);
    }

    private function buildSse(array $events): string {
        $sse = '';
        foreach ($events as $event) {
            $sse .= 'event: ' . $event['event'] . "\n";
            $sse .= 'data: ' . json_encode($event['data'], JSON_THROW_ON_ERROR) . "\n\n";
        }

        return $sse;
    }
}
