<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\OpenAI;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\OpenAI\OpenAICompatibleClient;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\Client\Universal\LocalModel;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Stream\CallableStreamListener;
use Soukicz\Llm\Stream\StreamEvent;
use Soukicz\Llm\Stream\StreamEventType;
use Soukicz\Llm\Tool\CallbackToolDefinition;
use GuzzleHttp\Promise\Create;
use Soukicz\Llm\Message\LLMMessageContents;

class OpenAIStreamingTest extends TestCase {
    private array $requestHistory = [];

    private function createClientWithMockHandler(MockHandler $mockHandler): OpenAICompatibleClient {
        $this->requestHistory = [];
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($this->requestHistory));

        $customMiddleware = function (callable $handler) use ($handlerStack) {
            return function ($request, array $options) use ($handlerStack) {
                return $handlerStack($request, $options);
            };
        };

        return new OpenAICompatibleClient(
            apiKey: 'fake-api-key',
            baseUrl: 'https://api.openai.com/v1',
            cache: null,
            customHttpMiddleware: $customMiddleware,
        );
    }

    private function buildSseBody(array $chunks): string {
        $sse = '';
        foreach ($chunks as $chunk) {
            if ($chunk === '[DONE]') {
                $sse .= "data: [DONE]\n\n";
            } else {
                $sse .= 'data: ' . json_encode($chunk, JSON_THROW_ON_ERROR) . "\n\n";
            }
        }

        return $sse;
    }

    public function testStreamingTextResponse(): void {
        $sseBody = $this->buildSseBody([
            ['choices' => [['delta' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['content' => ' world!'], 'finish_reason' => null]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'stop']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
            '[DONE]',
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $events = [];
        $request = new LLMRequest(
            model: new LocalModel('gpt-4o-mini'),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hello')]),
            streamListener: new CallableStreamListener(function (StreamEvent $event) use (&$events) {
                $events[] = $event;
            }),
        );

        $response = $client->sendRequestAsync($request)->wait();

        // Verify response
        $this->assertEquals('Hello world!', $response->getLastText());
        $this->assertEquals(StopReason::FINISHED, $response->getStopReason());
        $this->assertEquals(10, $response->getInputTokens());
        $this->assertEquals(5, $response->getOutputTokens());

        // Verify stream events
        $this->assertEquals(StreamEventType::MESSAGE_START, $events[0]->type);
        $textDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TEXT_DELTA));
        $this->assertCount(2, $textDeltas);
        $this->assertEquals('Hello', $textDeltas[0]->delta);
        $this->assertEquals(' world!', $textDeltas[1]->delta);
        $this->assertEquals(StreamEventType::MESSAGE_COMPLETE, $events[array_key_last($events)]->type);

        // Verify request body includes stream options
        $requestBody = json_decode((string) $this->requestHistory[0]['request']->getBody(), true);
        $this->assertTrue($requestBody['stream']);
        $this->assertTrue($requestBody['stream_options']['include_usage']);
    }

    public function testStreamingToolUseResponse(): void {
        $sseBody = $this->buildSseBody([
            ['choices' => [['delta' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [['index' => 0, 'id' => 'call_abc', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '']]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"que']]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'ry":"test"}']]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 15]],
            '[DONE]',
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $events = [];
        $request = new LLMRequest(
            model: new LocalModel('gpt-4o-mini'),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Search for test')]),
            tools: [new CallbackToolDefinition(
                'search',
                'Search tool',
                ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
                fn(array $input) => Create::promiseFor(LLMMessageContents::fromArrayData(['results' => []]))
            )],
            streamListener: new CallableStreamListener(function (StreamEvent $event) use (&$events) {
                $events[] = $event;
            }),
        );

        $response = $client->sendRequestAsync($request)->wait();

        // Verify stream events for tool use
        $toolStarts = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_USE_START));
        $this->assertCount(1, $toolStarts);
        $this->assertEquals('search', $toolStarts[0]->toolName);
        $this->assertEquals('call_abc', $toolStarts[0]->toolId);

        $toolDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_INPUT_DELTA));
        $this->assertCount(2, $toolDeltas);
        $this->assertEquals('{"que', $toolDeltas[0]->delta);
        $this->assertEquals('ry":"test"}', $toolDeltas[1]->delta);
    }

    public function testStreamingMultipleToolCalls(): void {
        $sseBody = $this->buildSseBody([
            ['choices' => [['delta' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '']]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"query":"first"}']]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 1, 'id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '']]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 1, 'function' => ['arguments' => '{"city":"NYC"}']]]], 'finish_reason' => null]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 20]],
            '[DONE]',
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $events = [];
        $request = new LLMRequest(
            model: new LocalModel('gpt-4o-mini'),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Search and weather')]),
            tools: [
                new CallbackToolDefinition('search', 'Search', ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']], fn(array $input) => Create::promiseFor(LLMMessageContents::fromArrayData([]))),
                new CallbackToolDefinition('weather', 'Weather', ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']], fn(array $input) => Create::promiseFor(LLMMessageContents::fromArrayData([]))),
            ],
            streamListener: new CallableStreamListener(function (StreamEvent $event) use (&$events) {
                $events[] = $event;
            }),
        );

        $response = $client->sendRequestAsync($request)->wait();

        // Verify both tool starts
        $toolStarts = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_USE_START));
        $this->assertCount(2, $toolStarts);
        $this->assertEquals('search', $toolStarts[0]->toolName);
        $this->assertEquals('weather', $toolStarts[1]->toolName);
    }

    public function testStreamingResponseMatchesNonStreaming(): void {
        // Non-streaming response
        $jsonBody = json_encode([
            'choices' => [
                [
                    'message' => ['content' => 'Hello world!', 'role' => 'assistant'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json', 'X-Request-Duration-ms' => '100'], $jsonBody),
        ]);

        $nonStreamingClient = $this->createClientWithMockHandler($mockHandler);

        $model = new LocalModel('gpt-4o-mini');
        $conversation = new LLMConversation([LLMMessage::createFromUserString('Hello')]);

        $nonStreamingRequest = new LLMRequest(model: $model, conversation: $conversation);
        $nonStreamingResponse = $nonStreamingClient->sendRequestAsync($nonStreamingRequest)->wait();

        // Streaming response with equivalent content
        $sseBody = $this->buildSseBody([
            ['choices' => [['delta' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['content' => 'Hello world!'], 'finish_reason' => null]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'stop']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
            '[DONE]',
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

    public function testStreamingRequestUsesIdentityEncoding(): void {
        $sseBody = $this->buildSseBody([
            ['choices' => [['delta' => ['content' => 'Hi'], 'finish_reason' => null]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'stop']]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1]],
            '[DONE]',
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $request = new LLMRequest(
            model: new LocalModel('gpt-4o-mini'),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hi')]),
            streamListener: new CallableStreamListener(function (StreamEvent $event) {}),
        );

        $client->sendRequestAsync($request)->wait();

        // Verify accept-encoding is identity for streaming
        $httpRequest = $this->requestHistory[0]['request'];
        $this->assertEquals('identity', $httpRequest->getHeaderLine('accept-encoding'));
    }
}
