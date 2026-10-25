<?php

namespace Soukicz\Llm\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Soukicz\Llm\Cache\CacheInterface;

class HttpClientFactory {
    private const MAX_RETRIES = 3;
    /** Guzzle 8 only; a string so the class may be missing on Guzzle 7 */
    private const NETWORK_TIMEOUT_EXCEPTION = 'GuzzleHttp\\Exception\\NetworkTimeoutException';

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
                            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                                $cache->store($request, $response);
                            }

                            return $response;
                        }
                    );
                };
            });
        }
    }

    private static function addRetryMiddleware(HandlerStack $handler): void {
        $decider = static function (int $retries, RequestInterface $request, ?ResponseInterface $response = null, ?\Throwable $exception = null): bool {
            if ($retries >= self::MAX_RETRIES) {
                return false;
            }
            // Retry only failures without a response that cannot have been processed or that Guzzle 7
            // already retried: a failed connection (DNS, TCP, TLS - nothing was sent) and a timeout before
            // the response. Guzzle 8 reports that timeout as NetworkTimeoutException, which is not a
            // ConnectException. Send/receive errors after the request went out stay unretried, as on Guzzle 7:
            // the provider may already have run (and billed) the request
            if ($exception instanceof ConnectException || is_a($exception, self::NETWORK_TIMEOUT_EXCEPTION)) {
                return true;
            }

            return
                null !== $response
                && in_array($response->getStatusCode(), [429, 529, 500, 502, 503, 504], true);
        };

        $delay = static function (int $retries, ?ResponseInterface $response = null): int {
            if ($response === null || !$response->hasHeader('Retry-After')) {
                // 1 s, 2 s, 4 s; RetryMiddleware::exponentialDelay() is deprecated in Guzzle 7.11 and removed in 8
                return (int) 2 ** ($retries - 1) * 1000;
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
