# Reasoning Models

Reasoning models spend additional computation time thinking through problems before responding. This makes them particularly effective for complex tasks requiring deep analysis, mathematics, coding, and logical reasoning.

All three major providers support reasoning through this library:

- **Anthropic** - Claude extended thinking (adaptive thinking with effort levels, or an explicit token budget)
- **OpenAI** - Reasoning effort on o-series and GPT-5.x models
- **Google Gemini** - Thinking levels on Gemini 2.5+ models

## Overview

Traditional language models generate responses token-by-token immediately. Reasoning models add an internal "thinking" phase where they:
- Break down complex problems
- Consider multiple approaches
- Verify their reasoning
- Refine their answers

This results in more accurate responses for challenging tasks, at the cost of higher latency and token usage.

## Configuring Reasoning

PHP LLM provides two ways to configure reasoning via the `reasoningConfig` parameter of `LLMRequest`. When `reasoningConfig` is left at `null` (the default), the provider's default behavior is used.

### Reasoning Effort

Control how much computational effort the model spends reasoning. `ReasoningEffort` works with all three providers:

```php
<?php
use Soukicz\Llm\Client\OpenAI\Model\GPTo3;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\LLMRequest;

$request = new LLMRequest(
    model: new GPTo3(GPTo3::VERSION_2025_04_16),
    conversation: $conversation,
    reasoningConfig: ReasoningEffort::HIGH
);
```

**Effort Levels:**
- `ReasoningEffort::NONE` - Disable reasoning entirely
- `ReasoningEffort::MINIMAL` - Minimal reasoning
- `ReasoningEffort::LOW` - Fast, less thorough reasoning
- `ReasoningEffort::MEDIUM` - Balanced reasoning
- `ReasoningEffort::HIGH` - Thorough, slower reasoning
- `ReasoningEffort::EXTRA_HIGH` - Maximum reasoning effort

There is no default level — omitting `reasoningConfig` leaves the decision to the provider.

**How effort maps to each provider:**

| Effort | Anthropic (adaptive thinking + effort) | OpenAI (`reasoning_effort`) | Gemini 3.x (`thinkingLevel`) | Gemini 2.x (`thinkingBudget`) |
|---|---|---|---|---|
| `NONE` | thinking disabled | `none` | `thinkingBudget: 0` | `0` |
| `MINIMAL` | `low` | `minimal` | `minimal` | `512` |
| `LOW` | `low` | `low` | `low` | `1024` |
| `MEDIUM` | `medium` | `medium` | `medium` | `8192` |
| `HIGH` | `high` | `high` | `high` | `24576` |
| `EXTRA_HIGH` | `max` | `xhigh` | `high` | `24576` |

Gemini 2.x models do not accept `thinkingLevel` — the library automatically translates the effort level to a `thinkingBudget` token budget for them.

### Reasoning Budget

Set an explicit token limit for the model's internal reasoning. `ReasoningBudget` is **Anthropic-only** — it maps to Claude's `thinking.budget_tokens`. The OpenAI and Gemini encoders throw an `InvalidArgumentException` when given a `ReasoningBudget`.

```php
<?php
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Sonnet;
use Soukicz\Llm\Config\ReasoningBudget;
use Soukicz\Llm\LLMRequest;

$request = new LLMRequest(
    model: new AnthropicClaude46Sonnet(),
    conversation: $conversation,
    reasoningConfig: new ReasoningBudget(10000) // Max 10k tokens for thinking
);
```

## Complete Example

```php
<?php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\OpenAI\OpenAIClient;
use Soukicz\Llm\Client\OpenAI\Model\GPTo3;
use Soukicz\Llm\Client\LLMAgentClient;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;

$cache = new FileCache(sys_get_temp_dir());
$openai = new OpenAIClient('sk-xxxxx', 'org-xxxxx', $cache);
$agentClient = new LLMAgentClient();

$response = $agentClient->run(
    client: $openai,
    request: new LLMRequest(
        model: new GPTo3(GPTo3::VERSION_2025_04_16),
        conversation: new LLMConversation([
            LLMMessage::createFromUserString(
                'A farmer has 17 sheep. All but 9 die. How many sheep are left alive?'
            )
        ]),
        reasoningConfig: ReasoningEffort::HIGH
    )
);

echo $response->getLastText(); // "9 sheep are left alive"
```

The same request works with Claude extended thinking:

```php
<?php
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Sonnet;

$anthropic = new AnthropicClient('sk-xxxxx', $cache);

$response = $agentClient->run(
    client: $anthropic,
    request: new LLMRequest(
        model: new AnthropicClaude46Sonnet(),
        conversation: $conversation,
        reasoningConfig: ReasoningEffort::HIGH
    )
);
```

## When to Use Reasoning Models

**Ideal Use Cases:**
- ✅ Complex mathematical problems
- ✅ Advanced coding challenges
- ✅ Logical puzzles and riddles
- ✅ Scientific analysis
- ✅ Multi-step problem solving
- ✅ Tasks requiring verification

**Not Ideal For:**
- ❌ Simple queries
- ❌ Creative writing
- ❌ Casual conversation
- ❌ Tasks requiring fast responses
- ❌ Cost-sensitive applications

## Supported Models

### Anthropic (Extended Thinking)

```php
<?php
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Opus;

// Supports ReasoningEffort (adaptive thinking) and ReasoningBudget (explicit token budget)
$sonnet = new AnthropicClaude46Sonnet();
$opus = new AnthropicClaude46Opus();
```

Claude's thinking blocks are returned as `LLMMessageReasoning` content in the conversation, so you can inspect what the model thought about.

### OpenAI Reasoning Models

```php
<?php
use Soukicz\Llm\Client\OpenAI\Model\GPTo3;
use Soukicz\Llm\Client\OpenAI\Model\GPTo4Mini;
use Soukicz\Llm\Client\OpenAI\Model\GPT54;

// o3 - Dedicated reasoning model
$o3 = new GPTo3(GPTo3::VERSION_2025_04_16);

// o4-mini - Faster, more cost-effective reasoning
$o4mini = new GPTo4Mini(GPTo4Mini::VERSION_2025_04_16);

// GPT-5.x - General models with configurable reasoning effort
$gpt54 = new GPT54(GPT54::VERSION_2026_03_05);
```

### Google Gemini (Thinking)

```php
<?php
use Soukicz\Llm\Client\Gemini\Model\Gemini25Flash;
use Soukicz\Llm\Client\Gemini\Model\Gemini25Pro;
use Soukicz\Llm\Client\Gemini\Model\Gemini3ProPreview;

// Gemini 2.5 models: effort is sent as a thinking token budget
$pro = new Gemini25Pro();
$flash = new Gemini25Flash();

// Gemini 3.x models: effort is sent as a thinking level
$gemini3 = new Gemini3ProPreview();
```

## Cost Considerations

Reasoning models consume significantly more tokens due to their internal thinking process:

1. **Input tokens** - Your prompt (standard pricing)
2. **Reasoning tokens** - Internal thinking (billed as output tokens)
3. **Output tokens** - The response (standard pricing)

On Anthropic, use `ReasoningBudget` to cap thinking tokens:

```php
<?php
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Sonnet;
use Soukicz\Llm\Config\ReasoningBudget;

// Limit thinking to 5000 tokens for cost control
$request = new LLMRequest(
    model: new AnthropicClaude46Sonnet(),
    conversation: $conversation,
    reasoningConfig: new ReasoningBudget(5000)
);
```

On OpenAI and Gemini, use a lower `ReasoningEffort` level instead.

## Tracking Usage

Monitor token usage and cost directly on the response:

```php
<?php
$response = $agentClient->run($client, $request);

echo "Input tokens: " . $response->getInputTokens() . "\n";
echo "Output tokens: " . $response->getOutputTokens() . "\n";
echo "Input cost: $" . $response->getInputPriceUsd() . "\n";
echo "Output cost: $" . $response->getOutputPriceUsd() . "\n";
echo "Time: " . $response->getTotalTimeMs() . " ms\n";
```

Reasoning tokens are included in the output token count reported by the providers.

## Combining with Other Features

### With Tools

Reasoning models work excellently with tools for complex agent workflows:

```php
<?php
$request = new LLMRequest(
    model: new GPTo3(GPTo3::VERSION_2025_04_16),
    conversation: $conversation,
    tools: [$calculatorTool, $databaseTool],
    reasoningConfig: ReasoningEffort::HIGH
);
```

### With Feedback Loops

Combine reasoning with validation for ultra-reliable agents:

```php
<?php
$response = $agentClient->run(
    client: $openai,
    request: new LLMRequest(
        model: new GPTo3(GPTo3::VERSION_2025_04_16),
        conversation: $conversation,
        reasoningConfig: ReasoningEffort::HIGH
    ),
    feedbackCallback: function ($response) {
        // Validate the reasoning model's output
        return $isValid ? null : LLMMessage::createFromUserString('Please reconsider...');
    }
);
```

## Best Practices

1. **Start with MEDIUM effort** - Only increase if needed
2. **Cap thinking tokens on Anthropic** - Use `ReasoningBudget` to prevent runaway costs
3. **Use for appropriate tasks** - Don't use reasoning models for simple queries
4. **Monitor costs closely** - Track token usage via `getOutputTokens()` and `getOutputPriceUsd()`
5. **Test with cheaper models first** - o4-mini or Gemini Flash are more cost-effective for development

## Provider Support

| Feature | Anthropic | OpenAI | Gemini |
|---|---|---|---|
| `ReasoningEffort` | ✅ (adaptive extended thinking + effort) | ✅ (`reasoning_effort`) | ✅ (`thinkingLevel` on 3.x, `thinkingBudget` on 2.x) |
| `ReasoningBudget` | ✅ (`thinking.budget_tokens`) | ❌ throws `InvalidArgumentException` | ❌ throws `InvalidArgumentException` |
| Thinking visible in response | ✅ (`LLMMessageReasoning`) | ❌ | ✅ (streaming `THINKING_DELTA`) |

For OpenAI-compatible providers, support depends on the underlying model.

## See Also

- [Configuration Guide](configuration.md) - All request configuration options
- [Feedback Loops](feedback-loops.md) - Validate reasoning outputs
- [OpenAI Provider Documentation](../providers/README.md) - OpenAI-specific features
