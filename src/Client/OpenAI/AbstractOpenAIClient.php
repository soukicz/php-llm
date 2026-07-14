<?php

namespace Soukicz\Llm\Client\OpenAI;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Soukicz\Llm\Cache\CacheInterface;
use Soukicz\Llm\Client\LLMBatchClient;
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\Http\HttpClientFactory;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Stream\OpenAIStreamAccumulator;
use Soukicz\Llm\Stream\StreamListenerInterface;

abstract class AbstractOpenAIClient extends OpenAIEncoder implements LLMBatchClient {
    private ?Client $httpClient = null;

    private ?Client $cachedHttpClient = null;

    public function __construct(private readonly ?CacheInterface $cache = null, private $customHttpMiddleware = null)
    {
    }

    abstract protected function getHeaders(): array;

    abstract protected function getBaseUrl(): string;

    abstract public function getCode(): string;

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

    private function sendStreamingRequestAsync(RequestInterface $httpRequest, StreamListenerInterface $streamListener, ?RequestInterface $cacheKeyRequest = null): PromiseInterface {
        // Check cache
        if ($this->cache !== null && $cacheKeyRequest !== null) {
            $cachedResponse = $this->cache->fetch($cacheKeyRequest);
            if ($cachedResponse !== null) {
                $responseData = json_decode((string) $cachedResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
                OpenAIStreamAccumulator::replay($responseData, $streamListener);
                $timeMs = (int) $cachedResponse->getHeaderLine('X-Request-Duration-ms');

                return Create::promiseFor(new ModelResponse($responseData, $timeMs));
            }
        }

        $requestStart = microtime(true);

        return $this->getHttpClient()->sendAsync($httpRequest, [
            'stream' => true,
        ])->then(function (ResponseInterface $response) use ($streamListener, $requestStart, $cacheKeyRequest) {
            $result = OpenAIStreamAccumulator::consume($response->getBody(), $streamListener);
            $timeMs = (int) round((microtime(true) - $requestStart) * 1000);

            // Store in cache
            if ($this->cache !== null && $cacheKeyRequest !== null) {
                $syntheticResponse = new Response(200, ['Content-Type' => 'application/json', 'X-Request-Duration-ms' => (string) $timeMs], json_encode($result, JSON_THROW_ON_ERROR));
                $this->cache->store($cacheKeyRequest, $syntheticResponse);
            }

            return new ModelResponse($result, $timeMs);
        });
    }

    public function sendRequestAsync(LLMRequest $request): PromiseInterface {
        $streamListener = $request->getStreamListener();

        if ($streamListener !== null) {
            $modelPromise = $this->sendStreamingRequestAsync($this->getStreamingChatRequest($request), $streamListener, $this->getChatRequest($request));
        } else {
            $modelPromise = $this->sendCachedRequestAsync($this->getChatRequest($request));
        }

        return $modelPromise->then(function (ModelResponse $modelResponse) use ($request) {
            $encodedResponseOrRequest = $this->decodeResponse($request, $modelResponse);
            if ($encodedResponseOrRequest instanceof LLMResponse) {
                return $encodedResponseOrRequest;
            }

            return $this->sendRequestAsync($encodedResponseOrRequest);
        });
    }

    private function getChatRequest(LLMRequest $request): RequestInterface {
        return new Request('POST', $this->getBaseUrl() . '/chat/completions', array_merge($this->getHeaders(), [
            'Content-Type' => 'application/json',
            'accept-encoding' => 'gzip',
        ]), json_encode($this->encodeRequest($request), JSON_THROW_ON_ERROR));
    }

    private function getStreamingChatRequest(LLMRequest $request): RequestInterface {
        $data = $this->encodeRequest($request);
        $data['stream'] = true;
        $data['stream_options'] = ['include_usage' => true];

        return new Request('POST', $this->getBaseUrl() . '/chat/completions', array_merge($this->getHeaders(), [
            'Content-Type' => 'application/json',
            'accept-encoding' => 'identity',
        ]), json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function getBatchEmbeddings(array $texts, string $model = 'text-embedding-3-small', int $dimensions = 512): array {
        $chunks = array_chunk($texts, 100, true);
        $chunkKeys = [];
        $results = [];
        $totalTokens = 0;

        $requests = function () use ($chunks, &$chunkKeys, $model, $dimensions) {
            foreach ($chunks as $i => $chunk) {
                $chunkKeys[$i] = array_keys($chunk);
                yield $i => new Request('POST', $this->getBaseUrl() . '/embeddings', [
                    'Content-Type' => 'application/json',
                ], json_encode([
                    'model' => $model,
                    'dimensions' => $dimensions,
                    'input' => array_values($chunk),
                ], JSON_THROW_ON_ERROR));
            }
        };

        $pool = new Pool($this->getHttpClient(), $requests(), [
            'concurrency' => 32,
            'fulfilled' => function (ResponseInterface $response, int $i) use (&$results, &$totalTokens, &$chunkKeys) {
                $data = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $keys = $chunkKeys[$i];
                foreach ($data['data'] as $embedding) {
                    $results[$keys[$embedding['index']]] = $embedding['embedding'];
                }
                $totalTokens += $data['usage']['total_tokens'];
            },
            'rejected' => function (\Throwable $reason) {
                throw $reason;
            },
        ]);

        $pool->promise()->wait();
        ksort($results);

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

        $fileResponse = $this->getHttpClient()->post($this->getBaseUrl().'/files', [
            'multipart' => [
                ['name' => 'purpose', 'contents' => 'batch'],
                ['name' => 'file', 'contents' => $body, 'filename' => 'batch.jsonl'],
            ],
        ]);

        $file = json_decode((string) $fileResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $batchResult = $this->getHttpClient()->post($this->getBaseUrl().'/batches', [
            'json' => [
                'completion_window' => '24h',
                'endpoint' => $endpoint,
                'input_file_id' => $file['id'],
            ],
        ]);

        return json_decode((string) $batchResult->getBody(), true, 512, JSON_THROW_ON_ERROR)['id'];
    }

    public function retrieveBatch(string $batchId): ?array {
        $response = json_decode($this->getHttpClient()->get($this->getBaseUrl().'/batches/' . $batchId)->getBody(), true, 512, JSON_THROW_ON_ERROR);

        // Only these statuses can still change - return null so the caller polls
        // again later. Every other status (completed / failed / expired / cancelled)
        // is terminal: the batch will never change again, so from here we must
        // always return an array and never null. A caller that treats null as
        // "not ready, retry later" would otherwise poll a dead batch forever - a
        // batch that misses its 24h completion window ends up 'expired' and can
        // never reach 'completed'.
        if (in_array($response['status'], ['validating', 'in_progress', 'finalizing', 'cancelling'], true)) {
            return null;
        }

        if ($response['output_file_id'] === null) {
            // No output at all. Surface a genuinely-failed *completed* batch loudly
            // for a few days (so a real prompt/config error is noticed), then give
            // up. Batches that failed / expired / cancelled will never produce
            // output, so give up immediately - retrying a dead batch is futile.
            if ($response['error_file_id'] && $response['status'] === 'completed' && $response['completed_at'] > time() - 3 * 24 * 60 * 60) {
                $file = (string) $this->getHttpClient()->get($this->getBaseUrl().'/files/' . $response['error_file_id'] . '/content')->getBody();

                throw new \RuntimeException('Batch failed: ' . substr($file, 0, 1000));
            }

            return [];
        }

        $file = (string) $this->getHttpClient()->get($this->getBaseUrl().'/files/' . $response['output_file_id'] . '/content')->getBody();
        $results = explode("\n", trim($file));
        $responses = [];
        foreach ($results as $row) {
            $result = json_decode($row, true, 512, JSON_THROW_ON_ERROR);
            $content = '';
            foreach ($result['response']['body']['choices'] as $contentPart) {
                $messageContent = $contentPart['message']['content'];
                if (is_string($messageContent)) {
                    $content .= $messageContent;
                } elseif (is_array($messageContent)) {
                    foreach ($messageContent as $part) {
                        if (($part['type'] ?? null) === 'text') {
                            $content .= $part['text'];
                        }
                    }
                }
            }
            $responses[$result['custom_id']] = $content;
        }

        return $responses;
    }
}
