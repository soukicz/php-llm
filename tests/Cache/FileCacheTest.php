<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Cache;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Cache\FileCache;

class FileCacheTest extends TestCase {
    private string $cacheDir;
    private FileCache $cache;

    protected function setUp(): void {
        $this->cacheDir = sys_get_temp_dir() . '/llm-file-cache-test-' . uniqid();
        mkdir($this->cacheDir);
        $this->cache = new FileCache($this->cacheDir);
    }

    protected function tearDown(): void {
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->cacheDir);
    }

    private function createRequest(string $body = '{"prompt":"hello"}'): Request {
        return new Request('POST', 'https://api.example.com/v1/messages', [], $body);
    }

    public function testConstructorRejectsMissingDirectory(): void {
        $this->expectException(\RuntimeException::class);

        new FileCache($this->cacheDir . '/does-not-exist');
    }

    public function testFetchReturnsNullOnMiss(): void {
        $this->assertNull($this->cache->fetch($this->createRequest()));
    }

    public function testStoreFetchRoundTripPreservesResponse(): void {
        $request = $this->createRequest();
        $response = new Response(200, ['Content-Type' => 'application/json', 'X-Custom' => 'abc'], '{"answer":42}');

        $this->cache->store($request, $response);
        $cached = $this->cache->fetch($request);

        $this->assertNotNull($cached);
        $this->assertSame(200, $cached->getStatusCode());
        $this->assertSame('{"answer":42}', (string) $cached->getBody());
        $this->assertSame('application/json', $cached->getHeaderLine('Content-Type'));
        $this->assertSame('abc', $cached->getHeaderLine('X-Custom'));
    }

    public function testDifferentRequestBodiesGetDifferentEntries(): void {
        $requestA = $this->createRequest('{"prompt":"a"}');
        $requestB = $this->createRequest('{"prompt":"b"}');

        $this->cache->store($requestA, new Response(200, [], 'response A'));
        $this->cache->store($requestB, new Response(200, [], 'response B'));

        $this->assertSame('response A', (string) $this->cache->fetch($requestA)->getBody());
        $this->assertSame('response B', (string) $this->cache->fetch($requestB)->getBody());
    }

    public function testInvalidateRemovesEntry(): void {
        $request = $this->createRequest();
        $this->cache->store($request, new Response(200, [], 'data'));
        $this->assertNotNull($this->cache->fetch($request));

        $this->cache->invalidate($request);

        $this->assertNull($this->cache->fetch($request));
    }

    public function testInvalidateOnMissingEntryIsSilent(): void {
        $this->cache->invalidate($this->createRequest());

        $this->assertNull($this->cache->fetch($this->createRequest()));
    }

    public function testCorruptedCacheFileIsTreatedAsMiss(): void {
        $request = $this->createRequest();
        $this->cache->store($request, new Response(200, [], 'data'));

        // Corrupt the single stored file
        $files = glob($this->cacheDir . '/*.json');
        $this->assertCount(1, $files);
        file_put_contents($files[0], 'this is not json {');

        $this->assertNull($this->cache->fetch($request));
    }
}
