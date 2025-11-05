# Provider Usage Guide

This guide shows how to use each provider client in PHP LLM. All providers share the same `LLMRequest` API but have different client instantiation and configuration options.

## Client Interface Support

| Client | Implements LLMClient | Implements LLMBatchClient | Constructor Parameters |
|--------|---------------------|---------------------------|------------------------|
| `AnthropicClient` | ✅ | ✅ | `apiKey`, `cache`, `handler` |
| `OpenAIClient` | ✅ | ✅ | `apiKey`, `apiOrganization`, `cache`, `handler` |
| `GeminiClient` | ✅ | ❌ | `apiKey`, `cache`, `safetySettings`, `handler` |
| `OpenAICompatibleClient` | ✅ | Varies | `apiKey`, `baseUrl`, `cache`, `handler` |

## Anthropic (Claude)

### Client Instantiation

```php
<?php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;

$cache = new FileCache(sys_get_temp_dir());
$client = new AnthropicClient(
    apiKey: 'sk-ant-xxxxx',
    cache: $cache,           // Optional: CacheInterface
    handler: $handlerStack   // Optional: Guzzle HandlerStack for middleware
);
```

### Model Classes

All Anthropic models require a version constant:

```php
<?php
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude35Haiku;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude37Sonnet;

// Must specify version when instantiating
$model = new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929);
$model = new AnthropicClaude35Haiku(AnthropicClaude35Haiku::VERSION_20241022);
```

**Available model classes:**
- `AnthropicClaude35Sonnet`, `AnthropicClaude35Haiku`
- `AnthropicClaude37Sonnet`
- `AnthropicClaude4Sonnet`, `AnthropicClaude4Opus`
- `AnthropicClaude41Opus`
- `AnthropicClaude45Sonnet`

### Batch Processing Support

```php
<?php
use Soukicz\Llm\Client\LLMBatchClient;

// AnthropicClient implements LLMBatchClient
/** @var LLMBatchClient $client */
$batchId = $client->createBatch($requests);
$batch = $client->retrieveBatch($batchId);
```

## OpenAI (GPT)

### Client Instantiation

```php
<?php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\OpenAI\OpenAIClient;

$cache = new FileCache(sys_get_temp_dir());
$client = new OpenAIClient(
    apiKey: 'sk-xxxxx',
    apiOrganization: 'org-xxxxx',  // Optional: for organization accounts
    cache: $cache,                  // Optional: CacheInterface
    handler: $handlerStack          // Optional: Guzzle HandlerStack
);
```

### Model Classes

All OpenAI models require a version constant:

```php
<?php
use Soukicz\Llm\Client\OpenAI\Model\GPT5;
use Soukicz\Llm\Client\OpenAI\Model\GPT4o;
use Soukicz\Llm\Client\OpenAI\Model\GPTo3;
use Soukicz\Llm\Client\OpenAI\Model\GPTo4Mini;

// All models require version parameter
$model = new GPT5(GPT5::VERSION_2025_08_07);
$model = new GPT4o(GPT4o::VERSION_2024_11_20);
$model = new GPTo3(GPTo3::VERSION_2025_04_16);
$model = new GPTo4Mini(GPTo4Mini::VERSION_2025_04_16);
```

**Available model classes:**
- `GPT4o`, `GPT4oMini`
- `GPT41`, `GPT41Mini`, `GPT41Nano`
- `GPTo3`, `GPTo4Mini` (reasoning models)
- `GPT5`, `GPT5Mini`, `GPT5Nano`

**Note:** Each model class has version constants defined (e.g., `GPT5::VERSION_2025_08_07`). Check the class for available versions.

### Reasoning Model Configuration

Use `reasoningEffort` or `reasoningConfig` in `LLMRequest`:

```php
<?php
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\Config\ReasoningBudget;

$request = new LLMRequest(
    model: new GPTo3(GPTo3::VERSION_2025_04_16),
    conversation: $conversation,
    reasoningEffort: ReasoningEffort::HIGH  // or ReasoningEffort::LOW, ::MEDIUM
);

// Or use token budget
$request = new LLMRequest(
    model: new GPTo3(GPTo3::VERSION_2025_04_16),
    conversation: $conversation,
    reasoningConfig: new ReasoningBudget(10000)
);
```

### Batch Processing Support

```php
<?php
// OpenAIClient implements LLMBatchClient
$batchId = $client->createBatch($requests);
$batch = $client->retrieveBatch($batchId);
```

## Google Gemini

### Client Instantiation

```php
<?php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Gemini\GeminiClient;

$cache = new FileCache(sys_get_temp_dir());
$client = new GeminiClient(
    apiKey: 'your-api-key',
    cache: $cache,              // Optional: CacheInterface
    safetySettings: [],         // Optional: array of safety settings (see below)
    handler: $handlerStack      // Optional: Guzzle HandlerStack
);
```

### Model Classes

```php
<?php
use Soukicz\Llm\Client\Gemini\Model\Gemini25Pro;
use Soukicz\Llm\Client\Gemini\Model\Gemini25Flash;
use Soukicz\Llm\Client\Gemini\Model\Gemini20Flash;

// Models don't require version parameters
$model = new Gemini25Pro();
$model = new Gemini25Flash();
```

**Available model classes:**
- `Gemini20Flash`, `Gemini20FlashLite`
- `Gemini25Flash`, `Gemini25FlashLite`
- `Gemini25FlashImagePreview`
- `Gemini25Pro`, `Gemini25ProPreview`

### Safety Settings Configuration

GeminiClient accepts safety settings as an array in the constructor:

```php
<?php
// Permissive settings (minimal blocking)
$permissiveSettings = [
    ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
    ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
    ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
];

// Strict settings (block more content)
$strictSettings = [
    ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
    ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
    ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
];

$client = new GeminiClient(
    apiKey: 'your-api-key',
    safetySettings: $permissiveSettings
);
```

**Available thresholds:**
- `BLOCK_NONE` - No blocking
- `BLOCK_ONLY_HIGH` - Block high severity only
- `BLOCK_MEDIUM_AND_ABOVE` - Block medium and high
- `BLOCK_LOW_AND_ABOVE` - Block low, medium, and high

### Batch Processing

⚠️ GeminiClient does **not** implement `LLMBatchClient`. Batch processing is not supported for Gemini through this library.

### Limitations

- **No PDF support** - Gemini models don't accept PDF inputs through this library
- **No batch processing** - Must process requests individually

## OpenAI-Compatible Providers

### Client Instantiation

Use `OpenAICompatibleClient` for any service that implements the OpenAI API specification:

```php
<?php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\OpenAI\OpenAICompatibleClient;

$cache = new FileCache(sys_get_temp_dir());
$client = new OpenAICompatibleClient(
    apiKey: 'your-api-key',
    baseUrl: 'https://api.provider.com/v1',  // Required: API endpoint
    cache: $cache,                            // Optional: CacheInterface
    handler: $handlerStack                    // Optional: Guzzle HandlerStack
);
```

### Model Usage

Use `LocalModel` to specify any model name as a string:

```php
<?php
use Soukicz\Llm\Client\Universal\LocalModel;

// Model name as provided by the API service
$model = new LocalModel('model-name');

// The model name is passed directly to the API
$request = new LLMRequest(
    model: new LocalModel('llama-3.2-8b'),
    conversation: $conversation
);
```

### Provider-Specific Examples

#### OpenRouter

```php
<?php
$client = new OpenAICompatibleClient(
    apiKey: 'sk-or-v1-xxxxx',
    baseUrl: 'https://openrouter.ai/api/v1'
);

// Use OpenRouter's model naming format
$model = new LocalModel('anthropic/claude-3.5-sonnet');
$model = new LocalModel('openai/gpt-4o');
$model = new LocalModel('meta-llama/llama-3.2-8b-instruct');
```

#### Ollama (Local)

```php
<?php
$client = new OpenAICompatibleClient(
    apiKey: 'not-needed',  // Ollama doesn't require API key
    baseUrl: 'http://localhost:11434/v1'
);

// Use Ollama model names
$model = new LocalModel('llama3.2');
$model = new LocalModel('mistral');
```

#### llama-server (Local)

```php
<?php
$client = new OpenAICompatibleClient(
    apiKey: 'not-needed',
    baseUrl: 'http://localhost:8080/v1'
);

$model = new LocalModel('local-model');
```

### Feature Support

Feature availability depends entirely on the underlying provider/model:
- **Function calling**: Check if provider supports tools
- **Multimodal**: Check if model accepts images/PDFs
- **Batch processing**: Most compatible APIs don't support batching
- **Reasoning**: Only if provider offers reasoning models

## AWS Bedrock

AWS Bedrock support is available through a separate extension package:

```bash
composer require soukicz/llm-aws-bedrock
```

This package provides a `BedrockClient` that implements the same `LLMClient` interface. See the [extension documentation](https://github.com/soukicz/llm-aws-bedrock) for setup and usage details.

## Using Multiple Providers

You can use multiple providers in the same application by instantiating different clients:

```php
<?php
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\OpenAI\OpenAIClient;
use Soukicz\Llm\Client\Gemini\GeminiClient;

// Instantiate all providers
$anthropic = new AnthropicClient('sk-ant-xxxxx', $cache);
$openai = new OpenAIClient('sk-xxxxx', 'org-xxxxx', $cache);
$gemini = new GeminiClient('gemini-key', $cache);

// Use the same LLMAgentClient with any provider
$agentClient = new LLMAgentClient();

// Switch between providers by changing the client
$response = $agentClient->run($anthropic, $request);
$response = $agentClient->run($openai, $request);
$response = $agentClient->run($gemini, $request);
```

## Common Configuration

### Caching

All clients support HTTP-level caching through the `CacheInterface`:

```php
<?php
use Soukicz\Llm\Cache\FileCache;

$cache = new FileCache('/path/to/cache/dir');

// Pass the same cache instance to all clients
$anthropic = new AnthropicClient('key', $cache);
$openai = new OpenAIClient('key', null, $cache);
$gemini = new GeminiClient('key', $cache);
```

### Guzzle Middleware

All clients accept a Guzzle `HandlerStack` for custom middleware (logging, retries, etc.):

```php
<?php
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

$stack = HandlerStack::create();
$stack->push(Middleware::log($logger, $messageFormatter));

$client = new AnthropicClient(
    apiKey: 'key',
    cache: $cache,
    handler: $stack
);
```

### Environment Variables

Store API keys in environment variables:

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
```

## See Also

- [Configuration Guide](../guides/configuration.md) - Request configuration options
- [Multimodal Guide](../guides/multimodal.md) - Using images and PDFs
- [Reasoning Guide](../guides/reasoning.md) - OpenAI reasoning models
- [Batch Processing Guide](../guides/batch-processing.md) - Anthropic and OpenAI batch APIs
