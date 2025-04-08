<?php

namespace Soukicz\Llm\Client\Gemini;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Soukicz\Llm\Cache\CacheInterface;
use Soukicz\Llm\Client\LLMClient;
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\Http\HttpClientFactory;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;

class GeminiClient extends GeminiEncoder implements LLMClient {
    public const CODE = 'gemini';

    public const MODEL_GEMINI_2_5_PRO_PREVIEW_03_25 = 'gemini-2.5-pro-preview-03-25';

    public const MODEL_GEMINI_2_0_FLASH = 'gemini-2.0-flash';

    public const MODEL_GEMINI_2_0_FLASH_LITE = 'gemini-2.0-flash-lite';

    private ?Client $httpClient = null;

    private ?Client $cachedHttpClient = null;

    private string $apiEndpoint = 'https://generativelanguage.googleapis.com/v1beta';

    // For testing purposes
    public static ?Client $testHttpClient = null;

    public function __construct(private readonly string $apiKey, private readonly ?CacheInterface $cache = null, private $customHttpMiddleware = null) {
    }

    private function getHeaders(): array {
        return [
            'Content-Type' => 'application/json',
        ];
    }

    private function getHttpClient(): Client {
        if (self::$testHttpClient !== null) {
            return self::$testHttpClient;
        }

        if (!$this->httpClient) {
            $this->httpClient = HttpClientFactory::createClient($this->customHttpMiddleware, null, $this->getHeaders());
        }

        return $this->httpClient;
    }

    private function getCachedHttpClient(): Client {
        if (self::$testHttpClient !== null) {
            return self::$testHttpClient;
        }

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
        return $this->sendCachedRequestAsync($this->getGenerateContentRequest($request))->then(function (ModelResponse $modelResponse) use ($request) {
            $encodedResponseOrRequest = $this->decodeResponse($request, $modelResponse);
            if ($encodedResponseOrRequest instanceof LLMResponse) {
                return $encodedResponseOrRequest;
            }

            return $this->sendRequestAsync($encodedResponseOrRequest);
        });
    }

    private function getGenerateContentRequest(LLMRequest $request): RequestInterface {
        $url = "{$this->apiEndpoint}/models/{$request->getModel()}:generateContent?key={$this->apiKey}";

        return new Request('POST', $url, [
            'Content-Type' => 'application/json',
            'accept-encoding' => 'gzip',
        ], json_encode($this->encodeRequest($request), JSON_THROW_ON_ERROR));
    }

    public function getCode(): string {
        return self::CODE;
    }
}
