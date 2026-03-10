<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Stream;

use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Stream\CallableStreamListener;
use Soukicz\Llm\Stream\GeminiStreamAccumulator;
use Soukicz\Llm\Stream\StreamEvent;
use Soukicz\Llm\Stream\StreamEventType;

class GeminiStreamAccumulatorTest extends TestCase {
    public function testTextOnlyResponse(): void {
        $sse = $this->buildSse([
            ['candidates' => [['content' => ['parts' => [['text' => 'Hello']]], 'finishReason' => null]]],
            ['candidates' => [['content' => ['parts' => [['text' => ' world']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]],
        ]);

        $events = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        });

        $result = GeminiStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        // Verify reconstructed response
        $this->assertCount(2, $result['candidates'][0]['content']['parts']);
        $this->assertEquals('Hello', $result['candidates'][0]['content']['parts'][0]['text']);
        $this->assertEquals(' world', $result['candidates'][0]['content']['parts'][1]['text']);
        $this->assertEquals('STOP', $result['candidates'][0]['finishReason']);
        $this->assertEquals(10, $result['usageMetadata']['promptTokenCount']);
        $this->assertEquals(5, $result['usageMetadata']['candidatesTokenCount']);

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
            ['candidates' => [['content' => ['parts' => [['text' => 'Let me search.']]]]]],
            ['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'search', 'args' => ['query' => 'test']]]]], 'finishReason' => 'FUNCTION_CALL']], 'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 8]],
        ]);

        $events = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        });

        $result = GeminiStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        // Verify reconstructed response
        $parts = $result['candidates'][0]['content']['parts'];
        $this->assertCount(2, $parts);
        $this->assertEquals('Let me search.', $parts[0]['text']);
        $this->assertEquals('search', $parts[1]['functionCall']['name']);
        $this->assertEquals(['query' => 'test'], $parts[1]['functionCall']['args']);
        $this->assertEquals('FUNCTION_CALL', $result['candidates'][0]['finishReason']);

        // Verify tool events
        $toolStarts = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_USE_START));
        $this->assertCount(1, $toolStarts);
        $this->assertEquals('search', $toolStarts[0]->toolName);

        $toolDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_INPUT_DELTA));
        $this->assertCount(1, $toolDeltas);
        $this->assertEquals('{"query":"test"}', $toolDeltas[0]->delta);
    }

    public function testChunksWithoutCandidates(): void {
        $sse = $this->buildSse([
            ['candidates' => [['content' => ['parts' => [['text' => 'Hi']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 1]],
        ]);

        $listener = new CallableStreamListener(function (StreamEvent $event) {});

        $result = GeminiStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        $this->assertCount(1, $result['candidates'][0]['content']['parts']);
        $this->assertEquals('Hi', $result['candidates'][0]['content']['parts'][0]['text']);
    }

    public function testImageResponse(): void {
        $sse = $this->buildSse([
            ['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/jpeg', 'data' => 'base64data']]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 100]],
        ]);

        $listener = new CallableStreamListener(function (StreamEvent $event) {});

        $result = GeminiStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        $this->assertCount(1, $result['candidates'][0]['content']['parts']);
        $this->assertEquals('image/jpeg', $result['candidates'][0]['content']['parts'][0]['inlineData']['mimeType']);
    }

    public function testThinkingResponse(): void {
        $sse = $this->buildSse([
            ['candidates' => [['content' => ['parts' => [['thought' => 'Let me think about this...']]]]]],
            ['candidates' => [['content' => ['parts' => [['text' => 'The answer is 42.']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 8]],
        ]);

        $events = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        });

        $result = GeminiStreamAccumulator::consume(Utils::streamFor($sse), $listener);

        // Verify thinking part is included in reconstruction
        $this->assertCount(2, $result['candidates'][0]['content']['parts']);
        $this->assertEquals('Let me think about this...', $result['candidates'][0]['content']['parts'][0]['thought']);
        $this->assertEquals('The answer is 42.', $result['candidates'][0]['content']['parts'][1]['text']);

        // Verify thinking events
        $thinkingDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::THINKING_DELTA));
        $this->assertCount(1, $thinkingDeltas);
        $this->assertEquals('Let me think about this...', $thinkingDeltas[0]->delta);

        // Verify text events
        $textDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TEXT_DELTA));
        $this->assertCount(1, $textDeltas);
        $this->assertEquals('The answer is 42.', $textDeltas[0]->delta);
    }

    public function testReplayTextResponse(): void {
        $responseData = [
            'candidates' => [
                ['content' => ['parts' => [['text' => 'Hello'], ['text' => ' world']]], 'finishReason' => 'STOP'],
            ],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ];

        $events = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        });

        GeminiStreamAccumulator::replay($responseData, $listener);

        $this->assertEquals(StreamEventType::MESSAGE_START, $events[0]->type);
        $this->assertEquals(StreamEventType::MESSAGE_COMPLETE, $events[array_key_last($events)]->type);

        $textDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TEXT_DELTA));
        $this->assertCount(2, $textDeltas);
        $this->assertEquals('Hello', $textDeltas[0]->delta);
        $this->assertEquals(0, $textDeltas[0]->blockIndex);
        $this->assertEquals(' world', $textDeltas[1]->delta);
        $this->assertEquals(1, $textDeltas[1]->blockIndex);
    }

    public function testReplayToolCallResponse(): void {
        $responseData = [
            'candidates' => [
                ['content' => ['parts' => [
                    ['text' => 'Let me search.'],
                    ['functionCall' => ['name' => 'search', 'args' => ['query' => 'test']]],
                ]], 'finishReason' => 'FUNCTION_CALL'],
            ],
            'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 8],
        ];

        $events = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        });

        GeminiStreamAccumulator::replay($responseData, $listener);

        $toolStarts = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_USE_START));
        $this->assertCount(1, $toolStarts);
        $this->assertEquals('search', $toolStarts[0]->toolName);
        $this->assertEquals(1, $toolStarts[0]->blockIndex);

        $toolDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_INPUT_DELTA));
        $this->assertCount(1, $toolDeltas);
        $this->assertEquals('{"query":"test"}', $toolDeltas[0]->delta);

        $blockStops = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::CONTENT_BLOCK_STOP));
        $this->assertCount(1, $blockStops);
        $this->assertEquals(1, $blockStops[0]->blockIndex);
    }

    public function testReplayThinkingResponse(): void {
        $responseData = [
            'candidates' => [
                ['content' => ['parts' => [
                    ['thought' => 'Let me think about this...'],
                    ['text' => 'The answer is 42.'],
                ]], 'finishReason' => 'STOP'],
            ],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 8],
        ];

        $events = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
            $events[] = $event;
        });

        GeminiStreamAccumulator::replay($responseData, $listener);

        $thinkingDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::THINKING_DELTA));
        $this->assertCount(1, $thinkingDeltas);
        $this->assertEquals('Let me think about this...', $thinkingDeltas[0]->delta);
        $this->assertEquals(0, $thinkingDeltas[0]->blockIndex);

        $textDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TEXT_DELTA));
        $this->assertCount(1, $textDeltas);
        $this->assertEquals('The answer is 42.', $textDeltas[0]->delta);
        $this->assertEquals(1, $textDeltas[0]->blockIndex);
    }

    private function buildSse(array $chunks): string {
        $sse = '';
        foreach ($chunks as $chunk) {
            $sse .= 'data: ' . json_encode($chunk, JSON_THROW_ON_ERROR) . "\n\n";
        }

        return $sse;
    }
}
