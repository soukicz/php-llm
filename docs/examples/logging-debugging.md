# Logging & Debugging

Monitor and debug your AI agents with built-in logging and debugging tools.

## Markdown Formatter

Convert requests and responses to readable markdown for logging or debugging:

```php
<?php
use Soukicz\Llm\MarkdownFormatter;

$formatter = new MarkdownFormatter();

// Format response
$markdown = $formatter->responseToMarkdown($response);
echo $markdown;

// Format request
$markdown = $formatter->requestToMarkdown($request);
echo $markdown;
```

**Sample Output:**

```markdown
## Request
**Model:** claude-sonnet-4-5-20250929
**Temperature:** 1.0
**Messages:** 2

### User
What is the capital of France?

---

## Response
**Stop Reason:** end_turn
**Input Tokens:** 15
**Output Tokens:** 8
**Cost:** $0.000345

### Assistant
The capital of France is Paris.
```

## Custom Logger

Implement `LLMLogger` for custom logging:

```php
<?php
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Log\LLMLogger;
use Soukicz\Llm\MarkdownFormatter;

readonly class LLMFileLogger implements LLMLogger {

    public function __construct(
        private string            $logPath,
        private MarkdownFormatter $formatter
    ) {
    }

    public function requestStarted(LLMRequest $request): void {
        $markdown = $this->formatter->requestToMarkdown($request);
        file_put_contents($this->logPath, $markdown . "\n\n", FILE_APPEND);
    }

    public function requestFinished(LLMResponse $response): void {
        $markdown = $this->formatter->responseToMarkdown($response);
        file_put_contents($this->logPath, $markdown . "\n\n---\n\n", FILE_APPEND);
    }
}
```

## Using the Logger

```php
<?php
use Soukicz\Llm\Client\LLMAgentClient;
use Soukicz\Llm\MarkdownFormatter;

$logger = new LLMFileLogger(__DIR__ . '/llm.log', new MarkdownFormatter());
$agentClient = new LLMAgentClient($logger);

// All requests will now be logged
$response = $agentClient->run($client, $request);
```

## PSR-3 Logger Integration

Integrate with PSR-3 loggers (Monolog, etc.):

```php
<?php
use Psr\Log\LoggerInterface;
use Soukicz\Llm\Log\LLMLogger;

readonly class PSR3LLMLogger implements LLMLogger {

    public function __construct(
        private LoggerInterface   $logger,
        private MarkdownFormatter $formatter
    ) {
    }

    public function requestStarted(LLMRequest $request): void {
        $this->logger->info('LLM Request Started', [
            'model' => $request->getModel()->getCode(),
            'messages' => count($request->getConversation()->getMessages()),
        ]);
    }

    public function requestFinished(LLMResponse $response): void {
        $inputCost = $response->getInputPriceUsd() ?? 0;
        $outputCost = $response->getOutputPriceUsd() ?? 0;

        $this->logger->info('LLM Request Finished', [
            'model' => $response->getRequest()->getModel()->getCode(),
            'input_tokens' => $response->getInputTokens(),
            'output_tokens' => $response->getOutputTokens(),
            'cost' => $inputCost + $outputCost,
            'response_time_ms' => $response->getTotalTimeMs(),
        ]);
    }
}
```

```php
<?php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$monolog = new Logger('llm');
$monolog->pushHandler(new StreamHandler(__DIR__ . '/llm.log', Logger::INFO));

$logger = new PSR3LLMLogger($monolog, new MarkdownFormatter());
$agentClient = new LLMAgentClient($logger);
```

**Sample Log Output:**

```
[2025-01-15 10:23:45] llm.INFO: LLM Request Started {"model":"claude-sonnet-4-5-20250929","messages":1}
[2025-01-15 10:23:47] llm.INFO: LLM Request Finished {"model":"claude-sonnet-4-5-20250929","input_tokens":15,"output_tokens":8,"cost":0.000345,"response_time_ms":1823}
```

## HTTP Middleware Logging

Log HTTP requests with Guzzle middleware:

```php
<?php
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\MessageFormatter;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('http');
$logger->pushHandler(new StreamHandler(__DIR__ . '/http.log'));

$stack = HandlerStack::create();
$stack->push(
    Middleware::log(
        $logger,
        new MessageFormatter('{method} {uri} - {code} - {res_body}')
    )
);

$client = new AnthropicClient(
    apiKey: 'sk-xxxxx',
    cache: $cache,
    handler: $stack
);
```

## Debugging Failed Requests

```php
<?php
use Soukicz\Llm\Client\LLMClientException;

try {
    $response = $agentClient->run($client, $request);
} catch (LLMClientException $e) {
    // Log error details
    error_log("LLM Error: " . $e->getMessage());
    error_log("Request: " . $formatter->requestToMarkdown($request));

    // Check if it's a rate limit
    if ($e->getCode() === 429) {
        sleep(60);
        $response = $agentClient->run($client, $request); // Retry
    }
}
```

## Performance Monitoring

Track request timing and costs:

```php
<?php
class PerformanceLogger implements LLMLogger {
    private array $timings = [];

    public function requestStarted(LLMRequest $request): void {
        $this->timings[spl_object_id($request)] = microtime(true);
    }

    public function requestFinished(LLMResponse $response): void {
        $requestId = spl_object_id($response->getRequest());
        $duration = isset($this->timings[$requestId])
            ? (microtime(true) - $this->timings[$requestId]) * 1000
            : $response->getTotalTimeMs();

        $totalTokens = $response->getInputTokens() + $response->getOutputTokens();
        $totalCost = ($response->getInputPriceUsd() ?? 0) + ($response->getOutputPriceUsd() ?? 0);

        echo sprintf(
            "Request %s: %dms, %d tokens, $%.6f\n",
            $response->getRequest()->getModel()->getCode(),
            $duration,
            $totalTokens,
            $totalCost
        );

        unset($this->timings[$requestId]);
    }
}
```

**Sample Output:**

```
Request claude-sonnet-4-5-20250929: 1823ms, 23 tokens, $0.000345
Request gpt-5-2025-08-07: 956ms, 45 tokens, $0.000890
Request gemini-2.5-pro: 1245ms, 31 tokens, $0.000520
```

## Debug Mode

Enable verbose debugging to inspect all request/response details:

```php
<?php
class DebugLogger implements LLMLogger {
    public function requestStarted(LLMRequest $request): void {
        echo "=== REQUEST STARTED ===\n";
        echo "Model: " . $request->getModel()->getCode() . "\n";
        echo "Temperature: " . ($request->getTemperature() ?? 'default') . "\n";
        echo "Max Tokens: " . ($request->getMaxTokens() ?? 'default') . "\n";
        echo "Messages: " . count($request->getConversation()->getMessages()) . "\n";
        echo "Tools: " . count($request->getTools()) . "\n\n";
    }

    public function requestFinished(LLMResponse $response): void {
        echo "=== REQUEST FINISHED ===\n";
        echo "Stop Reason: " . $response->getStopReason() . "\n";
        echo "Response Time: " . $response->getTotalTimeMs() . "ms\n";
        echo "Input Tokens: " . $response->getInputTokens() . "\n";
        echo "Output Tokens: " . $response->getOutputTokens() . "\n";

        $totalCost = ($response->getInputPriceUsd() ?? 0) + ($response->getOutputPriceUsd() ?? 0);
        echo "Cost: $" . number_format($totalCost, 6) . "\n";

        $text = $response->getLastText();
        echo "Response: " . substr($text, 0, 100) . (strlen($text) > 100 ? "..." : "") . "\n\n";
    }
}
```

**Sample Output:**

```
=== REQUEST STARTED ===
Model: claude-sonnet-4-5-20250929
Temperature: 1.0
Max Tokens: 2048
Messages: 1
Tools: 0

=== REQUEST FINISHED ===
Stop Reason: end_turn
Response Time: 1823ms
Input Tokens: 15
Output Tokens: 8
Cost: $0.000345
Response: The capital of France is Paris.
```

## Structured Logging

Log in JSON format for analysis and monitoring:

```php
<?php
class JSONLogger implements LLMLogger {
    public function __construct(private string $logFile) {}

    public function requestStarted(LLMRequest $request): void {
        // Optional: log request start
    }

    public function requestFinished(LLMResponse $response): void {
        $inputCost = $response->getInputPriceUsd() ?? 0;
        $outputCost = $response->getOutputPriceUsd() ?? 0;

        $log = [
            'timestamp' => date('c'),
            'model' => $response->getRequest()->getModel()->getCode(),
            'input_tokens' => $response->getInputTokens(),
            'output_tokens' => $response->getOutputTokens(),
            'total_tokens' => $response->getInputTokens() + $response->getOutputTokens(),
            'input_cost' => $inputCost,
            'output_cost' => $outputCost,
            'total_cost' => $inputCost + $outputCost,
            'response_time_ms' => $response->getTotalTimeMs(),
            'stop_reason' => $response->getStopReason(),
        ];

        file_put_contents(
            $this->logFile,
            json_encode($log) . "\n",
            FILE_APPEND
        );
    }
}
```

**Sample Log Output (llm.json):**

```json
{"timestamp":"2025-01-15T10:23:47+00:00","model":"claude-sonnet-4-5-20250929","input_tokens":15,"output_tokens":8,"total_tokens":23,"input_cost":0.000045,"output_cost":0.0003,"total_cost":0.000345,"response_time_ms":1823,"stop_reason":"end_turn"}
{"timestamp":"2025-01-15T10:24:12+00:00","model":"gpt-5-2025-08-07","input_tokens":22,"output_tokens":45,"total_tokens":67,"input_cost":0.00011,"output_cost":0.00078,"total_cost":0.00089,"response_time_ms":956,"stop_reason":"stop"}
{"timestamp":"2025-01-15T10:25:03+00:00","model":"gemini-2.5-pro","input_tokens":18,"output_tokens":31,"total_tokens":49,"input_cost":0.00009,"output_cost":0.00043,"total_cost":0.00052,"response_time_ms":1245,"stop_reason":"STOP"}
```

This format is ideal for log aggregation tools like ELK stack, Splunk, or DataDog.

## See Also

- [Configuration Guide](../guides/configuration.md) - Configure logging behavior
- [Quick Start](quick-start.md) - Basic usage examples
