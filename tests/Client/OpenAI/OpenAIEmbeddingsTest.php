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
use Soukicz\Llm\Client\OpenAI\OpenAIClient;

class OpenAIEmbeddingsTest extends TestCase {
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

    /**
     * Build an embeddings API response for the given input count. Embeddings are returned
     * deliberately out of order to verify the client maps them back via the index field.
     */
    private function embeddingsResponse(int $count, int $startValue): Response {
        $data = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $data[] = [
                'index' => $i,
                'embedding' => [(float) ($startValue + $i)],
            ];
        }

        return new Response(200, [], json_encode([
            'data' => $data,
            'usage' => ['total_tokens' => $count],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Regression test for parallel batching: results must come back keyed and ordered
     * by the original input position even with multiple chunks and out-of-order
     * embeddings within each response
     */
    public function testResultsPreserveInputOrderAcrossChunks(): void {
        // 250 inputs → 3 chunks (100 + 100 + 50)
        $texts = [];
        for ($i = 0; $i < 250; $i++) {
            $texts[] = 'text ' . $i;
        }

        $client = $this->createClientWithResponses([
            $this->embeddingsResponse(100, 0),
            $this->embeddingsResponse(100, 100),
            $this->embeddingsResponse(50, 200),
        ]);

        $results = $client->getBatchEmbeddings($texts);

        $this->assertCount(250, $results);
        $this->assertSame(range(0, 249), array_keys($results));
        // Each embedding value encodes its global input position
        foreach ($results as $position => $embedding) {
            $this->assertEquals([$position], $embedding, "Embedding at position $position is misaligned");
        }
    }

    public function testRequestPayloadAndChunking(): void {
        $texts = array_fill(0, 150, 'hello');

        $client = $this->createClientWithResponses([
            $this->embeddingsResponse(100, 0),
            $this->embeddingsResponse(50, 100),
        ]);

        $client->getBatchEmbeddings($texts, 'text-embedding-3-large', 1024);

        $this->assertCount(2, $this->sentRequests);

        $firstPayload = json_decode((string) $this->sentRequests[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('text-embedding-3-large', $firstPayload['model']);
        $this->assertSame(1024, $firstPayload['dimensions']);
        $this->assertCount(100, $firstPayload['input']);

        $secondPayload = json_decode((string) $this->sentRequests[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(50, $secondPayload['input']);
    }
}
