# Configuration Options

Configure your AI agent requests with various parameters to control behavior, output, and resource usage.

## LLMRequest Parameters

```php
<?php
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\Config\StructuredOutputConfig;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Stream\CallableStreamListener;

$request = new LLMRequest(
    model: $model,                              // Required: Model instance
    conversation: $conversation,                // Required: LLMConversation
    temperature: 0.7,                           // Optional: 0.0 to 1.0 (default 0.0)
    maxTokens: 4096,                            // Optional: Maximum response tokens (default 4096)
    tools: $tools,                              // Optional: Array of tool definitions
    stopSequences: ['###', 'END'],              // Optional: Stop generation strings
    reasoningConfig: ReasoningEffort::HIGH,     // Optional: ReasoningEffort or ReasoningBudget
    structuredOutputConfig: new StructuredOutputConfig($schema), // Optional: JSON Schema output
    streamListener: new CallableStreamListener( // Optional: Real-time progress updates
        fn($event) => print($event->delta)
    ),
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

- **0.0** - Deterministic, focused responses (the library default)
- **0.5** - Balanced
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

### streamListener

Attach a listener to receive real-time streaming updates. The response remains identical — streaming is a side-effect for progress display:

```php
<?php
use Soukicz\Llm\Stream\CallableStreamListener;
use Soukicz\Llm\Stream\StreamEvent;
use Soukicz\Llm\Stream\StreamEventType;

$request = new LLMRequest(
    model: $model,
    conversation: $conversation,
    streamListener: new CallableStreamListener(function (StreamEvent $event) {
        match ($event->type) {
            StreamEventType::TEXT_DELTA => print($event->delta),
            StreamEventType::TOOL_USE_START => print("\n[Tool: {$event->toolName}]\n"),
            default => null,
        };
    }),
);
```

Or implement `StreamListenerInterface` for a reusable class-based listener. See [Streaming Guide](streaming.md) for full documentation and practical examples.

**Note:** Streaming works with the response cache. On a cache hit, the cached response is replayed through the stream listener, and a completed live stream is stored in the cache for future requests.

### structuredOutputConfig

Force the model to return JSON matching a schema:

```php
<?php
use Soukicz\Llm\Config\StructuredOutputConfig;

$request = new LLMRequest(
    model: $model,
    conversation: $conversation,
    structuredOutputConfig: new StructuredOutputConfig([
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
        ],
        'required' => ['name'],
    ]),
);

$data = $agentClient->run($client, $request)->getLastStructuredData();
```

See [Structured Output Guide](structured-output.md) for full documentation.

## Reasoning Parameters

### reasoningConfig

The `reasoningConfig` parameter accepts either a `ReasoningEffort` enum case or a `ReasoningBudget` instance. When left at `null` (the default), the provider's default behavior is used.

Control computational effort with `ReasoningEffort` (works with Anthropic, OpenAI, and Gemini):

```php
<?php
use Soukicz\Llm\Client\OpenAI\Model\GPTo3;
use Soukicz\Llm\Config\ReasoningEffort;

$request = new LLMRequest(
    model: new GPTo3(GPTo3::VERSION_2025_04_16),
    conversation: $conversation,
    reasoningConfig: ReasoningEffort::HIGH
);
```

**Options:**
- `ReasoningEffort::NONE` - Disable reasoning
- `ReasoningEffort::MINIMAL` - Minimal reasoning
- `ReasoningEffort::LOW` - Fast, less thorough
- `ReasoningEffort::MEDIUM` - Balanced
- `ReasoningEffort::HIGH` - Thorough, slower
- `ReasoningEffort::EXTRA_HIGH` - Maximum effort

Or limit reasoning tokens for cost control using `ReasoningBudget` (**Anthropic only** — the OpenAI and Gemini encoders throw `InvalidArgumentException`):

```php
<?php
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Sonnet;
use Soukicz\Llm\Config\ReasoningBudget;

$request = new LLMRequest(
    model: new AnthropicClaude46Sonnet(),
    conversation: $conversation,
    reasoningConfig: new ReasoningBudget(5000)  // Max 5k thinking tokens
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

All clients accept a `customHttpMiddleware` parameter — a single Guzzle middleware callable that is pushed onto the client's internal handler stack. Use it for logging or custom behavior:

```php
<?php
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

$loggingMiddleware = function (callable $handler) {
    return function (RequestInterface $request, array $options) use ($handler) {
        return $handler($request, $options)->then(
            function (ResponseInterface $response) use ($request) {
                error_log($request->getMethod() . ' ' . $request->getUri() . ' - ' . $response->getStatusCode());

                return $response;
            }
        );
    };
};

$client = new AnthropicClient(
    apiKey: 'sk-xxxxx',
    cache: $cache,
    customHttpMiddleware: $loggingMiddleware
);
```

See [Logging & Debugging](../examples/logging-debugging.md) for a complete middleware example.

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
    baseUrl: 'https://openrouter.ai/api/v1'
);

$model = new LocalModel('anthropic/claude-haiku-4.5');
```

## Configuration Best Practices

1. **Use environment variables** for API keys
2. **Enable caching** for development to save costs
3. **Set reasonable maxTokens** to prevent runaway costs
4. **Use lower temperature** for factual tasks
5. **Use higher temperature** for creative tasks
6. **Set stopSequences** for structured outputs
7. **Configure safety settings** appropriately for your use case
8. **Limit reasoning costs in production** - `ReasoningBudget` on Anthropic, lower `ReasoningEffort` elsewhere

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
- [Structured Output](structured-output.md) - JSON Schema constrained responses
- [Streaming](streaming.md) - Real-time response streaming
- [Tools Guide](tools.md) - Tool configuration
- [Caching Guide](caching.md) - Cache configuration
- [Provider Documentation](../providers/README.md) - Provider-specific options
