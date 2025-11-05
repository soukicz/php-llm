# Quick Start Examples

Get started with PHP LLM in minutes with these basic examples.

## Installation

```bash
composer require soukicz/llm
```

## Simple Synchronous Request

The most basic way to interact with an LLM. This pattern is perfect for simple one-off requests where you don't need conversation history.

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\LLMAgentClient;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;

// Set up caching to avoid redundant API calls
$cache = new FileCache(sys_get_temp_dir());

// Initialize the Anthropic client with your API key
$anthropic = new AnthropicClient('sk-xxxxx', $cache);

// The chain client handles the request/response cycle
$agentClient = new LLMAgentClient();

// Make a synchronous request
$response = $agentClient->run(
    client: $anthropic,
    request: new LLMRequest(
        model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
        conversation: new LLMConversation([
            LLMMessage::createFromUserString('What is PHP?')
        ]),
    )
);

// Get the AI's response text
echo $response->getLastText();
```

## Async Request

Use asynchronous requests when you need to make multiple LLM calls concurrently or when you want non-blocking execution. This uses promises under the hood.

```php
<?php
use Soukicz\Llm\LLMResponse;

// Start an async request (doesn't block)
$promise = $agentClient->runAsync(
    client: $anthropic,
    request: new LLMRequest(
        model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
        conversation: new LLMConversation([
            LLMMessage::createFromUserString('Explain async programming')
        ]),
    )
);

// Handle the response when it arrives
$promise->then(function (LLMResponse $response) {
    echo $response->getLastText();
});

// You can do other work here while waiting for the response
// Or make multiple async requests and wait for all to complete
```

## Multi-Turn Conversation

This library uses **immutable objects** - methods like `withMessage()` return a new instance rather than modifying the original. This prevents accidental state mutations and makes your code more predictable.

```php
<?php
$conversation = new LLMConversation([
    LLMMessage::createFromUserString('What is 2 + 2?'),
]);

// First turn
$response = $agentClient->run(
    client: $anthropic,
    request: new LLMRequest(
        model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
        conversation: $conversation,
    )
);

echo "AI: " . $response->getLastText() . "\n"; // "4"

// Add AI response to conversation (returns new instance)
$conversation = $conversation->withMessage($response->getLastMessage());

// Add user's follow-up question (returns new instance)
$conversation = $conversation->withMessage(
    LLMMessage::createFromUserString('What about 2 * 2?')
);

// Second turn
$response = $agentClient->run(
    client: $anthropic,
    request: new LLMRequest(
        model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
        conversation: $conversation,
    )
);

echo "AI: " . $response->getLastText() . "\n"; // "4"
```

## Different Providers

PHP LLM provides a unified interface across multiple LLM providers. Simply swap the client and model - the rest of your code stays the same.

### OpenAI

```php
<?php
use Soukicz\Llm\Client\OpenAI\OpenAIClient;
use Soukicz\Llm\Client\OpenAI\Model\GPT5;

$openai = new OpenAIClient('sk-xxxxx', 'org-xxxxx', $cache);

$response = $agentClient->run(
    client: $openai,
    request: new LLMRequest(
        model: new GPT5(GPT5::VERSION_2025_08_07),
        conversation: $conversation,
    )
);
```

### Google Gemini

```php
<?php
use Soukicz\Llm\Client\Gemini\GeminiClient;
use Soukicz\Llm\Client\Gemini\Model\Gemini25Pro;

$gemini = new GeminiClient('your-api-key', $cache);

$response = $agentClient->run(
    client: $gemini,
    request: new LLMRequest(
        model: new Gemini25Pro(),
        conversation: $conversation,
    )
);
```

### OpenRouter

```php
<?php
use Soukicz\Llm\Client\OpenAI\OpenAICompatibleClient;
use Soukicz\Llm\Client\Universal\LocalModel;

$client = new OpenAICompatibleClient(
    apiKey: 'sk-or-v1-xxxxx',
    baseUrl: 'https://openrouter.ai/api/v1',
);

$response = $agentClient->run(
    client: $client,
    request: new LLMRequest(
        model: new LocalModel('anthropic/claude-3.5-sonnet'),
        conversation: $conversation,
    )
);
```

## Using Environment Variables

```php
<?php
$anthropic = new AnthropicClient(
    apiKey: getenv('ANTHROPIC_API_KEY'),
    cache: $cache
);

$openai = new OpenAIClient(
    apiKey: getenv('OPENAI_API_KEY'),
    apiOrganization: getenv('OPENAI_ORG_ID'),
    cache: $cache
);

$gemini = new GeminiClient(
    apiKey: getenv('GEMINI_API_KEY'),
    cache: $cache
);
```

## Error Handling

All client operations can throw `LLMClientException` for API errors, network failures, and invalid responses. Always wrap your LLM calls in try-catch blocks for production code.

```php
<?php
use Soukicz\Llm\Client\LLMClientException;

try {
    $response = $agentClient->run($client, $request);
    echo $response->getLastText();
} catch (LLMClientException $e) {
    echo "Error: " . $e->getMessage();
    // Handle error: log, retry, fallback, etc.
}
```

## Tracking Costs

Every response includes token usage and cost information. This helps you monitor API expenses and optimize your prompts.

```php
<?php
$response = $agentClient->run($client, $request);

// Token counts
echo "Input tokens: " . $response->getInputTokens() . "\n";
echo "Output tokens: " . $response->getOutputTokens() . "\n";
echo "Total tokens: " . ($response->getInputTokens() + $response->getOutputTokens()) . "\n";

// Cost breakdown (in USD, null if pricing unavailable)
$inputCost = $response->getInputPriceUsd() ?? 0;
$outputCost = $response->getOutputPriceUsd() ?? 0;
$totalCost = $inputCost + $outputCost;

echo "Input cost: $" . number_format($inputCost, 6) . "\n";
echo "Output cost: $" . number_format($outputCost, 6) . "\n";
echo "Total cost: $" . number_format($totalCost, 6) . "\n";

// Performance metrics
echo "Response time: " . $response->getTotalTimeMs() . "ms\n";
```

## Next Steps

- [Tools Guide](../guides/tools.md) - Add function calling to your agents
- [Multimodal](../guides/multimodal.md) - Process images and PDFs
- [Feedback Loops](../guides/feedback-loops.md) - Build self-correcting agents
- [Configuration](../guides/configuration.md) - Advanced configuration options
