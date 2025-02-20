<?php declare(strict_types=1);

namespace Soukicz\Llm\Cache;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class FileCache extends AbstractCache {
    public function __construct(private readonly string $cacheDir) {
        if (!is_dir($this->cacheDir)) {
            throw new \RuntimeException('Cache directory does not exist: ' . $this->cacheDir);
        }
    }

    private function getPath(string $key): string {
        return $this->cacheDir . '/' . md5($key) . '.json';
    }

    public function fetch(RequestInterface $request): ?ResponseInterface {
        $path = $this->getPath($this->getCacheKey($request));
        if (!file_exists($path)) {
            return null;
        }

        return $this->responseFromJson(file_get_contents($path));
    }

    public function store(RequestInterface $request, ResponseInterface $response): void {
        $key = $this->getCacheKey($request);
        file_put_contents($this->getPath($key), $this->responseToJson($response));
    }

    public function invalidate(RequestInterface $request): void {
        @unlink($this->getPath($this->getCacheKey($request)));
    }

}
