<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\OpenAI;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\OpenAI\AbstractOpenAIClient;
use Soukicz\Llm\Client\OpenAI\Model\GPT4oMini;
use Soukicz\Llm\Client\OpenAI\OpenAIClient;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

class OpenAIBatchTest extends TestCase {
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $sentRequests = [];

    private function createClientWithResponses(array $responses): OpenAIClient {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $this->sentRequests = [];
        $stack->push(Middleware::history($this->sentRequests));

        $client = new OpenAIClient('test-api-key', null);

        $reflection = new \ReflectionProperty(AbstractOpenAIClient::class, 'httpClient');
        $reflection->setValue($client, new Client(['handler' => $stack]));

        return $client;
    }

    private function createRequest(string $prompt): LLMRequest {
        return new LLMRequest(
            model: new GPT4oMini(GPT4oMini::VERSION_2024_07_18),
            conversation: new LLMConversation([LLMMessage::createFromUserString($prompt)]),
        );
    }

    public function testCreateBatchUploadsJsonlAndCreatesBatch(): void {
        $client = $this->createClientWithResponses([
            new Response(200, [], json_encode(['id' => 'file-abc'], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['id' => 'batch-xyz'], JSON_THROW_ON_ERROR)),
        ]);

        $batchId = $client->createBatch([
            'first' => $this->createRequest('Hello'),
            'second' => $this->createRequest('World'),
        ]);

        $this->assertSame('batch-xyz', $batchId);
        $this->assertCount(2, $this->sentRequests);

        // First request uploads the JSONL file
        $fileUpload = $this->sentRequests[0]['request'];
        $this->assertStringEndsWith('/files', (string) $fileUpload->getUri());
        $uploadBody = (string) $fileUpload->getBody();
        $this->assertStringContainsString('"custom_id":"first"', $uploadBody);
        $this->assertStringContainsString('"custom_id":"second"', $uploadBody);
        $this->assertStringContainsString('"url":"\/v1\/chat\/completions"', $uploadBody);

        // Second request creates the batch from the uploaded file
        $batchCreate = $this->sentRequests[1]['request'];
        $this->assertStringEndsWith('/batches', (string) $batchCreate->getUri());
        $batchPayload = json_decode((string) $batchCreate->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('file-abc', $batchPayload['input_file_id']);
        $this->assertSame('24h', $batchPayload['completion_window']);
    }

    public function testRetrieveBatchReturnsNullWhileNotCompleted(): void {
        $client = $this->createClientWithResponses([
            new Response(200, [], json_encode(['status' => 'in_progress'], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertNull($client->retrieveBatch('batch-xyz'));
    }

    /**
     * Regression test: content used to be doubled ($content .= $content) instead of accumulated
     */
    public function testRetrieveBatchReturnsContentKeyedByCustomId(): void {
        $statusResponse = json_encode([
            'status' => 'completed',
            'output_file_id' => 'file-out',
            'error_file_id' => null,
            'completed_at' => time(),
        ], JSON_THROW_ON_ERROR);

        $resultsJsonl = implode("\n", [
            json_encode([
                'custom_id' => 'first',
                'response' => ['body' => ['choices' => [
                    ['message' => ['content' => 'Hello world']],
                ]]],
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'custom_id' => 'second',
                'response' => ['body' => ['choices' => [
                    // Content may also arrive as a list of typed parts
                    ['message' => ['content' => [
                        ['type' => 'text', 'text' => 'Second '],
                        ['type' => 'text', 'text' => 'answer'],
                    ]]],
                ]]],
            ], JSON_THROW_ON_ERROR),
        ]);

        $client = $this->createClientWithResponses([
            new Response(200, [], $statusResponse),
            new Response(200, [], $resultsJsonl),
        ]);

        $results = $client->retrieveBatch('batch-xyz');

        $this->assertSame([
            'first' => 'Hello world',
            'second' => 'Second answer',
        ], $results);
    }

    public function testRetrieveBatchThrowsOnRecentFailure(): void {
        $client = $this->createClientWithResponses([
            new Response(200, [], json_encode([
                'status' => 'completed',
                'output_file_id' => null,
                'error_file_id' => 'file-err',
                'completed_at' => time(),
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], '{"error": "something went wrong"}'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Batch failed');

        $client->retrieveBatch('batch-xyz');
    }

    /**
     * Documents current behavior: failures older than three days are swallowed and
     * reported as an empty result set (OpenAI error files expire)
     */
    public function testRetrieveBatchReturnsEmptyArrayForExpiredFailure(): void {
        $client = $this->createClientWithResponses([
            new Response(200, [], json_encode([
                'status' => 'completed',
                'output_file_id' => null,
                'error_file_id' => 'file-err',
                'completed_at' => time() - 4 * 24 * 60 * 60,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertSame([], $client->retrieveBatch('batch-xyz'));
    }
}
