<?php

namespace Soukicz\PhpLlm\Http;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RetryMiddleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Soukicz\PhpLlm\Cache\CacheInterface;

class HttpClientFactory {
    private const MAX_RETRIES = 3;

    public static function createClient(?callable $customMiddleware = null, ?CacheInterface $cache = null, array $headers = []): Client {
        $options = [
            'headers' => array_merge($headers, [
                'Accept-encoding' => 'gzip',
            ]),
        ];

        $handler = HandlerStack::create();
        if ($customMiddleware) {
            $handler->push($customMiddleware);
        }

        self::addCacheMiddleware($handler, $cache);

        self::addRetryMiddleware($handler);

        $options['handler'] = $handler;

        return new Client($options);
    }

    private static function addCacheMiddleware(HandlerStack $handler, ?CacheInterface $cache): void {
        if ($cache) {
            $handler->push(function (callable $handler) use ($cache): callable {
                return static function (RequestInterface $request, array $options) use (&$handler, $cache) {
                    $response = $cache->fetch($request);

                    if ($response) {
                        return Create::promiseFor($response);
                    }

                    $requestStart = microtime(true);
                    /** @var PromiseInterface $promise */
                    $promise = $handler($request, $options);

                    return $promise->then(
                        function (ResponseInterface $response) use ($request, $cache, $requestStart) {
                            $response = $response->withHeader('X-Request-Duration-ms', (string) round((microtime(true) - $requestStart) * 1000));
                            $cache->store($request, $response);

                            return $response;
                        }
                    );
                };
            });
        }
    }

    private static function addRetryMiddleware(HandlerStack $handler): void {
        $decider = static function (int $retries, RequestInterface $request, ?ResponseInterface $response = null): bool {
            return
                $retries < self::MAX_RETRIES
                && null !== $response
                && 429 === $response->getStatusCode();
        };

        $delay = static function (int $retries, ResponseInterface $response): int {
            if (!$response->hasHeader('Retry-After')) {
                return RetryMiddleware::exponentialDelay($retries);
            }

            $retryAfter = $response->getHeaderLine('Retry-After');

            if (!is_numeric($retryAfter)) {
                $retryAfter = (new \DateTime($retryAfter))->getTimestamp() - time();
            }

            return (int) $retryAfter * 1000;
        };


        $handler->push(Middleware::retry($decider, $delay));
    }
}
