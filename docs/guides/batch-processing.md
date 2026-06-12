# Batch Processing

Process high volumes of LLM requests efficiently using batch operations. Batch processing is ideal for offline workloads where immediate responses aren't required.

## Overview

Batch processing allows you to:
- Submit multiple requests at once
- Process them asynchronously
- Retrieve results later
- Save costs (often 50% cheaper than real-time)
- Handle large-scale operations

**Note:** Batch processing support varies by provider. Both `AnthropicClient` and `OpenAIClient` implement batches; Gemini does not.

## LLMBatchClient Interface

Clients implementing batch operations use the `LLMBatchClient` interface:

```php
<?php
use Soukicz\Llm\Client\LLMBatchClient;

interface LLMBatchClient {
    public function createBatch(array $requests): string;
    public function retrieveBatch(string $batchId): ?array;
    public function getCode(): string;
}
```

- `createBatch()` takes an array of `LLMRequest` objects **keyed by your custom ID** and returns the provider's batch ID.
- `retrieveBatch()` returns `null` while the batch is still in progress. Once finished, it returns an array mapping each custom ID to the response text content.

## Basic Usage

### Submit Batch

```php
<?php
use Soukicz\Llm\Client\OpenAI\OpenAIClient;
use Soukicz\Llm\Client\OpenAI\Model\GPT5;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

/** @var LLMBatchClient $client */
$client = new OpenAIClient('sk-xxxxx', 'org-xxxxx');

// Prepare multiple requests, keyed by a custom ID of your choice
$requests = [];
for ($i = 0; $i < 1000; $i++) {
    $requests["document-$i"] = new LLMRequest(
        model: new GPT5(GPT5::VERSION_2025_08_07),
        conversation: new LLMConversation([
            LLMMessage::createFromUserString("Summarize document $i")
        ])
    );
}

// Submit batch
$batchId = $client->createBatch($requests);
echo "Batch created: $batchId\n";
```

### Retrieve Batch

```php
<?php
// Returns null while the batch is in progress,
// or an array of [custom ID => response text] when finished
$results = $client->retrieveBatch($batchId);

if ($results !== null) {
    foreach ($results as $customId => $text) {
        echo "$customId: $text\n";
    }
}
```

## Complete Example

```php
<?php
use Soukicz\Llm\Client\OpenAI\OpenAIClient;
use Soukicz\Llm\Client\OpenAI\Model\GPT5;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

$client = new OpenAIClient('sk-xxxxx', 'org-xxxxx');

// Prepare batch of classification tasks
$texts = [
    'review-1' => 'This product is amazing!',
    'review-2' => 'Terrible service, would not recommend.',
    'review-3' => 'It\'s okay, nothing special.',
    // ... 1000s more
];

$requests = array_map(
    fn($text) => new LLMRequest(
        model: new GPT5(GPT5::VERSION_2025_08_07),
        conversation: new LLMConversation([
            LLMMessage::createFromUserString("Classify sentiment (positive/negative/neutral): $text")
        ])
    ),
    $texts
); // array_map preserves the string keys, which become custom IDs

// Submit batch
$batchId = $client->createBatch($requests);

// Poll until complete
do {
    sleep(60); // Wait 1 minute
    $results = $client->retrieveBatch($batchId);
} while ($results === null);

// Process batch results: custom ID => response text
foreach ($results as $customId => $text) {
    echo "$customId: $text\n";
}
```

The same code works with `AnthropicClient` — only the client and model classes change.

## Async Polling

Use async operations for efficient polling:

```php
<?php
use React\EventLoop\Loop;

$batchId = $client->createBatch($requests);

// Check every 60 seconds
Loop::addPeriodicTimer(60, function () use ($client, $batchId, &$timer) {
    $batch = $client->retrieveBatch($batchId);

    if ($batch !== null) {
        // Batch is available, process results
        processResults($batch);
        Loop::cancelTimer($timer);
    }
});

Loop::run();
```

## Use Cases

### Data Processing

Process large datasets:

```php
<?php
// Classify 100k customer reviews
$reviews = loadReviews(); // 100,000 reviews

$batches = array_chunk($reviews, 1000); // Batch size of 1000

foreach ($batches as $batchReviews) {
    $requests = array_map(
        fn($review) => createClassificationRequest($review),
        $batchReviews
    );

    $batchIds[] = $client->createBatch($requests);
}

// Wait for all batches to complete
waitForBatches($batchIds);
```

### Content Generation

Generate content at scale:

```php
<?php
// Generate product descriptions for 10k products
$products = loadProducts();

$requests = array_map(
    fn($product) => new LLMRequest(
        model: $model,
        conversation: new LLMConversation([
            LLMMessage::createFromUserString("Write a compelling product description for: {$product->name}")
        ])
    ),
    $products
);

$batchId = $client->createBatch($requests);
```

### Translation

Batch translate documents:

```php
<?php
// Translate 1000 documents to 5 languages
$documents = loadDocuments();
$languages = ['es', 'fr', 'de', 'it', 'pt'];

$requests = [];
foreach ($documents as $doc) {
    foreach ($languages as $lang) {
        $requests[] = new LLMRequest(
            model: $model,
            conversation: new LLMConversation([
                LLMMessage::createFromUserString("Translate to $lang: {$doc->content}")
            ])
        );
    }
}

$batchId = $client->createBatch($requests);
```

## Best Practices

1. **Batch sizing** - Keep batches at 1000-10000 requests for optimal processing
2. **Polling interval** - Poll every 60-300 seconds, not more frequently
3. **Error handling** - Handle failed batches gracefully
4. **Cost monitoring** - Track batch costs across operations
5. **Result storage** - Save results immediately after retrieval
6. **Timeout handling** - Set reasonable timeouts for batch completion
7. **Rate limits** - Respect provider rate limits on batch creation

## Error Handling

There is no dedicated batch exception class. Batch creation fails with a Guzzle HTTP exception (e.g. `GuzzleHttp\Exception\ClientException`), and `retrieveBatch()` throws a `\RuntimeException` when the batch itself failed (e.g. OpenAI produced only an error file) or returned an unexpected status:

```php
<?php
use GuzzleHttp\Exception\GuzzleException;

try {
    $batchId = $client->createBatch($requests);
} catch (GuzzleException $e) {
    // Handle batch creation error (invalid request, rate limit, ...)
    echo "Failed to create batch: " . $e->getMessage();

    // Retry with smaller batch size
    $smallerBatches = array_chunk($requests, 500, preserve_keys: true);
    foreach ($smallerBatches as $batch) {
        $batchIds[] = $client->createBatch($batch);
    }
}

// Retrieve batch results
try {
    $results = $client->retrieveBatch($batchId);
    if ($results !== null) {
        processResults($results); // custom ID => response text
    }
} catch (\RuntimeException $e) {
    echo "Batch failed: " . $e->getMessage();
}
```

## Cost Comparison

Batch processing typically offers 50% cost savings:

```php
<?php
// Real-time: $0.01 per request × 10,000 = $100
$realTimeCost = 10000 * 0.01;

// Batch: $0.005 per request × 10,000 = $50
$batchCost = 10000 * 0.005;

echo "Savings: $" . ($realTimeCost - $batchCost); // $50
```

## Provider Support

- ✅ **OpenAI** - `OpenAIClient` implements `LLMBatchClient` (uploads a JSONL file via `/files` and creates a `/batches` job with a 24h completion window)
- ✅ **Anthropic** - `AnthropicClient` implements `LLMBatchClient` (uses the `/v1/messages/batches` API)
- ❌ **Google Gemini** - Not supported by `GeminiClient`
- ⚠️ **OpenAI-compatible** - `OpenAICompatibleClient` inherits the batch methods, but the provider must support the OpenAI files and batches endpoints

## Implementation Notes

- Custom IDs are the array keys you pass to `createBatch()` and they identify each result returned by `retrieveBatch()`.
- `retrieveBatch()` extracts only the **text content** of each response. Tool calls, structured output and other content types are not decoded.
- For OpenAI, when a completed batch produced only an error file, `retrieveBatch()` throws a `\RuntimeException` with the error details (or returns an empty array when the batch is older than 3 days).

## Limitations

- **Latency** - Results may take minutes to hours
- **No streaming** - Batch responses don't support streaming
- **No cancellation** - Some providers don't allow batch cancellation
- **Result expiration** - Results may expire after 24-48 hours
- **Size limits** - Maximum batch size varies by provider

## See Also

- [Configuration Guide](configuration.md) - Request configuration
- [Provider Documentation](../providers/README.md) - Provider-specific batch features
