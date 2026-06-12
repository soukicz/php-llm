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
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Haiku;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

class AnthropicBatchTest extends TestCase {
    /** @var array<int, \Psr\Http\Message\RequestInterface> */
    private array $sentRequests = [];

    private function createClientWithResponses(array $responses): AnthropicClient {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $this->sentRequests = [];
        $history = Middleware::history($this->sentRequests);
        $stack->push($history);

        $client = new AnthropicClient('test-api-key');

        // Inject the mocked HTTP client into the lazily initialized private property
        $reflection = new \ReflectionProperty(AnthropicClient::class, 'httpClient');
        $reflection->setValue($client, new Client(['handler' => $stack]));

        return $client;
    }

    private function createRequest(string $prompt): LLMRequest {
        return new LLMRequest(
            model: new AnthropicClaude45Haiku(AnthropicClaude45Haiku::VERSION_20251001),
            conversation: new LLMConversation([LLMMessage::createFromUserString($prompt)]),
        );
    }

    public function testCreateBatchEncodesRequestsWithCustomIds(): void {
        $client = $this->createClientWithResponses([
            new Response(200, [], json_encode(['id' => 'msgbatch_123'], JSON_THROW_ON_ERROR)),
        ]);

        $batchId = $client->createBatch([
            'first' => $this->createRequest('Hello'),
            'second' => $this->createRequest('World'),
        ]);

        $this->assertSame('msgbatch_123', $batchId);

        $this->assertCount(1, $this->sentRequests);
        $sent = $this->sentRequests[0]['request'];
        $this->assertSame('POST', $sent->getMethod());
        $this->assertSame('https://api.anthropic.com/v1/messages/batches', (string) $sent->getUri());
        $this->assertSame('test-api-key', $sent->getHeaderLine('x-api-key'));

        $payload = json_decode((string) $sent->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $payload['requests']);
        $this->assertSame('first', $payload['requests'][0]['custom_id']);
        $this->assertSame('second', $payload['requests'][1]['custom_id']);
        $this->assertSame('Hello', $payload['requests'][0]['params']['messages'][0]['content'][0]['text']);
        $this->assertSame('claude-haiku-4-5-20251001', $payload['requests'][0]['params']['model']);
    }

    public function testRetrieveBatchReturnsNullWhileInProgress(): void {
        $client = $this->createClientWithResponses([
            new Response(200, [], json_encode(['processing_status' => 'in_progress'], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertNull($client->retrieveBatch('msgbatch_123'));
    }

    public function testRetrieveBatchReturnsContentKeyedByCustomId(): void {
        $statusResponse = json_encode([
            'processing_status' => 'ended',
            'results_url' => 'https://api.anthropic.com/v1/messages/batches/msgbatch_123/results',
        ], JSON_THROW_ON_ERROR);

        // JSONL results: multiple text blocks must be concatenated, non-text blocks skipped
        $resultsJsonl = implode("\n", [
            json_encode([
                'custom_id' => 'first',
                'result' => ['message' => ['content' => [
                    ['type' => 'text', 'text' => 'Hello '],
                    ['type' => 'text', 'text' => 'world'],
                ]]],
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'custom_id' => 'second',
                'result' => ['message' => ['content' => [
                    ['type' => 'thinking', 'thinking' => 'hmm', 'signature' => 'sig'],
                    ['type' => 'text', 'text' => 'Second answer'],
                ]]],
            ], JSON_THROW_ON_ERROR),
        ]);

        $client = $this->createClientWithResponses([
            new Response(200, [], $statusResponse),
            new Response(200, [], $resultsJsonl),
        ]);

        $results = $client->retrieveBatch('msgbatch_123');

        $this->assertSame([
            'first' => 'Hello world',
            'second' => 'Second answer',
        ], $results);
    }

    public function testRetrieveBatchThrowsOnUnexpectedStatus(): void {
        $client = $this->createClientWithResponses([
            new Response(200, [], json_encode(['processing_status' => 'canceling', 'status' => 'canceling'], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected batch status');

        $client->retrieveBatch('msgbatch_123');
    }
}
