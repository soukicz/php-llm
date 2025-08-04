<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\OpenAI;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\OpenAI\Model\GPT41;
use Soukicz\Llm\Client\OpenAI\OpenAICompatibleClient;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

class OpenAICompatibleClientTest extends TestCase {
    private MockHandler $mockHandler;
    private array $requestHistory = [];

    protected function setUp(): void {
        $this->mockHandler = new MockHandler();
        $this->requestHistory = [];

        $handlerStack = HandlerStack::create($this->mockHandler);
        $handlerStack->push(Middleware::history($this->requestHistory));

        $httpClient = new Client(['handler' => $handlerStack]);

        // Monkey patch the OpenAICompatibleClient to use our mock HTTP client
        OpenAICompatibleClient::$testHttpClient = $httpClient;
    }

    public function testSendRequestAsyncUsesCustomBaseUrlAndModel(): void {
        $this->mockHandler->append(
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Hello from custom API!',
                            'role' => 'assistant',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ]))
        );

        $client = new OpenAICompatibleClient(
            baseUrl: 'https://custom.api.com/v1',
            model: 'custom-model',
            apiKey: 'test-api-key'
        );
        $conversation = new LLMConversation([LLMMessage::createFromUserString('Hello')]);
        $request = new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation
        );
        $response = $client->sendRequestAsync($request)->wait();

        // Verify the response
        $this->assertEquals('Hello from custom API!', $response->getLastText());
        $this->assertCount(1, $this->requestHistory);
        $httpRequest = $this->requestHistory[0]['request'];
        $this->assertEquals('https://custom.api.com/v1/chat/completions', (string) $httpRequest->getUri());

        // Verify the model was injected into the request body
        $body = json_decode((string) $httpRequest->getBody(), true);
        $this->assertArrayHasKey('model', $body);
        $this->assertEquals('custom-model', $body['model']);
    }
}
