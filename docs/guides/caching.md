# Caching

Reduce costs and latency with intelligent HTTP-level response caching. PHP LLM caches LLM responses automatically, making repeated requests nearly instant and free.

## Overview

All PHP LLM clients support caching at the HTTP request level. When enabled:
- Identical requests return cached responses instantly
- No API calls are made for cached requests
- Original response time is preserved in metadata
- Costs are eliminated for cached responses

## File Cache

The built-in `FileCache` stores responses on the filesystem:

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

Implement the `CacheInterface` for custom caching:

```php
<?php
use Soukicz\Llm\Cache\CacheInterface;

class RedisCache implements CacheInterface {
    public function __construct(
        private Redis $redis,
        private int $ttl = 3600
    ) {}

    public function get(string $key): ?string {
        $value = $this->redis->get($key);
        return $value !== false ? $value : null;
    }

    public function set(string $key, string $value): void {
        $this->redis->setex($key, $this->ttl, $value);
    }

    public function has(string $key): bool {
        return $this->redis->exists($key) > 0;
    }

    public function delete(string $key): void {
        $this->redis->del($key);
    }
}
```

```php
<?php
$cache = new RedisCache($redisClient);
$client = new AnthropicClient('sk-xxxxx', $cache);
```

## Cache Keys

Cache keys are generated from:
- API endpoint
- Model name and version
- Request parameters (temperature, maxTokens, etc.)
- Conversation messages
- Tool definitions

**Important:** Always use exact model versions to prevent stale cached responses.

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
    $response = $chainClient->run(
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
❌ Stream responses (partial)
❌ Async requests in progress

## Monitoring Cache Performance

Track cache hit rates:

```php
<?php
class CacheMonitor implements CacheInterface {
    private int $hits = 0;
    private int $misses = 0;

    public function __construct(
        private CacheInterface $cache
    ) {}

    public function get(string $key): ?string {
        $value = $this->cache->get($key);
        if ($value !== null) {
            $this->hits++;
        } else {
            $this->misses++;
        }
        return $value;
    }

    public function set(string $key, string $value): void {
        $this->cache->set($key, $value);
    }

    public function getHitRate(): float {
        $total = $this->hits + $this->misses;
        return $total > 0 ? $this->hits / $total : 0;
    }

    // Implement other interface methods...
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

```php
<?php
// Clear specific cache entry
$cache->delete($cacheKey);

// Clear all cache (FileCache example)
array_map('unlink', glob('/tmp/llm-cache/*'));
```

### Automatic Expiration

Implement TTL in custom cache:

```php
<?php
class TTLFileCache implements CacheInterface {
    private int $ttl;

    public function __construct(string $directory, int $ttlSeconds = 3600) {
        $this->directory = $directory;
        $this->ttl = $ttlSeconds;
    }

    public function get(string $key): ?string {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        // Check if expired
        if (time() - filemtime($file) > $this->ttl) {
            unlink($file);
            return null;
        }

        return file_get_contents($file);
    }

    // Implement other methods...
}
```

## Cost Savings

Example cost calculation:

```php
<?php
$request = new LLMRequest(/*...*/);

// First request - hits API ($0.015)
$response1 = $chainClient->run($client, $request);
echo "Cost: $" . $response1->getTokenUsage()->getTotalCost() . "\n";

// Cached request - no cost ($0.00)
$response2 = $chainClient->run($client, $request);
echo "Cost: $" . $response2->getTokenUsage()->getTotalCost() . "\n";

// 100% savings on repeated requests!
```

## See Also

- [Configuration Guide](configuration.md) - Client configuration
- [Examples](../examples/index.md) - Cache usage examples
