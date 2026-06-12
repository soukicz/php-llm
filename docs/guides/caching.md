# Caching

Reduce costs and latency with intelligent HTTP-level response caching. PHP LLM caches LLM responses automatically, making repeated requests nearly instant and free.

## Overview

All PHP LLM clients support caching at the HTTP request level. When enabled:
- Identical requests return cached responses instantly
- No API calls are made for cached requests
- Original response time is preserved in metadata
- Costs are eliminated for cached responses
- Streaming and non-streaming requests share the same cache entries

## File Cache

The built-in `FileCache` stores responses on the filesystem. The directory must already exist — the constructor throws a `RuntimeException` otherwise:

```php
<?php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;

$cache = new FileCache(sys_get_temp_dir());
$client = new AnthropicClient('sk-xxxxx', $cache);
```

**Characteristics:**
- ✅ Simple to set up
- ✅ No additional dependencies
- ✅ Works across requests
- ⚠️ Limited to single server
- ⚠️ Manual cleanup required

### Custom Cache Directory

```php
<?php
$cache = new FileCache('/var/cache/llm');
$client = new AnthropicClient('sk-xxxxx', $cache);
```

## DynamoDB Cache

For distributed systems, use the DynamoDB cache extension:

```bash
composer require soukicz/llm-cache-dynamodb
```

```php
<?php
use Soukicz\Llm\Cache\DynamoDB\DynamoDBCache;
use Aws\DynamoDb\DynamoDbClient;

$dynamodb = new DynamoDbClient([
    'region' => 'us-east-1',
    'version' => 'latest',
]);

$cache = new DynamoDBCache($dynamodb, 'llm-cache-table');
$client = new AnthropicClient('sk-xxxxx', $cache);
```

**Characteristics:**
- ✅ Distributed across servers
- ✅ Automatic TTL expiration
- ✅ Scalable
- ❌ Requires AWS setup
- ❌ Additional costs

## Custom Cache Implementation

The cache operates on PSR-7 HTTP messages. The `CacheInterface` has three methods:

```php
<?php
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface CacheInterface {
    public function fetch(RequestInterface $request): ?ResponseInterface;

    public function store(RequestInterface $request, ResponseInterface $response): void;

    public function invalidate(RequestInterface $request): void;
}
```

For custom backends, extend `AbstractCache` — it provides `getCacheKey(RequestInterface): string` (a SHA-512 hash of URL, method and body) plus `responseToJson()`/`responseFromJson()` helpers for serializing PSR-7 responses:

```php
<?php
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Soukicz\Llm\Cache\AbstractCache;

class RedisCache extends AbstractCache {
    public function __construct(
        private readonly Redis $redis,
        private readonly int $ttl = 3600
    ) {}

    public function fetch(RequestInterface $request): ?ResponseInterface {
        $json = $this->redis->get($this->getCacheKey($request));

        return $json !== false ? $this->responseFromJson($json) : null;
    }

    public function store(RequestInterface $request, ResponseInterface $response): void {
        $this->redis->setex($this->getCacheKey($request), $this->ttl, $this->responseToJson($response));
    }

    public function invalidate(RequestInterface $request): void {
        $this->redis->del($this->getCacheKey($request));
    }
}
```

```php
<?php
$cache = new RedisCache($redisClient);
$client = new AnthropicClient('sk-xxxxx', $cache);
```

## Cache Keys

Cache keys are a SHA-512 hash of the HTTP request:
- Request URL (API endpoint, including the model for Gemini)
- HTTP method
- Request body (model, temperature, maxTokens, conversation messages, tool definitions, ...)

Any change to the request body produces a new cache key.

**Important:** Always use exact model versions to prevent stale cached responses.

**Security caveat:** The cache key does **not** include request headers, so API keys are not part of the key. Identical requests share cache entries regardless of which credentials were used. The cache is intended for development, testing and request deduplication — do not rely on it for multi-tenant isolation.

## Best Practices

### Use Exact Model Versions

❌ **Bad - Generic naming**
```php
<?php
// Vague version could cache responses from old models
$model = new AnthropicClaude45Sonnet('latest');
```

✅ **Good - Explicit version**
```php
<?php
// Specific version ensures cache correctness
$model = new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929);
```

### Development vs Production

**Development:**
```php
<?php
// Aggressive caching to save costs during development
$cache = new FileCache('/tmp/llm-cache');
$client = new AnthropicClient('sk-xxxxx', $cache);
```

**Production:**
```php
<?php
// Distributed cache for multi-server setup
$cache = new DynamoDBCache($dynamodb, 'prod-llm-cache');
$client = new AnthropicClient('sk-xxxxx', $cache);
```

### Cache Warming

Pre-cache common requests:

```php
<?php
// Warm cache with common queries
$commonQueries = [
    'What is PHP?',
    'How do I install composer?',
    'What are PHP traits?',
];

foreach ($commonQueries as $query) {
    $response = $agentClient->run(
        client: $client,
        request: new LLMRequest(
            model: $model,
            conversation: new LLMConversation([
                LLMMessage::createFromUserString($query)
            ])
        )
    );
}
```

## Disabling Cache

To bypass cache for specific requests, create a client without cache:

```php
<?php
// No cache
$client = new AnthropicClient('sk-xxxxx', null);
```

## Cache Behavior

### What Gets Cached

✅ Successful responses
✅ Complete conversations
✅ Tool call results
✅ Multimodal requests

### What Doesn't Get Cached

❌ Failed requests (errors)
❌ Incomplete responses
❌ Async requests in progress

## Monitoring Cache Performance

Track cache hit rates by decorating another cache:

```php
<?php
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Soukicz\Llm\Cache\CacheInterface;

class CacheMonitor implements CacheInterface {
    private int $hits = 0;
    private int $misses = 0;

    public function __construct(
        private readonly CacheInterface $cache
    ) {}

    public function fetch(RequestInterface $request): ?ResponseInterface {
        $response = $this->cache->fetch($request);
        if ($response !== null) {
            $this->hits++;
        } else {
            $this->misses++;
        }

        return $response;
    }

    public function store(RequestInterface $request, ResponseInterface $response): void {
        $this->cache->store($request, $response);
    }

    public function invalidate(RequestInterface $request): void {
        $this->cache->invalidate($request);
    }

    public function getHitRate(): float {
        $total = $this->hits + $this->misses;
        return $total > 0 ? $this->hits / $total : 0;
    }
}
```

```php
<?php
$cache = new CacheMonitor(new FileCache('/tmp/cache'));
$client = new AnthropicClient('sk-xxxxx', $cache);

// After some requests...
echo "Cache hit rate: " . ($cache->getHitRate() * 100) . "%\n";
```

## Cache Expiration

### Manual Cleanup

`invalidate()` removes the entry for a specific PSR-7 HTTP request. Since you usually don't have the underlying HTTP request at hand, the simplest cleanup for `FileCache` is to delete the cache files:

```php
<?php
// Clear all cache (FileCache stores one .json file per entry)
array_map('unlink', glob('/tmp/llm-cache/*.json'));
```

### Automatic Expiration

Implement TTL in a custom cache by extending `AbstractCache`:

```php
<?php
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Soukicz\Llm\Cache\AbstractCache;

class TTLFileCache extends AbstractCache {
    public function __construct(
        private readonly string $directory,
        private readonly int $ttl = 3600
    ) {}

    private function getPath(RequestInterface $request): string {
        return $this->directory . '/' . md5($this->getCacheKey($request)) . '.json';
    }

    public function fetch(RequestInterface $request): ?ResponseInterface {
        $file = $this->getPath($request);

        if (!file_exists($file)) {
            return null;
        }

        // Check if expired
        if (time() - filemtime($file) > $this->ttl) {
            unlink($file);

            return null;
        }

        return $this->responseFromJson(file_get_contents($file));
    }

    public function store(RequestInterface $request, ResponseInterface $response): void {
        file_put_contents($this->getPath($request), $this->responseToJson($response), LOCK_EX);
    }

    public function invalidate(RequestInterface $request): void {
        @unlink($this->getPath($request));
    }
}
```

## Cost Savings

Example cost calculation:

```php
<?php
$request = new LLMRequest(/*...*/);

// First request - hits the API
$response1 = $agentClient->run($client, $request);
echo "Cost: $" . ($response1->getInputPriceUsd() + $response1->getOutputPriceUsd()) . "\n";

// Identical request - served from the cache, no API call is made
$response2 = $agentClient->run($client, $request);

// 100% savings on repeated requests!
```

Note that the reported price is calculated from the token counts in the response, so a cached response still reports the original cost — but no API call is made and nothing is billed.

## See Also

- [Configuration Guide](configuration.md) - Client configuration
- [Examples](../examples/index.md) - Cache usage examples
