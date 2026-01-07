<?php

namespace Soukicz\Llm\Client\OpenAI;

use Soukicz\Llm\Cache\CacheInterface;

class OpenAIClient extends AbstractOpenAIClient {
    public const CODE = 'openai';

    public function __construct(private readonly string $apiKey, private readonly ?string $apiOrganization, ?CacheInterface $cache = null, $customHttpMiddleware = null) {
        parent::__construct($cache, $customHttpMiddleware);
    }

    protected function getHeaders(): array {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];
        if ($this->apiOrganization !== null) {
            $headers['OpenAI-Organization'] = $this->apiOrganization;
        }

        return $headers;
    }

    protected function getBaseUrl(): string {
        return 'https://api.openai.com/v1';
    }

    public function getCode(): string {
        return self::CODE;
    }
}
