# Best Practices

Key patterns and principles for building robust, production-ready AI applications with PHP LLM.

---

## Caching

### Why Caching Matters

Caching is essential for building efficient and cost-effective LLM applications:

- **Cost savings**: Eliminate redundant API calls for identical requests, reducing costs significantly
- **Performance**: Return cached responses instantly instead of waiting for API roundtrips
- **Reliability**: Reduce dependency on external API availability and rate limits
- **Development efficiency**: Speed up testing and development cycles with instant cached responses

### When to Use Caching

Use caching when:

- You have repeated identical requests (same model, same conversation, same parameters)
- You're working in development/testing environments with repetitive queries
- Cost optimization is a priority for your application
- You have predictable query patterns that are likely to repeat

### Basic Implementation

```php
<?php
use Soukicz\Llm\Cache\FileCache;

// Enable caching for identical requests
$cache = new FileCache(sys_get_temp_dir());
$client = new AnthropicClient('sk-xxxxx', $cache);

// Identical requests will automatically use cache, saving API calls
```

### Learn More

For detailed information on cache implementations, custom cache backends, cache warming strategies, and monitoring, see the comprehensive [Caching Guide](../guides/caching.md).

---

## Feedback Loops

### Why Feedback Loops Matter

Feedback loops enable self-correcting AI agents that can validate and improve their own outputs:

- **Validate outputs**: Automatically check responses against quality criteria before accepting them
- **Self-improve**: Request corrections from the LLM without manual intervention
- **Meet requirements**: Ensure responses match your exact specifications (format, completeness, accuracy)
- **Build reliability**: Create consistent, validated outputs essential for production systems

### When to Use Feedback Loops

Use feedback loops when:

- Output format validation is critical (JSON, XML, specific schemas)
- Content must meet specific criteria (length, completeness, accuracy requirements)
- You need guaranteed compliance with business rules
- Building agentic systems that must produce reliable, consistent results

### Key Principle: Loop Counter

**Always implement a loop counter** to prevent infinite loops. This is a critical safeguard that prevents runaway costs and ensures your application remains responsive even when the LLM struggles to meet validation criteria.

```php
<?php
$maxIterations = 5;
$iteration = 0;

$response = $chainClient->run(
    client: $client,
    request: new LLMRequest(
        model: $model,
        conversation: $conversation
    ),
    feedbackCallback: function (LLMResponse $response) use (&$iteration, $maxIterations): ?LLMMessage {
        $iteration++;

        // CRITICAL: Stop after max attempts to prevent infinite loops
        if ($iteration >= $maxIterations) {
            return null; // Stop iteration
        }

        // Your validation logic here
        $text = $response->getLastText();
        if (!isValidJson($text)) {
            return LLMMessage::createFromUserString(
                'The response was not valid JSON. Please provide a valid JSON response.'
            );
        }

        return null; // Validation passed
    }
);
```

Without a loop counter, a feedback loop can continue indefinitely if the LLM cannot satisfy the validation criteria, leading to excessive API costs and application hangs.

### Learn More

For complete examples of validation patterns, nested LLM validation, progressive feedback strategies, and combining feedback loops with tools, see the [Feedback Loops Guide](../guides/feedback-loops.md).

---

## Async Operations for Parallel Tool Calls

### Why Async Operations Matter

Async operations are crucial for performance and efficiency in LLM applications:

- **Performance**: Process multiple requests concurrently instead of sequentially
- **Efficiency**: Reduce total execution time when handling multiple independent operations
- **Scalability**: Handle higher throughput with the same resources
- **Tool calls**: Execute multiple independent tool calls in parallel, dramatically speeding up agentic workflows

### When to Use Async Operations

Use async operations when:

- You have multiple independent LLM requests to process
- Tool calls can be executed in parallel (no dependencies between them)
- Processing large batches of items
- Building real-time applications that need low latency

### Parallel Tool Call Pattern

The most important use case for async operations is parallel tool execution. When an LLM agent needs to call multiple tools that don't depend on each other's results, async operations allow them to execute simultaneously rather than waiting for each to complete sequentially.

For example, if an agent needs to fetch data from three different sources, running them in parallel can reduce execution time from 9 seconds (3 × 3 seconds) to just 3 seconds.

```php
<?php
// Process multiple requests concurrently
$promises = [];

foreach ($items as $item) {
    $promises[] = $chainClient->runAsync(
        client: $client,
        request: new LLMRequest(
            model: $model,
            conversation: new LLMConversation([
                LLMMessage::createFromUserString("Analyze: {$item}")
            ])
        )
    );
}

// Wait for all to complete
$responses = Promise\Utils::all($promises)->wait();

// Process results
foreach ($responses as $response) {
    echo $response->getLastText() . "\n";
}
```

### Learn More

For advanced async patterns, batch processing strategies, handling async tool execution, and error handling in concurrent operations, see:

- [Tools & Function Calling Guide](../guides/tools.md) - Tool implementation with async support
- [Batch Processing Guide](../guides/batch-processing.md) - Large-scale async operations

---

## See Also

- [Caching Guide](../guides/caching.md) - Comprehensive caching documentation
- [Feedback Loops Guide](../guides/feedback-loops.md) - Building self-correcting agents
- [Tools Guide](../guides/tools.md) - Function calling and tool usage
- [Batch Processing Guide](../guides/batch-processing.md) - High-volume processing
- [State Management](state-management.md) - Managing conversation state
