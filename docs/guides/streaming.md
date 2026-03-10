# Streaming

Stream LLM responses in real time while keeping the simple request/response API. Streaming is implemented as a side-effect: you attach a listener to `LLMRequest`, receive incremental updates as they arrive, and the final `LLMResponse` is identical to a non-streaming call.

## Overview

Streaming is useful for:
- Showing real-time typing progress to users
- Displaying tool call activity during agentic workflows
- Providing visual feedback for long-running requests
- Building interactive CLI or web interfaces

Streaming is **optional** and **provider-agnostic**. The same listener works with Anthropic, OpenAI, and Gemini. No changes are needed to tool handling, feedback loops, or the agent client — the listener auto-propagates through the tool loop.

## Quick Start

```php
<?php
use Soukicz\Llm\Stream\CallableStreamListener;
use Soukicz\Llm\Stream\StreamEvent;
use Soukicz\Llm\Stream\StreamEventType;

$request = new LLMRequest(
    model: $model,
    conversation: $conversation,
    streamListener: new CallableStreamListener(function (StreamEvent $event) {
        if ($event->type === StreamEventType::TEXT_DELTA) {
            echo $event->delta;
        }
    }),
);

// Same API, same response — streaming is transparent
$response = $agentClient->run($client, $request);
```

## Stream Events

All events are delivered via `StreamEvent`, which has the following properties:

| Property | Type | Description |
|---|---|---|
| `type` | `StreamEventType` | The event type |
| `blockIndex` | `int` | Content block index (-1 for message-level events) |
| `delta` | `string` | Text or JSON fragment |
| `toolName` | `?string` | Tool name (for tool events) |
| `toolId` | `?string` | Tool call ID (for tool events) |

### Event Types

| Event | When it fires |
|---|---|
| `MESSAGE_START` | Response begins |
| `TEXT_DELTA` | New text fragment available |
| `THINKING_DELTA` | New reasoning/thinking fragment (Claude, Gemini) |
| `TOOL_USE_START` | Model begins a tool call |
| `TOOL_INPUT_DELTA` | Tool input JSON fragment |
| `CONTENT_BLOCK_STOP` | A content block is complete |
| `MESSAGE_COMPLETE` | Response is fully received |

## Using the Callable Listener

The simplest way to handle stream events is with `CallableStreamListener`, which wraps a closure:

```php
<?php
use Soukicz\Llm\Stream\CallableStreamListener;
use Soukicz\Llm\Stream\StreamEvent;
use Soukicz\Llm\Stream\StreamEventType;

$listener = new CallableStreamListener(function (StreamEvent $event) {
    match ($event->type) {
        StreamEventType::TEXT_DELTA => print($event->delta),
        StreamEventType::TOOL_USE_START => print("\n[Calling: {$event->toolName}]\n"),
        StreamEventType::TOOL_INPUT_DELTA => print($event->delta),
        StreamEventType::MESSAGE_COMPLETE => print("\n"),
        default => null,
    };
});
```

## Implementing a Custom Listener

For more control, implement `StreamListenerInterface` directly:

```php
<?php
use Soukicz\Llm\Stream\StreamListenerInterface;
use Soukicz\Llm\Stream\StreamEvent;
use Soukicz\Llm\Stream\StreamEventType;

class ProgressStreamListener implements StreamListenerInterface {
    private float $startTime;

    public function __construct(
        private readonly \Closure $onOutput,
    ) {
        $this->startTime = microtime(true);
    }

    public function onStreamEvent(StreamEvent $event): void {
        match ($event->type) {
            StreamEventType::MESSAGE_START => ($this->onOutput)("[started]\n"),
            StreamEventType::TEXT_DELTA => ($this->onOutput)($event->delta),
            StreamEventType::TOOL_USE_START => ($this->onOutput)("\n> Using tool: {$event->toolName}\n"),
            StreamEventType::MESSAGE_COMPLETE => ($this->onOutput)(sprintf(
                "\n[completed in %.1fs]\n",
                microtime(true) - $this->startTime
            )),
            default => null,
        };
    }
}
```

## Practical Examples

### CLI Progress Display

Print assistant responses as they stream, with tool call indicators:

```php
<?php
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\LLMAgentClient;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Stream\CallableStreamListener;
use Soukicz\Llm\Stream\StreamEvent;
use Soukicz\Llm\Stream\StreamEventType;

$client = new AnthropicClient(getenv('ANTHROPIC_API_KEY'));
$agentClient = new LLMAgentClient();

$response = $agentClient->run(
    client: $client,
    request: new LLMRequest(
        model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
        conversation: new LLMConversation([
            LLMMessage::createFromUserString('What is the weather in Prague?')
        ]),
        tools: [$weatherTool],
        streamListener: new CallableStreamListener(function (StreamEvent $event) {
            match ($event->type) {
                StreamEventType::TEXT_DELTA => print($event->delta),
                StreamEventType::TOOL_USE_START => print("\n🔧 {$event->toolName}\n"),
                StreamEventType::TOOL_INPUT_DELTA => null, // silent
                StreamEventType::MESSAGE_COMPLETE => print("\n"),
                default => null,
            };
        }),
    ),
);

// $response is the same LLMResponse you'd get without streaming
echo "\nTokens: {$response->getInputTokens()} in, {$response->getOutputTokens()} out\n";
```

### Web Server-Sent Events (SSE)

Forward stream events to a browser via SSE for a ChatGPT-like experience:

```php
<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

$response = $agentClient->run(
    client: $client,
    request: new LLMRequest(
        model: $model,
        conversation: $conversation,
        streamListener: new CallableStreamListener(function (StreamEvent $event) {
            $data = json_encode([
                'type' => $event->type->value,
                'delta' => $event->delta,
                'toolName' => $event->toolName,
            ]);
            echo "data: {$data}\n\n";
            ob_flush();
            flush();
        }),
    ),
);

// Send final complete message
echo "data: " . json_encode(['type' => 'done', 'text' => $response->getLastText()]) . "\n\n";
```

### Collecting Events for Testing

Capture all stream events for assertions:

```php
<?php
$events = [];
$listener = new CallableStreamListener(function (StreamEvent $event) use (&$events) {
    $events[] = $event;
});

$response = $agentClient->run($client, new LLMRequest(
    model: $model,
    conversation: $conversation,
    tools: $tools,
    streamListener: $listener,
));

// Verify streaming worked
$textDeltas = array_filter($events, fn($e) => $e->type === StreamEventType::TEXT_DELTA);
assert(count($textDeltas) > 0, 'Expected text deltas');

// The accumulated text matches the final response
$streamedText = implode('', array_map(fn($e) => $e->delta, $textDeltas));
// Note: For Anthropic/OpenAI, this equals $response->getLastText()
// For Gemini, text parts are separate (each chunk is a distinct text part)
```

### Logging Tool Calls with Timing

Track tool execution with start/end timing:

```php
<?php
class ToolTimingListener implements StreamListenerInterface {
    private array $toolTimings = [];
    private array $activeTools = [];

    public function onStreamEvent(StreamEvent $event): void {
        match ($event->type) {
            StreamEventType::TOOL_USE_START => $this->activeTools[$event->blockIndex] = [
                'name' => $event->toolName,
                'start' => microtime(true),
            ],
            StreamEventType::CONTENT_BLOCK_STOP => $this->finishTool($event->blockIndex),
            default => null,
        };
    }

    private function finishTool(int $blockIndex): void {
        if (isset($this->activeTools[$blockIndex])) {
            $tool = $this->activeTools[$blockIndex];
            $this->toolTimings[] = [
                'name' => $tool['name'],
                'duration_ms' => (microtime(true) - $tool['start']) * 1000,
            ];
            unset($this->activeTools[$blockIndex]);
        }
    }

    public function getToolTimings(): array {
        return $this->toolTimings;
    }
}
```

> **Note:** This timing pattern measures the duration of streaming tool input JSON from the API. It works well with Anthropic and OpenAI which stream tool inputs incrementally. Gemini sends complete tool calls in a single chunk, so `TOOL_USE_START` and `CONTENT_BLOCK_STOP` fire back-to-back with ~0ms between them. For Gemini, tool *execution* time (the time your handler takes) is a more useful metric.

## How It Works

### Architecture

Streaming is implemented as an optional code path in each provider's client. When `LLMRequest::getStreamListener()` returns a listener:

1. The client sends the request with streaming enabled (SSE)
2. The response body is consumed incrementally by a provider-specific **stream accumulator**
3. The accumulator parses SSE events, fires the listener for each delta, and reconstructs the full response
4. The reconstructed response is passed to the existing `decodeResponse()` method — identical to non-streaming

The `LLMAgentClient` needs zero changes. The stream listener is a `readonly` property on `LLMRequest` and auto-propagates through `clone` in the tool loop, so the listener continues to receive events across multi-turn tool calls.

### Cache Interaction

Streaming and caching work together seamlessly. Cache entries are shared between streaming and non-streaming requests:

- **Cache hit with streaming:** When a cached response exists for the request, stream events are replayed from the cached data (full content emitted as single deltas). The listener receives `MESSAGE_START`, content deltas, and `MESSAGE_COMPLETE` — same event types as a live stream, just instant.
- **Cache miss with streaming:** The request goes to the API via SSE. After the stream completes, the accumulated response is stored in the cache for future use.
- **Cross-mode sharing:** A non-streaming call populates the cache, and a subsequent streaming call can hit it (and vice versa). Both paths use the same cache key.

### Error Handling

- If the **listener throws**, the exception propagates and the promise rejects. The response is not returned.
- If the **provider sends an error** mid-stream (e.g., Anthropic's `overloaded_error`), a `RuntimeException` is thrown.
- HTTP errors (429, 500) are handled by the retry middleware before streaming begins.

## Provider Support

| Feature | Anthropic | OpenAI | Gemini |
|---|---|---|---|
| Text streaming | ✅ | ✅ | ✅ |
| Tool call streaming | ✅ (incremental JSON) | ✅ (incremental JSON) | ✅ (complete per chunk) |
| Thinking/reasoning | ✅ | ❌ | ✅ |
| Multiple tool calls | ✅ | ✅ (index-based) | ✅ |

**Note:** Gemini sends complete function call objects in a single chunk, while Anthropic and OpenAI stream tool input JSON incrementally.

## See Also

- [Configuration](configuration.md) - All `LLMRequest` parameters
- [Tools Guide](tools.md) - Tool definitions and function calling
- [Feedback Loops](feedback-loops.md) - Self-correcting agents (works with streaming)
