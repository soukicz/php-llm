# Configuration Options

Configure your AI agent requests with various parameters to control behavior, output, and resource usage.

## LLMRequest Parameters

```php
<?php
use Soukicz\Llm\Config\ReasoningBudget;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\LLMRequest;

$request = new LLMRequest(
    model: $model,                              // Required: Model instance
    conversation: $conversation,                // Required: LLMConversation
    tools: $tools,                              // Optional: Array of tool definitions
    temperature: 0.7,                           // Optional: 0.0 to 1.0
    maxTokens: 4096,                            // Optional: Maximum response tokens
    stopSequences: ['###', 'END'],              // Optional: Stop generation strings
    reasoningConfig: ReasoningEffort::HIGH,     // Optional: For reasoning models
    reasoningConfig: new ReasoningBudget(10000),// Optional: Token budget for reasoning
);
```

## Core Parameters

### model (Required)

The LLM model to use. Create model instances from provider-specific classes:

```php
<?php
// Anthropic
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Opus;
$model = new AnthropicClaude46Opus();

use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
$model = new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929);

// OpenAI
use Soukicz\Llm\Client\OpenAI\Model\GPT5;
$model = new GPT5(GPT5::VERSION_2025_08_07);

// Gemini
use Soukicz\Llm\Client\Gemini\Model\Gemini25Pro;
$model = new Gemini25Pro();
```

### conversation (Required)

An `LLMConversation` containing message history:

```php
<?php
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\Message\LLMMessage;

$conversation = new LLMConversation([
    LLMMessage::createFromUserString('Hello'),
    LLMMessage::createFromAssistantString('Hi! How can I help?'),
    LLMMessage::createFromUserString('What is PHP?'),
]);
```

## Optional Parameters

### temperature

Controls randomness in responses (0.0 to 1.0):

- **0.0** - Deterministic, focused responses
- **0.5** - Balanced (default for most models)
- **1.0** - Creative, varied responses

```php
<?php
// Precise, factual responses
$request = new LLMRequest(
    model: $model,
    conversation: $conversation,
    temperature: 0.0
);

// Creative writing
$request = new LLMRequest(
    model: $model,
    conversation: $conversation,
    temperature: 0.9
);
```

### maxTokens

Maximum number of tokens in the response:

```php
<?php
$request = new LLMRequest(
    model: $model,
    conversation: $conversation,
    maxTokens: 1000  // Limit response to ~750 words
);
```

**Note:** Different providers have different maximum limits. Check provider documentation.

### stopSequences

Array of strings that stop generation when encountered:

```php
<?php
$request = new LLMRequest(
    model: $model,
    conversation: $conversation,
    stopSequences: ['###', 'END', '\n\n---']
);
```

Useful for:
- Structured output formats
- Limiting response sections
- Custom delimiters

### tools

Array of tool definitions for function calling:

```php
<?php
use Soukicz\Llm\Tool\CallbackToolDefinition;

$tools = [
    new CallbackToolDefinition(
        name: 'search',
        description: 'Search the web',
        inputSchema: ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
        handler: fn($input) => searchWeb($input['query'])
    ),
];

$request = new LLMRequest(
    model: $model,
    conversation: $conversation,
    tools: $tools
);
```

See [Tools Guide](tools.md) for detailed tool documentation.

## Reasoning Parameters

For reasoning models (o3, o4):

### reasoningEffort

Control computational effort:

```php
<?php
use Soukicz\Llm\Config\ReasoningEffort;

$request = new LLMRequest(
    model: new GPTo3(GPTo3::VERSION_2025_04_16),
    conversation: $conversation,
    reasoningConfig: ReasoningEffort::HIGH
);
```

**Options:**
- `ReasoningEffort::LOW` - Fast, less thorough
- `ReasoningEffort::MEDIUM` - Balanced (default)
- `ReasoningEffort::HIGH` - Thorough, slower

### reasoningConfig

Limit reasoning tokens for cost control using `ReasoningBudget`:

```php
<?php
use Soukicz\Llm\Config\ReasoningBudget;

$request = new LLMRequest(
    model: new GPTo3(GPTo3::VERSION_2025_04_16),
    conversation: $conversation,
    reasoningConfig: new ReasoningBudget(5000)  // Max 5k reasoning tokens
);
```

See [Reasoning Models Guide](reasoning.md) for more details.

## Client Configuration

### Cache Configuration

Configure caching when creating clients:

```php
<?php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;

$cache = new FileCache(sys_get_temp_dir());
$client = new AnthropicClient('sk-xxxxx', $cache);
```

See [Caching Guide](caching.md) for cache options.

### HTTP Middleware

Add Guzzle middleware for logging or custom behavior:

```php
<?php
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

$stack = HandlerStack::create();
$stack->push(Middleware::log($logger, $formatter));

$client = new AnthropicClient(
    apiKey: 'sk-xxxxx',
    cache: $cache,
    handler: $stack
);
```

## Provider-Specific Configuration

### Gemini Safety Settings

Configure content safety filters using the Gemini API safety settings format:

```php
<?php
use Soukicz\Llm\Client\Gemini\GeminiClient;

// Permissive - Allow most content
$permissiveSafetySettings = [
    ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
    ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
    ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
];

$client = new GeminiClient(
    apiKey: 'your-api-key',
    safetySettings: $permissiveSafetySettings
);

// Strict - Block harmful content
$strictSafetySettings = [
    ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
    ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
    ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
];

$client = new GeminiClient(
    apiKey: 'your-api-key',
    safetySettings: $strictSafetySettings
);
```

### OpenAI-Compatible Clients

Custom base URL and model names:

```php
<?php
use Soukicz\Llm\Client\OpenAI\OpenAICompatibleClient;
use Soukicz\Llm\Client\Universal\LocalModel;

$client = new OpenAICompatibleClient(
    apiKey: 'your-api-key',
    baseUrl: 'https://api.openrouter.ai/v1'
);

$model = new LocalModel('anthropic/claude-3.5-sonnet');
```

## Configuration Best Practices

1. **Use environment variables** for API keys
2. **Enable caching** for development to save costs
3. **Set reasonable maxTokens** to prevent runaway costs
4. **Use lower temperature** for factual tasks
5. **Use higher temperature** for creative tasks
6. **Set stopSequences** for structured outputs
7. **Configure safety settings** appropriately for your use case
8. **Use reasoning budgets** in production

## Example: Complete Configuration

```php
<?php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\LLMAgentClient;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

// Configure client with cache
$cache = new FileCache(sys_get_temp_dir());
$client = new AnthropicClient(
    apiKey: getenv('ANTHROPIC_API_KEY'),
    cache: $cache
);

$agentClient = new LLMAgentClient();

// Configure request
$request = new LLMRequest(
    model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
    conversation: new LLMConversation([
        LLMMessage::createFromUserString('Write a short poem about PHP')
    ]),
    temperature: 0.8,          // Creative
    maxTokens: 500,            // ~375 words max
    stopSequences: ['---'],    // Stop at delimiter
);

$response = $agentClient->run($client, $request);
```

## See Also

- [Reasoning Models](reasoning.md) - Reasoning-specific configuration
- [Tools Guide](tools.md) - Tool configuration
- [Caching Guide](caching.md) - Cache configuration
- [Provider Documentation](../providers/README.md) - Provider-specific options
