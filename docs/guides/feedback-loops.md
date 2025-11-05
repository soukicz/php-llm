# Feedback Loops

Build self-correcting AI agents with feedback loops. The `LLMAgentClient` allows you to validate agent responses and automatically request improvements until quality criteria are met. This is essential for building reliable agentic systems that produce consistent, validated outputs.

## Overview

Feedback loops enable your AI agents to:
1. Generate a response
2. Validate the output against your criteria
3. Request corrections if needed
4. Iterate until the response meets requirements

This creates agents that can self-correct and improve their outputs without manual intervention.

## Basic Feedback Loop

Define a callback function to validate responses:

```php
<?php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\LLMAgentClient;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;

require_once __DIR__ . '/vendor/autoload.php';

$cache = new FileCache(sys_get_temp_dir());
$anthropic = new AnthropicClient('sk-xxxxxx', $cache);
$chainClient = new LLMAgentClient();

$response = $chainClient->run(
    client: $anthropic,
    request: new LLMRequest(
        model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
        conversation: new LLMConversation([
            LLMMessage::createFromUserString('List 5 animals in JSON array and wrap this array in XML tag named "animals"')
        ]),
    ),
    feedbackCallback: function (LLMResponse $llmResponse): ?LLMMessage {
        // Validate the response
        if (preg_match('~<animals>(.+)</animals>~s', $llmResponse->getLastText(), $m)) {
            try {
                json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
                return null; // Valid response - stop iteration
            } catch (JsonException $e) {
                // Invalid JSON - request correction
                return LLMMessage::createFromUserString(
                    'I am sorry, but the response is not a valid JSON (' . $e->getMessage() . '). Please respond again.'
                );
            }
        }

        // Missing XML tag - request correction
        return LLMMessage::createFromUserString(
            'I am sorry, but I could not find animals tag in the response. Please respond again.'
        );
    }
);

echo $response->getLastText();
```

## Feedback Callback Return Values

The feedback callback should return:

- **`null`** - Response is valid, stop iteration
- **`LLMMessage`** - Response needs improvement, send this message back to the agent

## Nested LLM Validation

Use another LLM to validate complex responses:

```php
<?php
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude35Haiku;

$response = $chainClient->run(
    client: $anthropic,
    request: new LLMRequest(
        model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
        conversation: new LLMConversation([
            LLMMessage::createFromUserString('List all US states in JSON array and wrap this array in XML tag named "states"')
        ]),
    ),
    feedbackCallback: function (LLMResponse $llmResponse) use ($anthropic, $chainClient): ?LLMMessage {
        if (preg_match('~</states>(.+)~s', $llmResponse->getLastText(), $m)) {
            $suffix = trim(trim(trim($m[1]), '`'));
            if (empty($suffix)) {
                return null; // Complete
            }

            // Use a cheaper, faster model to validate
            $checkResponse = $chainClient->run(
                client: $anthropic,
                request: new LLMRequest(
                    model: new AnthropicClaude35Haiku(AnthropicClaude35Haiku::VERSION_20241022),
                    conversation: new LLMConversation([
                        LLMMessage::createFromUserString(<<<EOT
I need help with understanding of text. I have submitted work and I have received following text at the end of response:

<response-text>
$suffix
</response-text>

I need you to decide if this means that work was completed or if I should request continuation of work. Briefly explain what you see in response and finally output WORK_COMPLETED or WORK_NOT_COMPLETED. This is automated process and I need one of these two outputs.
EOT
                        ),
                    ]),
                )
            );

            if (str_contains($checkResponse->getLastText(), 'WORK_COMPLETED')) {
                return null; // Validated as complete
            }

            return LLMMessage::createFromUserString('Please continue');
        }

        return null;
    }
);

echo $response->getLastText();
```

## Common Validation Patterns

### Format Validation

```php
<?php
feedbackCallback: function (LLMResponse $response): ?LLMMessage {
    $text = $response->getLastText();

    // Check for JSON format
    if (!json_decode($text)) {
        return LLMMessage::createFromUserString('Please provide valid JSON');
    }

    return null;
}
```

### Content Requirements

```php
<?php
feedbackCallback: function (LLMResponse $response): ?LLMMessage {
    $text = $response->getLastText();

    // Ensure response contains required information
    if (!str_contains($text, 'conclusion')) {
        return LLMMessage::createFromUserString('Please include a conclusion section');
    }

    return null;
}
```

### Length Constraints

```php
<?php
feedbackCallback: function (LLMResponse $response): ?LLMMessage {
    $text = $response->getLastText();
    $wordCount = str_word_count($text);

    if ($wordCount < 100) {
        return LLMMessage::createFromUserString('Please provide a more detailed response (at least 100 words)');
    }

    if ($wordCount > 500) {
        return LLMMessage::createFromUserString('Please make the response more concise (max 500 words)');
    }

    return null;
}
```

### Schema Validation

```php
<?php
feedbackCallback: function (LLMResponse $response): ?LLMMessage {
    $data = json_decode($response->getLastText(), true);

    if (!isset($data['name']) || !isset($data['email'])) {
        return LLMMessage::createFromUserString('Response must include name and email fields');
    }

    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        return LLMMessage::createFromUserString('Please provide a valid email address');
    }

    return null;
}
```

## Preventing Infinite Loops

Always implement safeguards to prevent infinite loops:

### Iteration Counter

```php
<?php
$maxIterations = 5;
$iteration = 0;

feedbackCallback: function (LLMResponse $response) use (&$iteration, $maxIterations): ?LLMMessage {
    $iteration++;

    if ($iteration >= $maxIterations) {
        // Stop after max attempts
        return null;
    }

    // Your validation logic
    if (!isValid($response)) {
        return LLMMessage::createFromUserString('Please try again');
    }

    return null;
}
```

### Progressive Feedback

Provide more specific guidance with each iteration:

```php
<?php
$attempt = 0;

feedbackCallback: function (LLMResponse $response) use (&$attempt): ?LLMMessage {
    $attempt++;

    if (!isValid($response)) {
        if ($attempt === 1) {
            return LLMMessage::createFromUserString('The format is incorrect');
        } elseif ($attempt === 2) {
            return LLMMessage::createFromUserString('Remember to use JSON format with "name" and "age" fields');
        } else {
            return LLMMessage::createFromUserString('Example: {"name": "John", "age": 30}');
        }
    }

    return null;
}
```

## Combining with Other Features

### With Tools

Validate tool outputs in feedback loops:

```php
<?php
$response = $chainClient->run(
    client: $anthropic,
    request: new LLMRequest(
        model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
        conversation: $conversation,
        tools: [$calculatorTool],
    ),
    feedbackCallback: function (LLMResponse $response): ?LLMMessage {
        // Ensure the agent used the calculator tool
        if (!$response->hasToolCalls()) {
            return LLMMessage::createFromUserString('Please use the calculator tool for this calculation');
        }
        return null;
    }
);
```

### With Reasoning Models

Validate reasoning model outputs:

```php
<?php
use Soukicz\Llm\Client\OpenAI\Model\OpenAIGPTo3;
use Soukicz\Llm\Config\ReasoningEffort;

$response = $chainClient->run(
    client: $openai,
    request: new LLMRequest(
        model: new OpenAIGPTo3(),
        conversation: $conversation,
        reasoningEffort: ReasoningEffort::HIGH
    ),
    feedbackCallback: function (LLMResponse $response): ?LLMMessage {
        // Verify mathematical accuracy
        if (!verifyCalculation($response->getLastText())) {
            return LLMMessage::createFromUserString('The calculation appears incorrect. Please verify your work.');
        }
        return null;
    }
);
```

## Best Practices

1. **Always set iteration limits** - Prevent infinite loops
2. **Provide specific feedback** - Tell the agent exactly what's wrong
3. **Use cheaper models for validation** - Save costs by using fast models for checks
4. **Log validation failures** - Track when and why validation fails
5. **Progressive guidance** - Provide more detail with each failed attempt
6. **Early termination** - Return `null` as soon as criteria are met
7. **Validate incrementally** - Check simple criteria first, complex ones later

## Common Pitfalls

❌ **No iteration limit**
```php
<?php
// BAD: Could loop forever
feedbackCallback: function ($response) {
    return !isValid($response) ? LLMMessage::createFromUserString('Try again') : null;
}
```

✅ **With iteration limit**
```php
<?php
// GOOD: Maximum attempts enforced
$attempts = 0;
feedbackCallback: function ($response) use (&$attempts) {
    $attempts++;
    if ($attempts >= 5) return null;
    return !isValid($response) ? LLMMessage::createFromUserString('Try again') : null;
}
```

❌ **Vague feedback**
```php
<?php
// BAD: Agent doesn't know what's wrong
return LLMMessage::createFromUserString('Invalid response');
```

✅ **Specific feedback**
```php
<?php
// GOOD: Clear, actionable feedback
return LLMMessage::createFromUserString('The JSON is missing the required "email" field');
```

## See Also

- [Tools Guide](tools.md) - Validate tool usage in feedback loops
- [Reasoning Models](reasoning.md) - Combine reasoning with validation
- [Examples](../examples/index.md) - More feedback loop examples
- [Configuration](configuration.md) - Configure request behavior
