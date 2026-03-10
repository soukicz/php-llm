<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Gemini;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Gemini\GeminiClient;
use Soukicz\Llm\Client\Gemini\Model\Gemini20Flash;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Stream\CallableStreamListener;
use Soukicz\Llm\Stream\StreamEvent;
use Soukicz\Llm\Stream\StreamEventType;
use Soukicz\Llm\Tests\Cache\InMemoryCache;
use Soukicz\Llm\Tool\CallbackToolDefinition;
use GuzzleHttp\Promise\Create;
use Soukicz\Llm\Message\LLMMessageContents;

class GeminiStreamingTest extends TestCase {
    private array $requestHistory = [];

    protected function setUp(): void {
        GeminiClient::$testHttpClient = null;
    }

    protected function tearDown(): void {
        GeminiClient::$testHttpClient = null;
    }

    private function createClientWithMockHandler(MockHandler $mockHandler, ?InMemoryCache $cache = null): GeminiClient {
        $this->requestHistory = [];
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($this->requestHistory));
        $httpClient = new Client(['handler' => $handlerStack]);

        GeminiClient::$testHttpClient = $httpClient;

        return new GeminiClient('fake-api-key', $cache);
    }

    private function buildSseBody(array $chunks): string {
        $sse = '';
        foreach ($chunks as $chunk) {
            $sse .= 'data: ' . json_encode($chunk, JSON_THROW_ON_ERROR) . "\n\n";
        }

        return $sse;
    }

    public function testStreamingTextResponse(): void {
        $sseBody = $this->buildSseBody([
            ['candidates' => [['content' => ['parts' => [['text' => 'Hello']]]]]],
            ['candidates' => [['content' => ['parts' => [['text' => ' world!']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]],
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $events = [];
        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hello')]),
            streamListener: new CallableStreamListener(function (StreamEvent $event) use (&$events) {
                $events[] = $event;
            }),
        );

        $response = $client->sendRequestAsync($request)->wait();

        // Gemini produces separate text parts per chunk, getLastText() returns the last one
        $this->assertEquals(' world!', $response->getLastText());
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
    }

    public function testStreamingToolUseResponse(): void {
        $sseBody = $this->buildSseBody([
            ['candidates' => [['content' => ['parts' => [['text' => 'Let me search.']]]]]],
            ['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'search', 'args' => ['query' => 'test']]]]], 'finishReason' => 'FUNCTION_CALL']], 'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 8]],
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $events = [];
        $request = new LLMRequest(
            model: new Gemini20Flash(),
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

        // Verify tool use events
        $toolStarts = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_USE_START));
        $this->assertCount(1, $toolStarts);
        $this->assertEquals('search', $toolStarts[0]->toolName);

        $toolDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TOOL_INPUT_DELTA));
        $this->assertCount(1, $toolDeltas);
        $this->assertEquals('{"query":"test"}', $toolDeltas[0]->delta);

        // Verify text was also streamed
        $textDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TEXT_DELTA));
        $this->assertCount(1, $textDeltas);
        $this->assertEquals('Let me search.', $textDeltas[0]->delta);
    }

    public function testStreamingThinkingResponse(): void {
        $sseBody = $this->buildSseBody([
            ['candidates' => [['content' => ['parts' => [['thought' => 'Let me think about this...']]]]]],
            ['candidates' => [['content' => ['parts' => [['text' => 'The answer is 42.']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 8]],
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $events = [];
        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: new LLMConversation([LLMMessage::createFromUserString('What is the answer?')]),
            streamListener: new CallableStreamListener(function (StreamEvent $event) use (&$events) {
                $events[] = $event;
            }),
        );

        $response = $client->sendRequestAsync($request)->wait();

        $this->assertEquals('The answer is 42.', $response->getLastText());

        // Verify thinking events
        $thinkingDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::THINKING_DELTA));
        $this->assertCount(1, $thinkingDeltas);
        $this->assertEquals('Let me think about this...', $thinkingDeltas[0]->delta);

        // Verify text events
        $textDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TEXT_DELTA));
        $this->assertCount(1, $textDeltas);
        $this->assertEquals('The answer is 42.', $textDeltas[0]->delta);
    }

    public function testStreamingResponseMatchesNonStreaming(): void {
        // Non-streaming response
        $jsonBody = json_encode([
            'candidates' => [
                [
                    'content' => ['parts' => [['text' => 'Hello world!']]],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json', 'X-Request-Duration-ms' => '100'], $jsonBody),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $model = new Gemini20Flash();
        $conversation = new LLMConversation([LLMMessage::createFromUserString('Hello')]);

        $nonStreamingRequest = new LLMRequest(model: $model, conversation: $conversation);
        $nonStreamingResponse = $client->sendRequestAsync($nonStreamingRequest)->wait();

        // Streaming response with equivalent content
        $sseBody = $this->buildSseBody([
            ['candidates' => [['content' => ['parts' => [['text' => 'Hello world!']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]],
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

    public function testStreamingPopulatesCache(): void {
        $cache = new InMemoryCache();

        $sseBody = $this->buildSseBody([
            ['candidates' => [['content' => ['parts' => [['text' => 'Hello world!']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]],
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler, $cache);

        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hello')]),
            streamListener: new CallableStreamListener(function (StreamEvent $event) {}),
        );

        $response = $client->sendRequestAsync($request)->wait();
        $this->assertEquals('Hello world!', $response->getLastText());
        $this->assertEquals(1, $cache->count());
    }

    public function testStreamingReadsFromCache(): void {
        $cache = new InMemoryCache();

        $sseBody = $this->buildSseBody([
            ['candidates' => [['content' => ['parts' => [['text' => 'Hello world!']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]],
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler, $cache);
        $model = new Gemini20Flash();
        $conversation = new LLMConversation([LLMMessage::createFromUserString('Hello')]);

        $request1 = new LLMRequest(
            model: $model,
            conversation: $conversation,
            streamListener: new CallableStreamListener(function (StreamEvent $event) {}),
        );
        $client->sendRequestAsync($request1)->wait();

        // Second call: cache hit
        $mockHandler2 = new MockHandler([]);
        $client2 = $this->createClientWithMockHandler($mockHandler2, $cache);

        $events = [];
        $request2 = new LLMRequest(
            model: $model,
            conversation: $conversation,
            streamListener: new CallableStreamListener(function (StreamEvent $event) use (&$events) {
                $events[] = $event;
            }),
        );
        $response = $client2->sendRequestAsync($request2)->wait();

        $this->assertEquals('Hello world!', $response->getLastText());
        $this->assertEquals(StopReason::FINISHED, $response->getStopReason());

        $this->assertEquals(StreamEventType::MESSAGE_START, $events[0]->type);
        $textDeltas = array_values(array_filter($events, fn(StreamEvent $e) => $e->type === StreamEventType::TEXT_DELTA));
        $this->assertCount(1, $textDeltas);
        $this->assertEquals('Hello world!', $textDeltas[0]->delta);
        $this->assertEquals(StreamEventType::MESSAGE_COMPLETE, $events[array_key_last($events)]->type);
    }

    public function testStreamingRequestUsesCorrectEndpoint(): void {
        $sseBody = $this->buildSseBody([
            ['candidates' => [['content' => ['parts' => [['text' => 'Hi']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 1]],
        ]);

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], $sseBody),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hi')]),
            streamListener: new CallableStreamListener(function (StreamEvent $event) {}),
        );

        $client->sendRequestAsync($request)->wait();

        // Verify the streaming endpoint is used
        $httpRequest = $this->requestHistory[0]['request'];
        $uri = (string) $httpRequest->getUri();
        $this->assertStringContainsString(':streamGenerateContent', $uri);
        $this->assertStringContainsString('alt=sse', $uri);
        $this->assertEquals('identity', $httpRequest->getHeaderLine('accept-encoding'));
    }
}
