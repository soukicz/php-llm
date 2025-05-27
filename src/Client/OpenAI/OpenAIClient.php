<?php

namespace Soukicz\Llm\Client\OpenAI;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Soukicz\Llm\Cache\CacheInterface;
use Soukicz\Llm\Client\LLMBatchClient;
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\Http\HttpClientFactory;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;

class OpenAIClient extends OpenAIEncoder implements LLMBatchClient {
    public const CODE = 'openai';

    private ?Client $httpClient = null;

    private ?Client $cachedHttpClient = null;

    public function __construct(private readonly string $apiKey, private readonly string $apiOrganization, private readonly ?CacheInterface $cache = null, private $customHttpMiddleware = null) {
    }

    private function getHeaders(): array {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'OpenAI-Organization' => $this->apiOrganization,
        ];
    }

    private function getHttpClient(): Client {
        if (!$this->httpClient) {
            $this->httpClient = HttpClientFactory::createClient($this->customHttpMiddleware, null, $this->getHeaders());
        }

        return $this->httpClient;
    }

    private function getCachedHttpClient(): Client {
        if (!$this->cache) {
            return $this->getHttpClient();
        }
        if (!$this->cachedHttpClient) {
            $this->cachedHttpClient = HttpClientFactory::createClient($this->customHttpMiddleware, $this->cache, $this->getHeaders());
        }

        return $this->cachedHttpClient;
    }

    private function sendCachedRequestAsync(RequestInterface $httpRequest): PromiseInterface {
        return $this->getCachedHttpClient()->sendAsync($httpRequest)->then(function (ResponseInterface $response) {
            return new ModelResponse(json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR), (int) $response->getHeaderLine('X-Request-Duration-ms'));
        });
    }

    public function sendRequestAsync(LLMRequest $request): PromiseInterface {
        return $this->sendCachedRequestAsync($this->getChatRequest($request))->then(function (ModelResponse $modelResponse) use ($request) {
            $encodedResponseOrRequest = $this->decodeResponse($request, $modelResponse);
            if ($encodedResponseOrRequest instanceof LLMResponse) {
                return $encodedResponseOrRequest;
            }

            return $this->sendRequestAsync($encodedResponseOrRequest);
        });
    }

    private function getChatRequest(LLMRequest $request): RequestInterface {
        return new Request('POST', 'https://api.openai.com/v1/chat/completions', [
            'Content-Type' => 'application/json',
            'accept-encoding' => 'gzip',
            'Authorization' => 'Bearer ' . $this->apiKey,
            'OpenAI-Organization' => $this->apiOrganization,
        ], json_encode($this->encodeRequest($request), JSON_THROW_ON_ERROR));
    }

    public function getBatchEmbeddings(array $texts, string $model = 'text-embedding-3-small', int $dimensions = 512): array {
        $results = [];
        $totalTokens = 0;
        foreach (array_chunk($texts, 100, true) as $chunk) {
            $keys = array_keys($chunk);
            $response = json_decode($this->getHttpClient()->post('https://api.openai.com/v1/embeddings', [
                'json' => [
                    'model' => $model,
                    'dimensions' => $dimensions,
                    'input' => array_values($chunk),
                ],
            ])->getBody(), true, 512, JSON_THROW_ON_ERROR);
            foreach ($response['data'] as $embedding) {
                $results[$keys[$embedding['index']]] = $embedding['embedding'];
            }
            $totalTokens += $response['usage']['total_tokens'];
        }

        return $results;
    }

    /**
     * @param LLMRequest[] $requests
     * @return string batch id
     */
    public function createBatch(array $requests): string {
        $body = '';
        $endpoint = '/v1/chat/completions';
        foreach ($requests as $customId => $request) {
            $body .= json_encode([
                    'custom_id' => $customId,
                    'method' => 'POST',
                    'url' => $endpoint,
                    'body' => $this->encodeRequest($request),
                ], JSON_THROW_ON_ERROR) . "\n";
        }

        $fileResponse = $this->getHttpClient()->post('https://api.openai.com/v1/files', [
            'multipart' => [
                ['name' => 'purpose', 'contents' => 'batch'],
                ['name' => 'file', 'contents' => $body, 'filename' => 'batch.jsonl'],
            ],
        ]);

        $file = json_decode((string) $fileResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $batchResult = $this->getHttpClient()->post('https://api.openai.com/v1/batches', [
            'json' => [
                'completion_window' => '24h',
                'endpoint' => $endpoint,
                'input_file_id' => $file['id'],
            ],
        ]);

        return json_decode((string) $batchResult->getBody(), true, 512, JSON_THROW_ON_ERROR)['id'];
    }

    public function retrieveBatch(string $batchId): ?array {
        $response = json_decode($this->getHttpClient()->get('https://api.openai.com/v1/batches/' . $batchId)->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if ($response['status'] !== 'completed') {
            return null;
        }

        if ($response['output_file_id'] === null && $response['error_file_id']) {
            if ($response['completed_at'] < time() - 3 * 24 * 60 * 60) {
                return [];
            }
            $file = (string) $this->getHttpClient()->get('https://api.openai.com/v1/files/' . $response['error_file_id'] . '/content')->getBody();

            throw new \RuntimeException('Batch failed: ' . substr($file, 0, 1000));
        }

        $file = (string) $this->getHttpClient()->get('https://api.openai.com/v1/files/' . $response['output_file_id'] . '/content')->getBody();
        $results = explode("\n", trim($file));
        $responses = [];
        foreach ($results as $row) {
            $result = json_decode($row, true, 512, JSON_THROW_ON_ERROR);
            $content = '';
            foreach ($result['response']['body']['choices'] as $contentPart) {
                $content = $contentPart['message']['content'];
                if (is_string($content)) {
                    $content .= $content;
                } elseif ($content['type'] === 'text') {
                    $content .= $content['text'];
                }
            }
            $responses[$result['custom_id']] = $content;
        }

        return $responses;
    }

    public function getCode(): string {
        return self::CODE;
    }
}
