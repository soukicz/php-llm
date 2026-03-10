<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude35Haiku;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Stream\CallableStreamListener;
use Soukicz\Llm\Stream\StreamEvent;
use Soukicz\Llm\Stream\StreamEventType;
use Soukicz\Llm\Tool\CallbackToolDefinition;
use GuzzleHttp\Promise\Create;
use Soukicz\Llm\Message\LLMMessageContents;

class AnthropicStreamingTest extends TestCase {
    private function createClientWithMockHandler(MockHandler $mockHandler): AnthropicClient {
        $handlerStack = HandlerStack::create($mockHandler);

        $customMiddleware = function (callable $handler) use ($handlerStack) {
            return function ($request, array $options) use ($handlerStack) {
                return $handlerStack($request, $options);
            };
        };

        return new AnthropicClient('fake-api-key', null, $customMiddleware);
    }

    private function buildSseBody(array $events): string {
        $sse = '';
        foreach ($events as $event) {
            $sse .= "event: {$event[0]}\n";
            $sse .= 'data: ' . json_encode($event[1], JSON_THROW_ON_ERROR) . "\n\n";
        }

        return $sse;
    }

    public function testStreamingTextResponse(): void {
        $sseBody = $this->buildSseBody([
            ['message_start', ['message' => ['usage' => ['input_tokens' => 25, 'output_tokens' => 0]]]],
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => ' world!']]],
            ['content_block_stop', ['index' => 0]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 12]]],
            ['message_stop', []],
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $events = [];
        $request = new LLMRequest(
            model: new AnthropicClaude35Haiku(AnthropicClaude35Haiku::VERSION_20241022),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hello')]),
            streamListener: new CallableStreamListener(function (StreamEvent $event) use (&$events) {
                $events[] = $event;
            }),
        );

        $response = $client->sendRequestAsync($request)->wait();

        // Verify response content
        $this->assertEquals('Hello world!', $response->getLastText());
        $this->assertEquals(StopReason::FINISHED, $response->getStopReason());
        $this->assertEquals(25, $response->getInputTokens());
        $this->assertEquals(12, $response->getOutputTokens());

        // Verify stream events
        $this->assertEquals(StreamEventType::MESSAGE_START, $events[0]->type);
        $textDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TEXT_DELTA));
        $this->assertCount(2, $textDeltas);
        $this->assertEquals('Hello', $textDeltas[0]->delta);
        $this->assertEquals(' world!', $textDeltas[1]->delta);
        $this->assertEquals(StreamEventType::MESSAGE_COMPLETE, $events[array_key_last($events)]->type);
    }

    public function testStreamingToolUseResponse(): void {
        $sseBody = $this->buildSseBody([
            ['message_start', ['message' => ['usage' => ['input_tokens' => 50, 'output_tokens' => 0]]]],
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Let me search.']]],
            ['content_block_stop', ['index' => 0]],
            ['content_block_start', ['index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'search']]],
            ['content_block_delta', ['index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"que']]],
            ['content_block_delta', ['index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'ry":"test"}']]],
            ['content_block_stop', ['index' => 1]],
            ['message_delta', ['delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 30]]],
            ['message_stop', []],
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $events = [];
        $searchTool = new CallbackToolDefinition(
            'search',
            'Search tool',
            ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
            fn(array $input) => Create::promiseFor(LLMMessageContents::fromArrayData(['results' => []]))
        );

        $request = new LLMRequest(
            model: new AnthropicClaude35Haiku(AnthropicClaude35Haiku::VERSION_20241022),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Search for test')]),
            tools: [$searchTool],
            streamListener: new CallableStreamListener(function (StreamEvent $event) use (&$events) {
                $events[] = $event;
            }),
        );

        $response = $client->sendRequestAsync($request)->wait();

        // Verify tool use stop reason (decodeResponse returns an LLMRequest for tool loop, not LLMResponse directly)
        // But since the client's sendRequestAsync will recurse into tool execution,
        // we need to check the events for the tool use
        $toolStarts = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_USE_START));
        $this->assertCount(1, $toolStarts);
        $this->assertEquals('search', $toolStarts[0]->toolName);
        $this->assertEquals('toolu_01', $toolStarts[0]->toolId);

        $toolDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_INPUT_DELTA));
        $this->assertCount(2, $toolDeltas);
        $this->assertEquals('{"que', $toolDeltas[0]->delta);
        $this->assertEquals('ry":"test"}', $toolDeltas[1]->delta);

        $textDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TEXT_DELTA));
        $this->assertCount(1, $textDeltas);
        $this->assertEquals('Let me search.', $textDeltas[0]->delta);
    }

    public function testStreamingThinkingResponse(): void {
        $sseBody = $this->buildSseBody([
            ['message_start', ['message' => ['usage' => ['input_tokens' => 15, 'output_tokens' => 0]]]],
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Let me think...']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig123']]],
            ['content_block_stop', ['index' => 0]],
            ['content_block_start', ['index' => 1, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'The answer is 42.']]],
            ['content_block_stop', ['index' => 1]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 20]]],
            ['message_stop', []],
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $events = [];
        $request = new LLMRequest(
            model: new AnthropicClaude35Haiku(AnthropicClaude35Haiku::VERSION_20241022),
            conversation: new LLMConversation([LLMMessage::createFromUserString('What is the meaning of life?')]),
            streamListener: new CallableStreamListener(function (StreamEvent $event) use (&$events) {
                $events[] = $event;
            }),
        );

        $response = $client->sendRequestAsync($request)->wait();

        // Verify response text (thinking is internal, text is the visible output)
        $this->assertEquals('The answer is 42.', $response->getLastText());
        $this->assertEquals(StopReason::FINISHED, $response->getStopReason());

        // Verify thinking events
        $thinkingDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::THINKING_DELTA));
        $this->assertCount(1, $thinkingDeltas);
        $this->assertEquals('Let me think...', $thinkingDeltas[0]->delta);

        // Verify text events
        $textDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TEXT_DELTA));
        $this->assertCount(1, $textDeltas);
        $this->assertEquals('The answer is 42.', $textDeltas[0]->delta);
    }

    public function testStreamingResponseMatchesNonStreaming(): void {
        // Non-streaming response
        $jsonBody = json_encode([
            'content' => [
                ['type' => 'text', 'text' => 'Hello world!'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 25, 'output_tokens' => 12],
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json', 'X-Request-Duration-ms' => '100'], $jsonBody),
        ]);

        $nonStreamingClient = $this->createClientWithMockHandler($mockHandler);

        $conversation = new LLMConversation([LLMMessage::createFromUserString('Hello')]);
        $model = new AnthropicClaude35Haiku(AnthropicClaude35Haiku::VERSION_20241022);

        $nonStreamingRequest = new LLMRequest(model: $model, conversation: $conversation);
        $nonStreamingResponse = $nonStreamingClient->sendRequestAsync($nonStreamingRequest)->wait();

        // Streaming response with equivalent content
        $sseBody = $this->buildSseBody([
            ['message_start', ['message' => ['usage' => ['input_tokens' => 25, 'output_tokens' => 0]]]],
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello world!']]],
            ['content_block_stop', ['index' => 0]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 12]]],
            ['message_stop', []],
        ]);

        $mockHandler2 = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $streamingClient = $this->createClientWithMockHandler($mockHandler2);

        $streamingRequest = new LLMRequest(
            model: $model,
            conversation: $conversation,
            streamListener: new CallableStreamListener(function (StreamEvent $event) {}),
        );
        $streamingResponse = $streamingClient->sendRequestAsync($streamingRequest)->wait();

        // Compare responses
        $this->assertEquals($nonStreamingResponse->getLastText(), $streamingResponse->getLastText());
        $this->assertEquals($nonStreamingResponse->getStopReason(), $streamingResponse->getStopReason());
        $this->assertEquals($nonStreamingResponse->getInputTokens(), $streamingResponse->getInputTokens());
        $this->assertEquals($nonStreamingResponse->getOutputTokens(), $streamingResponse->getOutputTokens());
    }

    public function testStreamingWithCacheTokens(): void {
        $sseBody = $this->buildSseBody([
            ['message_start', ['message' => ['usage' => ['input_tokens' => 25, 'output_tokens' => 0, 'cache_creation_input_tokens' => 100, 'cache_read_input_tokens' => 50]]]],
            ['content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Cached response']]],
            ['content_block_stop', ['index' => 0]],
            ['message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]]],
            ['message_stop', []],
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $request = new LLMRequest(
            model: new AnthropicClaude35Haiku(AnthropicClaude35Haiku::VERSION_20241022),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hello')]),
            streamListener: new CallableStreamListener(function (StreamEvent $event) {}),
        );

        $response = $client->sendRequestAsync($request)->wait();

        $this->assertEquals('Cached response', $response->getLastText());
        $this->assertEquals(StopReason::FINISHED, $response->getStopReason());
    }
}
