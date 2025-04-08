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
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;

class GeminiClientTest extends TestCase {
    private MockHandler $mockHandler;

    private array $requestHistory = [];

    protected function setUp(): void {
        $this->mockHandler = new MockHandler();
        $this->requestHistory = [];

        $handlerStack = HandlerStack::create($this->mockHandler);
        $handlerStack->push(Middleware::history($this->requestHistory));

        $httpClient = new Client(['handler' => $handlerStack]);

        // Monkey patch the GeminiClient to use our mock HTTP client
        GeminiClient::$testHttpClient = $httpClient;
    }

    public function testSendRequestAsync(): void {
        // Mock response data
        $responseBody = json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'This is a response from Gemini AI.'],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 8,
            ],
        ]);

        // Queue the mock response
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json', 'X-Request-Duration-ms' => '150'], $responseBody)
        );

        $geminiClient = new GeminiClient('fake-api-key');

        // Create a conversation
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([new LLMMessageText('Tell me a joke')]),
        ]);

        $request = new LLMRequest(
            model: GeminiClient::MODEL_GEMINI_2_0_FLASH,
            conversation: $conversation
        );

        // Send the request
        $responsePromise = $geminiClient->sendRequestAsync($request);
        $response = $responsePromise->wait();

        // Assert that the response was properly decoded
        $this->assertEquals('This is a response from Gemini AI.', $response->getLastText());
        $this->assertEquals(10, $response->getInputTokens());
        $this->assertEquals(8, $response->getOutputTokens());
        $this->assertEquals(150, $response->getTotalTimeMs());
    }

}
