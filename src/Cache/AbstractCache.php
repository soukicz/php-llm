<?php declare(strict_types=1);

namespace Soukicz\PhpLlm\Cache;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

abstract class AbstractCache implements CacheInterface {
    protected function getCacheKey(RequestInterface $request): string {
        return hash('sha512', json_encode([
            'url' => (string) $request->getUri(),
            'method' => $request->getMethod(),
            'body' => (string) $request->getBody(),
        ], JSON_THROW_ON_ERROR));
    }

    protected function responseFromJson(string $jsonString): ResponseInterface {
        $data = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);

        return new Response($data['status'], $data['headers'], $data['body']);
    }

    protected function responseToJson(ResponseInterface $response): string {
        $body = $response->getBody()->getContents();
        $response->getBody()->rewind();

        return json_encode([
            'body' => $body,
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
        ], JSON_THROW_ON_ERROR);
    }
}
