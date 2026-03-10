<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Cache;

use Soukicz\Llm\Cache\AbstractCache;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class InMemoryCache extends AbstractCache {
    /** @var array<string, string> */
    private array $store = [];

    public function fetch(RequestInterface $request): ?ResponseInterface {
        $key = $this->getCacheKey($request);
        if (!isset($this->store[$key])) {
            return null;
        }

        return $this->responseFromJson($this->store[$key]);
    }

    public function store(RequestInterface $request, ResponseInterface $response): void {
        $key = $this->getCacheKey($request);
        $this->store[$key] = $this->responseToJson($response);
    }

    public function invalidate(RequestInterface $request): void {
        $key = $this->getCacheKey($request);
        unset($this->store[$key]);
    }

    public function count(): int {
        return count($this->store);
    }
}
