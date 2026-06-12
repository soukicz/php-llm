# Structured Output

Force the model to respond with JSON matching a schema you define. Instead of parsing free-form text and hoping for the best, structured output guarantees machine-readable responses — ideal for data extraction, classification, and any workflow where the LLM response feeds directly into your application logic.

## Overview

Structured output works the same way across all three providers:

1. Define a JSON Schema describing the response shape
2. Pass it to `LLMRequest` via the `structuredOutputConfig` parameter
3. Read the decoded result with `$response->getLastStructuredData()`

The library translates your schema to each provider's native structured-output mechanism, so the same request code works with Anthropic, OpenAI, and Gemini.

## Basic Usage

Create a `StructuredOutputConfig` with a raw JSON Schema array:

```php
<?php
use Soukicz\Llm\Config\StructuredOutputConfig;
use Soukicz\Llm\LLMRequest;

$schema = [
    'type' => 'object',
    'properties' => [
        'name' => ['type' => 'string'],
        'email' => ['type' => 'string'],
    ],
    'required' => ['name', 'email'],
];

$request = new LLMRequest(
    model: $model,
    conversation: $conversation,
    structuredOutputConfig: new StructuredOutputConfig($schema),
);
```

## Reading the Result

When a request has a `structuredOutputConfig`, the response text is parsed as JSON and stored as structured data. Read it with `getLastStructuredData()`, which returns the decoded array:

```php
<?php
$response = $agentClient->run($client, $request);

$data = $response->getLastStructuredData();
echo $data['name'];   // "Jane"
echo $data['email'];  // "jane@example.com"
```

**Note:** With structured output enabled, the assistant message contains structured data instead of plain text — `getLastText()` will throw a `RuntimeException`. Use `getLastStructuredData()` instead.

The raw JSON string is preserved internally, so structured responses round-trip correctly when you continue the conversation in follow-up requests.

## Strict Mode

`StructuredOutputConfig` takes an optional second parameter:

```php
<?php
new StructuredOutputConfig($schema, strict: true);  // default
new StructuredOutputConfig($schema, strict: false); // permissive
```

**`strict: true` (default)** — The schema is enforced exactly. On OpenAI this enables the provider-side strict schema mode (`"strict": true` in `response_format`), which constrains generation so the output is guaranteed to match the schema: all required fields present, no extra properties, correct types.

**`strict: false`** — Permissive mode. The model is guided by the schema but the provider does not hard-enforce it, which can help when a schema uses features the strict mode rejects. The strict flag is currently forwarded to OpenAI; Anthropic and Gemini requests are encoded the same way regardless of the flag.

### Schema Normalization

Each encoder automatically adjusts your schema to what the provider accepts:

- **Anthropic and OpenAI** require `"additionalProperties": false` on every object in strict mode — the library adds it recursively wherever you didn't specify it.
- **Anthropic** strict mode does not support the constraints `minItems`, `maxItems`, `minimum`, `maximum`, `minLength`, `maxLength`, and `pattern`. The library removes them and appends them to the property `description` so the model still sees them as guidance.
- **Gemini** does not support `additionalProperties` at all — the library strips it recursively before sending the schema.

You can write one portable schema and let the encoders handle the differences.

## Complete Example

Extract structured contact data from free-form text:

```php
<?php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Sonnet;
use Soukicz\Llm\Client\LLMAgentClient;
use Soukicz\Llm\Config\StructuredOutputConfig;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

require_once __DIR__ . '/vendor/autoload.php';

$cache = new FileCache(sys_get_temp_dir());
$anthropic = new AnthropicClient(getenv('ANTHROPIC_API_KEY'), $cache);
$agentClient = new LLMAgentClient();

$schema = [
    'type' => 'object',
    'properties' => [
        'name' => ['type' => 'string', 'description' => 'Full name of the person'],
        'email' => ['type' => 'string', 'description' => 'Email address'],
        'phone' => ['type' => ['string', 'null'], 'description' => 'Phone number, null if not mentioned'],
        'topics' => [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'description' => 'Topics the person wants to discuss',
        ],
    ],
    'required' => ['name', 'email', 'phone', 'topics'],
];

$response = $agentClient->run(
    client: $anthropic,
    request: new LLMRequest(
        model: new AnthropicClaude46Sonnet(),
        conversation: new LLMConversation([
            LLMMessage::createFromUserString(
                'Extract the contact information from this email: ' .
                '"Hi, this is Jane Novak (jane.novak@example.com). ' .
                'I would like to talk about pricing and the API integration next week."'
            )
        ]),
        structuredOutputConfig: new StructuredOutputConfig($schema),
    )
);

$contact = $response->getLastStructuredData();

echo $contact['name'] . "\n";          // Jane Novak
echo $contact['email'] . "\n";         // jane.novak@example.com
var_dump($contact['phone']);           // NULL
print_r($contact['topics']);           // ['pricing', 'API integration']
```

## Combining with Other Features

### With Tools

Structured output and tools can be combined in a single request with `LLMAgentClient`. The agent runs the tool loop as usual, and the final response is constrained to your schema:

```php
<?php
$response = $agentClient->run(
    client: $anthropic,
    request: new LLMRequest(
        model: new AnthropicClaude46Sonnet(),
        conversation: $conversation,
        tools: [$currencyTool],
        structuredOutputConfig: new StructuredOutputConfig($schema),
    )
);

$data = $response->getLastStructuredData();
```

### With Reasoning

Structured output also works alongside reasoning configuration — for example Anthropic encodes both into the same `output_config`:

```php
<?php
use Soukicz\Llm\Config\ReasoningEffort;

$request = new LLMRequest(
    model: new AnthropicClaude46Sonnet(),
    conversation: $conversation,
    reasoningConfig: ReasoningEffort::HIGH,
    structuredOutputConfig: new StructuredOutputConfig($schema),
);
```

## Provider Support

| Provider | Mechanism |
|---|---|
| ✅ **Anthropic** | `output_config` with a `json_schema` format |
| ✅ **OpenAI** | `response_format` of type `json_schema` (with `strict` flag) |
| ✅ **Google Gemini** | `responseMimeType: application/json` + `responseSchema` (`additionalProperties` is stripped) |
| ⚠️ **OpenAI-compatible** | Uses the OpenAI encoding; depends on the underlying provider/model |

## Best Practices

1. **Mark fields as required** - Combined with strict mode this guarantees field presence
2. **Use descriptions** - Property descriptions guide the model just like prompt text
3. **Allow null where data may be missing** - Use `'type' => ['string', 'null']` instead of omitting fields
4. **Keep schemas flat where possible** - Deeply nested schemas are harder for models to fill correctly
5. **Use `getLastStructuredData()`** - Don't parse `getLastText()`; it throws for structured responses
6. **Prefer structured output over prompt-engineered JSON** - It removes the need for "respond only with JSON" instructions and feedback-loop re-parsing

## See Also

- [Configuration Guide](configuration.md) - All `LLMRequest` parameters
- [Tools Guide](tools.md) - Combine structured output with function calling
- [Feedback Loops](feedback-loops.md) - Validate response content beyond schema shape
- [Reasoning Models](reasoning.md) - Combine structured output with reasoning
