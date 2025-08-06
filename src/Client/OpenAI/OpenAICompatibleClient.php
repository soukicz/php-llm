<?php

namespace Soukicz\Llm\Client\OpenAI;

use Soukicz\Llm\Cache\CacheInterface;

class OpenAICompatibleClient extends AbstractOpenAIClient {
    public const CODE = 'openai-compatible';

    public function __construct(readonly private string $apiKey, readonly private string $baseUrl, ?CacheInterface $cache = null, $customHttpMiddleware = null) {
        parent::__construct($cache, $customHttpMiddleware);
    }

    public function getCode(): string {
        return self::CODE;
    }

    protected function getHeaders(): array {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];
    }

    protected function getBaseUrl(): string {
        return $this->baseUrl;
    }
}
