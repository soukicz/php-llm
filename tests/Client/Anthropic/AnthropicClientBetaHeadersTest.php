<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Opus;
use Soukicz\Llm\Config\ThinkingDisplay;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

class AnthropicClientBetaHeadersTest extends TestCase {
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function client(array $clientBetas, Response $response): AnthropicClient {
        $stack = HandlerStack::create(new MockHandler([$response]));
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        $client = new AnthropicClient('test-key', null, null, $clientBetas);
        (new \ReflectionProperty(AnthropicClient::class, 'httpClient'))->setValue($client, new Client(['handler' => $stack]));

        return $client;
    }

    private static function messageResponse(): Response {
        return new Response(200, [], json_encode([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], JSON_THROW_ON_ERROR));
    }

    private static function request(?ThinkingDisplay $display): LLMRequest {
        return new LLMRequest(
            model: new AnthropicClaude55Opus(),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hi')]),
            thinkingDisplay: $display,
        );
    }

    public function testRequestBetasAreMergedWithClientBetas(): void {
        $client = $this->client(['files-api-2025-04-14'], self::messageResponse());

        $client->sendRequestAsync(self::request(ThinkingDisplay::UPDATES))->wait();

        $this->assertSame('files-api-2025-04-14,thinking-display-updates-2026-08-18', $this->history[0]['request']->getHeaderLine('anthropic-beta'));
    }

    public function testNoBetaHeaderWithoutBetas(): void {
        $client = $this->client([], self::messageResponse());

        $client->sendRequestAsync(self::request(null))->wait();

        $this->assertFalse($this->history[0]['request']->hasHeader('anthropic-beta'));
    }

    public function testBatchSendsTheBetasOfItsRequests(): void {
        $client = $this->client([], new Response(200, [], json_encode(['id' => 'msgbatch_1'], JSON_THROW_ON_ERROR)));

        $client->createBatch(['a' => self::request(null), 'b' => self::request(ThinkingDisplay::UPDATES)]);

        $this->assertSame('thinking-display-updates-2026-08-18', $this->history[0]['request']->getHeaderLine('anthropic-beta'));
    }
}
