<?php

namespace Soukicz\PhpLlm\Client\Anthropic;

use GuzzleHttp\Client;
use Soukicz\PhpLlm\Cache\CacheInterface;
use Soukicz\PhpLlm\Client\LLMBatchClient;
use Soukicz\PhpLlm\Client\ModelResponse;
use Soukicz\PhpLlm\Http\HttpClientFactory;
use Soukicz\PhpLlm\LLMRequest;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;

class AnthropicClient extends AnthropicBaseClient implements LLMBatchClient {

    public const MODEL_SONNET_35 = 'claude-3-5-sonnet-20241022';
    public const MODEL_HAIKU_35 = 'claude-3-5-haiku-20241022';

    public const CODE = 'anthropic';

    private ?Client $httpClient = null;
    private ?Client $cachedHttpClient = null;

    public function __construct(private readonly string $apiKey, private readonly ?CacheInterface $cache = null, private $customHttpMiddleware = null) {

    }

    private function getHttpClient(): Client {
        if (!$this->httpClient) {
            $this->httpClient = HttpClientFactory::createClient($this->customHttpMiddleware);
        }

        return $this->httpClient;
    }

    private function getCachedHttpClient(): Client {
        if (!$this->cache) {
            return $this->getHttpClient();
        }
        if (!$this->cachedHttpClient) {
            $this->cachedHttpClient = HttpClientFactory::createClient($this->customHttpMiddleware, $this->cache);
        }

        return $this->cachedHttpClient;
    }

    private function getHeaders(?string $betaFeature = null): array {
        $headers = [
            'accept-encoding' => 'gzip',
            'anthropic-version' => '2023-06-01',
            'x-api-key' => $this->apiKey,
        ];
        if ($betaFeature) {
            $headers['anthropic-beta'] = $betaFeature;
        }

        return $headers;
    }

    protected function invokeModel(array $data): PromiseInterface {
        return $this->getCachedHttpClient()->postAsync('https://api.anthropic.com/v1/messages', [
            'headers' => $this->getHeaders(),
            'json' => $data,
        ])->then(function (ResponseInterface $response) {
            return new ModelResponse(json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR), (int) $response->getHeaderLine('X-Request-Duration'));
        });
    }

    /**
     * @param LLMRequest[] $requests
     * @return string batch id
     */
    public function createBatch(array $requests): string {
        $params = [];
        foreach ($requests as $customId => $request) {
            $params[] = [
                'custom_id' => $customId,
                'params' => $this->encodeRequest($request),
            ];
        }

        $response = $this->getHttpClient()->post('https://api.anthropic.com/v1/messages/batches', [
            'headers' => $this->getHeaders(),
            'json' => [
                'requests' => $params,
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR)['id'];
    }

    public function retrieveBatch(string $batchId): ?array {
        $response = json_decode($this->getHttpClient()->get('https://api.anthropic.com/v1/messages/batches/' . $batchId, [
            'headers' => $this->getHeaders(),
        ])->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if ($response['processing_status'] === 'in_progress') {
            return null;
        }
        if ($response['processing_status'] === 'ended') {
            $results = explode("\n", trim($this->getHttpClient()->get($response['results_url'], ['headers' => $this->getHeaders()])->getBody()->getContents()));
            $responses = [];
            foreach ($results as $row) {
                $result = json_decode($row, true, 512, JSON_THROW_ON_ERROR);
                $content = '';
                foreach ($result['result']['message']['content'] as $contentPart) {
                    if (is_string($contentPart)) {
                        $content .= $contentPart;
                    } elseif ($contentPart['type'] === 'text') {
                        $content .= $contentPart['text'];
                    }
                }
                $responses[$result['custom_id']] = $content;
            }

            return $responses;
        }

        throw new \RuntimeException('Unexpected batch status ' . $response['status'] . " - " . json_encode($response, JSON_THROW_ON_ERROR));
    }

    public function getCode(): string {
        return self::CODE;
    }
}
