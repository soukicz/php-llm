# Reasoning Models

Reasoning models like OpenAI's o3 and o4 series spend additional computation time thinking through problems before responding. This makes them particularly effective for complex tasks requiring deep analysis, mathematics, coding, and logical reasoning.

## Overview

Traditional language models generate responses token-by-token immediately. Reasoning models add an internal "thinking" phase where they:
- Break down complex problems
- Consider multiple approaches
- Verify their reasoning
- Refine their answers

This results in more accurate responses for challenging tasks, at the cost of higher latency and token usage.

## Configuring Reasoning

PHP LLM provides two ways to configure reasoning models:

### Reasoning Effort

Control how much computational effort the model spends reasoning:

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
- `ReasoningEffort::LOW` - Fast, less thorough reasoning
- `ReasoningEffort::MEDIUM` - Balanced reasoning (default)
- `ReasoningEffort::HIGH` - Thorough, slower reasoning

### Reasoning Budget

Set a token limit for the model's internal reasoning:

```php
<?php
use Soukicz\Llm\Config\ReasoningBudget;

$request = new LLMRequest(
    model: new GPTo3(GPTo3::VERSION_2025_04_16),
    conversation: $conversation,
    reasoningConfig: new ReasoningBudget(10000) // Max 10k tokens for reasoning
);
```

## Complete Example

```php
<?php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\OpenAI\OpenAIClient;
use Soukicz\Llm\Client\OpenAI\Model\GPTo3;
use Soukicz\Llm\Client\LLMChainClient;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;

$cache = new FileCache(sys_get_temp_dir());
$openai = new OpenAIClient('sk-xxxxx', 'org-xxxxx', $cache);
$chainClient = new LLMChainClient();

$response = $chainClient->run(
    client: $openai,
    request: new LLMRequest(
        model: new GPTo3(GPTo3::VERSION_2025_04_16),
        conversation: new LLMConversation([
            LLMMessage::createFromUserString(
                'A farmer has 17 sheep. All but 9 die. How many sheep are left alive?'
            )
        ]),
        reasoningEffort: ReasoningEffort::HIGH
    )
);

echo $response->getLastText(); // "9 sheep are left alive"
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

### OpenAI Reasoning Models

```php
<?php
use Soukicz\Llm\Client\OpenAI\Model\GPTo3;
use Soukicz\Llm\Client\OpenAI\Model\GPTo4Mini;

// o3 - Most capable reasoning model
$o3 = new GPTo3(GPTo3::VERSION_2025_04_16);

// o4-mini - Faster, more cost-effective reasoning
$o4mini = new GPTo4Mini(GPTo4Mini::VERSION_2025_04_16);
```

## Cost Considerations

Reasoning models consume significantly more tokens due to their internal thinking process:

1. **Input tokens** - Your prompt (standard pricing)
2. **Reasoning tokens** - Internal thinking (usually discounted pricing)
3. **Output tokens** - The response (standard pricing)

Use `ReasoningBudget` to control costs:

```php
<?php
use Soukicz\Llm\Config\ReasoningBudget;

// Limit reasoning to 5000 tokens for cost control
$request = new LLMRequest(
    model: new GPTo3(GPTo3::VERSION_2025_04_16),
    conversation: $conversation,
    reasoningConfig: new ReasoningBudget(5000)
);
```

## Tracking Reasoning Usage

Monitor token usage including reasoning tokens:

```php
<?php
$response = $chainClient->run($client, $request);
$usage = $response->getTokenUsage();

echo "Input tokens: " . $usage->getInputTokens() . "\n";
echo "Reasoning tokens: " . $usage->getReasoningTokens() . "\n";
echo "Output tokens: " . $usage->getOutputTokens() . "\n";
echo "Total cost: $" . $usage->getTotalCost() . "\n";
```

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
$response = $chainClient->run(
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
2. **Set budgets for production** - Prevent runaway costs
3. **Use for appropriate tasks** - Don't use reasoning models for simple queries
4. **Monitor costs closely** - Track token usage and adjust budgets
5. **Test with o4-mini first** - More cost-effective for development

## Provider Support

- ✅ **OpenAI** - o3, o4-mini (native reasoning support)
- ❌ **Anthropic** - Not available (Claude uses different architecture)
- ❌ **Google Gemini** - Not available
- ⚠️ **OpenAI-compatible** - Depends on provider

## See Also

- [Configuration Guide](configuration.md) - All request configuration options
- [Feedback Loops](feedback-loops.md) - Validate reasoning outputs
- [OpenAI Provider Documentation](../providers/README.md) - OpenAI-specific features
