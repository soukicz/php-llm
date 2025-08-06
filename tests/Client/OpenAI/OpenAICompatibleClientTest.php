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
use Soukicz\Llm\Client\Universal\LocalModel;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

class OpenAICompatibleClientTest extends TestCase {
    private MockHandler $mockHandler;
    private array $requestHistory = [];

    protected function setUp(): void {
        $this->mockHandler = new MockHandler();
        $this->requestHistory = [];
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
                'usage' => [
                    'prompt_tokens' => 5,
                    'completion_tokens' => 4,
                    'total_tokens' => 9,
                ],
            ]))
        );

        // Create a custom middleware that uses our mock handler
        $handlerStack = HandlerStack::create($this->mockHandler);
        $handlerStack->push(Middleware::history($this->requestHistory));
        
        $customMiddleware = function (callable $handler) use ($handlerStack) {
            return function ($request, array $options) use ($handlerStack) {
                return $handlerStack($request, $options);
            };
        };

        $client = new OpenAICompatibleClient(
            apiKey: 'test-api-key',
            baseUrl: 'https://custom.api.com/v1',
            cache: null,
            customHttpMiddleware: $customMiddleware
        );
        $conversation = new LLMConversation([LLMMessage::createFromUserString('Hello')]);
        $request = new LLMRequest(
            model: new LocalModel('custom-model'),
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
