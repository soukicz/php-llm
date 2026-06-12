<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Cache\CacheInterface;
use Soukicz\Llm\Http\HttpClientFactory;
use Soukicz\Llm\Tests\Cache\InMemoryCache;

class HttpClientFactoryTest extends TestCase {
    private MockHandler $mockHandler;

    /**
     * Build a client with the full factory middleware stack (custom middleware, cache,
     * retry) but with the network transport replaced by a MockHandler
     */
    private function createClient(?CacheInterface $cache = null, ?callable $customMiddleware = null): Client {
        $this->mockHandler = new MockHandler();
        $client = HttpClientFactory::createClient($customMiddleware, $cache);

        /** @var HandlerStack $stack */
        $stack = $client->getConfig('handler');
        $stack->setHandler($this->mockHandler);

        return $client;
    }

    public function testRetriesRetryableStatusCodesUntilSuccess(): void {
        $client = $this->createClient();
        $this->mockHandler->append(
            new Response(429, ['Retry-After' => '0']),
            new Response(503, ['Retry-After' => '0']),
            new Response(200, [], 'ok'),
        );

        $response = $client->get('https://example.com/api');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string) $response->getBody());
        $this->assertSame(0, $this->mockHandler->count(), 'All queued responses should have been consumed');
    }

    public function testGivesUpAfterMaxRetries(): void {
        $client = $this->createClient();
        // MAX_RETRIES is 3, so the 4th consecutive error is returned to the caller
        $this->mockHandler->append(
            new Response(500, ['Retry-After' => '0']),
            new Response(500, ['Retry-After' => '0']),
            new Response(500, ['Retry-After' => '0']),
            new Response(500, ['Retry-After' => '0']),
        );

        $this->expectException(ServerException::class);

        try {
            $client->get('https://example.com/api');
        } finally {
            $this->assertSame(0, $this->mockHandler->count(), 'Expected exactly 4 attempts (1 + 3 retries)');
        }
    }

    public function testDoesNotRetryNonRetryableClientErrors(): void {
        $client = $this->createClient();
        $this->mockHandler->append(
            new Response(404),
            new Response(200),
        );

        $this->expectException(ClientException::class);

        try {
            $client->get('https://example.com/api');
        } finally {
            $this->assertSame(1, $this->mockHandler->count(), 'A 404 must not be retried');
        }
    }

    public function testHonorsNumericRetryAfterHeader(): void {
        $client = $this->createClient();
        $this->mockHandler->append(
            new Response(429, ['Retry-After' => '1']),
            new Response(200),
        );

        $start = microtime(true);
        $response = $client->get('https://example.com/api');
        $elapsed = microtime(true) - $start;

        $this->assertSame(200, $response->getStatusCode());
        $this->assertGreaterThan(0.9, $elapsed, 'Retry should have waited for the Retry-After interval');
    }

    public function testHonorsHttpDateRetryAfterHeader(): void {
        $client = $this->createClient();
        $this->mockHandler->append(
            new Response(429, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 1)]),
            new Response(200),
        );

        $response = $client->get('https://example.com/api');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->mockHandler->count());
    }

    /**
     * Regression test: network-level failures (connection reset, DNS, timeout) used to
     * propagate immediately without any retry
     */
    public function testRetriesConnectExceptions(): void {
        $client = $this->createClient();
        $request = new Request('GET', 'https://example.com/api');
        $this->mockHandler->append(
            new ConnectException('Connection refused', $request),
            new Response(200, [], 'ok'),
        );

        $response = $client->get('https://example.com/api');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string) $response->getBody());
    }

    public function testSuccessfulResponsesAreCachedAndReplayed(): void {
        $cache = new InMemoryCache();
        $client = $this->createClient($cache);
        $this->mockHandler->append(new Response(200, [], 'fresh'));

        $first = $client->get('https://example.com/api');
        $this->assertSame('fresh', (string) $first->getBody());
        $this->assertSame(1, $cache->count());

        // Second identical request must be served from cache - the mock queue is empty,
        // so hitting the transport again would throw
        $second = $client->get('https://example.com/api');
        $this->assertSame('fresh', (string) $second->getBody());
        $this->assertSame(0, $this->mockHandler->count());
    }

    public function testErrorResponsesAreNotCached(): void {
        $cache = new InMemoryCache();
        $client = $this->createClient($cache);
        $this->mockHandler->append(new Response(404));

        try {
            $client->get('https://example.com/api');
            $this->fail('Expected ClientException');
        } catch (ClientException) {
        }

        $this->assertSame(0, $cache->count(), 'Non-2xx responses must not be cached');
    }

    public function testRequestDurationHeaderIsAddedWhenCacheIsActive(): void {
        $cache = new InMemoryCache();
        $client = $this->createClient($cache);
        $this->mockHandler->append(new Response(200, [], 'ok'));

        $response = $client->get('https://example.com/api');

        $this->assertTrue($response->hasHeader('X-Request-Duration-ms'));
        $this->assertIsNumeric($response->getHeaderLine('X-Request-Duration-ms'));
    }

    public function testCustomMiddlewareSeesRequestsAndResponses(): void {
        $seen = [];
        $middleware = function (callable $handler) use (&$seen): callable {
            return function ($request, array $options) use ($handler, &$seen) {
                $seen[] = $request->getMethod() . ' ' . $request->getUri();

                return $handler($request, $options);
            };
        };

        $client = $this->createClient(null, $middleware);
        $this->mockHandler->append(new Response(200));

        $client->get('https://example.com/api');

        $this->assertSame(['GET https://example.com/api'], $seen);
    }
}
