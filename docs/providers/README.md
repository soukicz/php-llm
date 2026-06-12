# Provider Usage Guide

This guide shows how to use each provider client in PHP LLM. All providers share the same `LLMRequest` API but have different client instantiation and configuration options.

## Client Interface Support

| Client | Implements LLMClient | Implements LLMBatchClient | Constructor Parameters |
|--------|---------------------|---------------------------|------------------------|
| `AnthropicClient` | ✅ | ✅ | `apiKey`, `cache`, `customHttpMiddleware`, `betaFeatures` |
| `OpenAIClient` | ✅ | ✅ | `apiKey`, `apiOrganization`, `cache`, `customHttpMiddleware` |
| `GeminiClient` | ✅ | ❌ | `apiKey`, `cache`, `customHttpMiddleware`, `safetySettings` |
| `OpenAICompatibleClient` | ✅ | ✅ (if the endpoint supports it) | `apiKey`, `baseUrl`, `cache`, `customHttpMiddleware` |

Structured output (`StructuredOutputConfig`) is supported by all three native providers (Anthropic, OpenAI, Gemini).

## Anthropic (Claude)

### Client Instantiation

```php
<?php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;

$cache = new FileCache(sys_get_temp_dir());
$client = new AnthropicClient(
    apiKey: 'sk-ant-xxxxx',
    cache: $cache,                         // Optional: CacheInterface
    customHttpMiddleware: $middleware,     // Optional: a single Guzzle middleware callable
    betaFeatures: ['context-1m-2025-08-07'] // Optional: Anthropic beta feature flags
);
```

### Model Classes

Most Anthropic models require a version constant; the newest models take no constructor arguments:

```php
<?php
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude35Haiku;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Opus;

// Versioned models require a version constant
$model = new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929);
$model = new AnthropicClaude35Haiku(AnthropicClaude35Haiku::VERSION_20241022);

// Claude 4.6 models take no arguments
$model = new AnthropicClaude46Sonnet();
$model = new AnthropicClaude46Opus();
```

**Available model classes:**

| Class | Constructor |
|-------|-------------|
| `AnthropicClaude35Sonnet` | `new AnthropicClaude35Sonnet(AnthropicClaude35Sonnet::VERSION_20241022)` |
| `AnthropicClaude35Haiku` | `new AnthropicClaude35Haiku(AnthropicClaude35Haiku::VERSION_20241022)` |
| `AnthropicClaude37Sonnet` | `new AnthropicClaude37Sonnet(AnthropicClaude37Sonnet::VERSION_20250219)` |
| `AnthropicClaude4Sonnet` | `new AnthropicClaude4Sonnet(AnthropicClaude4Sonnet::VERSION_20250514)` |
| `AnthropicClaude4Opus` | `new AnthropicClaude4Opus(AnthropicClaude4Opus::VERSION_20250514)` |
| `AnthropicClaude41Opus` | `new AnthropicClaude41Opus(AnthropicClaude41Opus::VERSION_20250805)` |
| `AnthropicClaude45Sonnet` | `new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929)` |
| `AnthropicClaude45Opus` | `new AnthropicClaude45Opus(AnthropicClaude45Opus::VERSION_20251101)` |
| `AnthropicClaude45Haiku` | `new AnthropicClaude45Haiku(AnthropicClaude45Haiku::VERSION_20251001)` |
| `AnthropicClaude46Sonnet` | `new AnthropicClaude46Sonnet()` |
| `AnthropicClaude46Opus` | `new AnthropicClaude46Opus()` |

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
    apiOrganization: 'org-xxxxx',       // Required positional argument (nullable) - pass null if you have none
    cache: $cache,                      // Optional: CacheInterface
    customHttpMiddleware: $middleware   // Optional: a single Guzzle middleware callable
);

// Without an organization, pass null explicitly:
$client = new OpenAIClient('sk-xxxxx', null, $cache);
```

### Model Classes

All OpenAI models require a version constant:

```php
<?php
use Soukicz\Llm\Client\OpenAI\Model\GPT54;
use Soukicz\Llm\Client\OpenAI\Model\GPT52;
use Soukicz\Llm\Client\OpenAI\Model\GPT5;
use Soukicz\Llm\Client\OpenAI\Model\GPTo3;

// All models require version parameter
$model = new GPT54(GPT54::VERSION_2026_03_05);
$model = new GPT52(GPT52::VERSION_2025_12_11);
$model = new GPT5(GPT5::VERSION_2025_08_07);
$model = new GPTo3(GPTo3::VERSION_2025_04_16);
```

**Available model classes:**

| Class | Constructor |
|-------|-------------|
| `GPT4o` | `new GPT4o(GPT4o::VERSION_2024_11_20)` |
| `GPT4oMini` | `new GPT4oMini(GPT4oMini::VERSION_2024_07_18)` |
| `GPT41` | `new GPT41(GPT41::VERSION_2025_04_14)` |
| `GPT41Mini` | `new GPT41Mini(GPT41Mini::VERSION_2025_04_14)` |
| `GPT41Nano` | `new GPT41Nano(GPT41Nano::VERSION_2025_04_14)` |
| `GPTo3` | `new GPTo3(GPTo3::VERSION_2025_04_16)` |
| `GPTo4Mini` | `new GPTo4Mini(GPTo4Mini::VERSION_2025_04_16)` |
| `GPT5` | `new GPT5(GPT5::VERSION_2025_08_07)` |
| `GPT5Mini` | `new GPT5Mini(GPT5Mini::VERSION_2025_08_07)` |
| `GPT5Nano` | `new GPT5Nano(GPT5Nano::VERSION_2025_08_07)` |
| `GPT52` | `new GPT52(GPT52::VERSION_2025_12_11)` |
| `GPT54` | `new GPT54(GPT54::VERSION_2026_03_05)` |
| `GPT54Mini` | `new GPT54Mini(GPT54Mini::VERSION_2026_03_17)` |
| `GPT54Nano` | `new GPT54Nano(GPT54Nano::VERSION_2026_03_17)` |

**Note:** Each model class has version constants defined (e.g., `GPT5::VERSION_2025_08_07`). Check the class for available versions.

### Reasoning Configuration

Use the `reasoningConfig` parameter of `LLMRequest`. The `ReasoningEffort` enum (`NONE`, `MINIMAL`, `LOW`, `MEDIUM`, `HIGH`, `EXTRA_HIGH`) works on OpenAI, Anthropic, and Gemini:

```php
<?php
use Soukicz\Llm\Config\ReasoningEffort;

$request = new LLMRequest(
    model: new GPTo3(GPTo3::VERSION_2025_04_16),
    conversation: $conversation,
    reasoningConfig: ReasoningEffort::HIGH  // or ::LOW, ::MEDIUM, ...
);
```

A token-based `ReasoningBudget` is supported by **Anthropic only** (OpenAI and Gemini throw an exception):

```php
<?php
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Sonnet;
use Soukicz\Llm\Config\ReasoningBudget;

$request = new LLMRequest(
    model: new AnthropicClaude46Sonnet(),
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
    cache: $cache,                        // Optional: CacheInterface
    customHttpMiddleware: $middleware,    // Optional: a single Guzzle middleware callable
    safetySettings: []                    // Optional: array of safety settings (see below)
);
```

### Model Classes

```php
<?php
use Soukicz\Llm\Client\Gemini\Model\Gemini25Pro;
use Soukicz\Llm\Client\Gemini\Model\Gemini25Flash;
use Soukicz\Llm\Client\Gemini\Model\Gemini3ProPreview;

// Most models don't require constructor arguments
$model = new Gemini25Pro();
$model = new Gemini25Flash();
$model = new Gemini3ProPreview();
```

**Available model classes:**

| Class | Constructor |
|-------|-------------|
| `Gemini20Flash` | `new Gemini20Flash()` |
| `Gemini20FlashLite` | `new Gemini20FlashLite()` |
| `Gemini25Flash` | `new Gemini25Flash()` |
| `Gemini25FlashLite` | `new Gemini25FlashLite()` |
| `Gemini25Pro` | `new Gemini25Pro()` |
| `Gemini25ProPreview` | `new Gemini25ProPreview(Gemini25ProPreview::VERSION_03_25)` |
| `Gemini3ProPreview` | `new Gemini3ProPreview()` |
| `Gemini25FlashImage` | `new Gemini25FlashImage()` — [image generation](https://ai.google.dev/gemini-api/docs/image-generation) |
| `Gemini25FlashImagePreview` | `new Gemini25FlashImagePreview()` — [image generation](https://ai.google.dev/gemini-api/docs/image-generation) |
| `Gemini3ProImagePreview` | `new Gemini3ProImagePreview()` — [image generation](https://ai.google.dev/gemini-api/docs/image-generation) |
| `Gemini31FlashImagePreview` | `new Gemini31FlashImagePreview()` — [image generation](https://ai.google.dev/gemini-api/docs/image-generation) |

Image generation models accept optional constructor arguments for output control, e.g. `new Gemini25FlashImage(imageAspectRatio: '16:9', imageSize: '2K')`.

Gemini models accept PDF inputs (sent as inline `application/pdf` data) and image inputs through the same `LLMRequest` API as the other providers.

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
    customHttpMiddleware: $middleware         // Optional: a single Guzzle middleware callable
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
$model = new LocalModel('anthropic/claude-haiku-4.5');
$model = new LocalModel('openai/gpt-5.2');
$model = new LocalModel('meta-llama/llama-3.3-70b-instruct');
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
- **Batch processing**: `OpenAICompatibleClient` implements `LLMBatchClient`, but it only works if the endpoint provides the OpenAI batch API
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

All clients accept a single Guzzle middleware callable via `customHttpMiddleware` (it is pushed onto the client's internal `HandlerStack`). Use this for logging, custom retries, etc.:

```php
<?php
use GuzzleHttp\Middleware;

$middleware = Middleware::log($logger, $messageFormatter);

$client = new AnthropicClient(
    apiKey: 'key',
    cache: $cache,
    customHttpMiddleware: $middleware
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
    apiOrganization: getenv('OPENAI_ORG_ID') ?: null,
    cache: $cache
);
```

## See Also

- [Configuration Guide](../guides/configuration.md) - Request configuration options
- [Multimodal Guide](../guides/multimodal.md) - Using images and PDFs
- [Reasoning Guide](../guides/reasoning.md) - Reasoning configuration for OpenAI, Anthropic, and Gemini
- [Batch Processing Guide](../guides/batch-processing.md) - Anthropic and OpenAI batch APIs
