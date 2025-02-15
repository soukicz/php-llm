<?php declare(strict_types=1);

namespace Soukicz\PhpLlm\Cache;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface CacheInterface {
    public function fetch(RequestInterface $request): ?ResponseInterface;

    public function store(RequestInterface $request, ResponseInterface $response): void;

    public function invalidate(RequestInterface $request): void;
}
