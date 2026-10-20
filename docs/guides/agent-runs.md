# Agent Runs: Progress, Limits and Cancellation

`LLMAgentClient::run()` loops while the model calls tools. Pass `AgentRunOptions` as the fourth argument to watch
the run and to stop it safely. Without options a run still differs from older versions in four ways: a refusal throws `AgentRunInterruptedException` (reason `refusal`) instead of returning the refused text, a `pause_turn` stop continues the run, a tool that throws, rejects or returns the wrong type becomes an error result for the model instead of aborting the run, and error results are sent to Claude with `is_error`.

```php
<?php
use Soukicz\Llm\Agent\AgentListener;
use Soukicz\Llm\Agent\AgentRunInterruptedException;
use Soukicz\Llm\Agent\AgentRunOptions;
use Soukicz\Llm\Client\LLMAgentClient;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessageContents;

$listener = new class implements AgentListener {
    public function onModelResponse(LLMResponse $stepResponse): void {
        // one API call: $stepResponse->getStepUsage(), $stepResponse->getTotalCostUsd() (cumulative)
    }

    public function onToolCallStarted(string $toolName, array $input): void {
        echo "running $toolName\n";
    }

    public function onToolCallFinished(string $toolName, array $input, LLMMessageContents $result, bool $isError, int $durationMs): void {
        echo "$toolName " . ($isError ? 'failed' : 'done') . " in {$durationMs} ms\n";
    }
};

try {
    $response = (new LLMAgentClient())->run($client, $request, null, new AgentRunOptions(
        listener: $listener,
        maxIterations: 40,                 // model calls
        maxCostUsd: 5.0,                   // cumulative, cache reads and writes included
        deadline: microtime(true) + 480,   // unix time
        shouldCancel: fn(): bool => $stopRequested(),
    ));
} catch (AgentRunInterruptedException $e) {
    $e->getReason();        // 'iterations' | 'cost' | 'deadline' | 'cancelled' | 'refusal'
    $e->getConversation();  // everything so far, safe to store and continue later
    $e->getUsage();         // TokenUsage of the run so far
    $e->getCostUsd();
}
```

## When the limits are checked

| Option | Checked | Effect |
|---|---|---|
| `maxIterations` | before each model call | N calls run, the next one throws `iterations` |
| `maxCostUsd` | after each model response | throws `cost`; the tools of that response do not run |
| `deadline` | before each model call and each tool call | throws `deadline` |
| `shouldCancel` | before each model call and each tool call (before the deadline) | throws `cancelled` |

When a run stops while tool calls are open, the stored conversation gets one closing user message. That message holds the results that finished, plus `ERROR: not run, the agent run was interrupted (<reason>)` for the rest. Every `tool_use` therefore has a `tool_result`, and the conversation replays as a valid request.

## Tool errors

A tool may fail in three ways:
- it throws;
- its promise is rejected;
- it returns something that is not `LLMMessageContents`.

Each one becomes an error result such as `ERROR: RuntimeException: timeout`, and the run continues. Results built with `LLMMessageContents::fromErrorString()` are sent to Claude with `is_error: true`.

## Refusals and paused turns

- `StopReason::REFUSAL`: a safety classifier declined (HTTP 200), with details in `LLMResponse::getStopDetails()`. In an agent run this throws `AgentRunInterruptedException` with reason `refusal`. The refused turn is left out of `getConversation()`. A plain `sendRequestAsync()` call returns the stop reason instead.
- `StopReason::PAUSE_TURN`: a server-side tool loop paused. The agent loop re-sends the conversation unchanged and goes on. This counts as an iteration.

## Usage and cost

`LLMResponse::getUsage()` returns a `Soukicz\Llm\Usage\TokenUsage` for the whole run, and `getStepUsage()` for one API call. Both carry `input` (uncached), `output`, `cacheWrite5m`, `cacheWrite1h`, `cacheRead` and `reasoning`. `getTotalCostUsd()` includes cache writes at their TTL price and cache reads. OpenAI fills `cacheRead` and `reasoning` from the provider's own counters; Gemini fills `reasoning` only, so its `cacheRead` stays 0.
