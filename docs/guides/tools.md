# Tools & Function Calling

Tools (also known as function calling) empower your AI agents to interact with external systems, APIs, and data sources. This is a core capability for building agentic systems that can take actions beyond generating text.

## Overview

When you provide tools to an AI agent, the model can:
1. Decide when a tool is needed to answer a query
2. Select the appropriate tool
3. Generate the correct input parameters
4. Receive the tool's output
5. Incorporate the results into its response

## Defining Tools

Use `CallbackToolDefinition` to define tools with callback functions:

```php
<?php
use Soukicz\Llm\Tool\CallbackToolDefinition;

$tool = new CallbackToolDefinition(
    name: 'tool_name',
    description: 'Clear description of what the tool does and when to use it',
    inputSchema: [
        'type' => 'object',
        'properties' => [
            'param1' => ['type' => 'string', 'description' => 'Parameter description'],
            'param2' => ['type' => 'number', 'description' => 'Another parameter'],
        ],
        'required' => ['param1'],
    ],
    handler: function (array $input) {
        // Your tool logic here
        return $result;
    }
);
```

## Complete Example: Currency Converter Agent

```php
<?php
use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\LLMChainClient;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Tool\CallbackToolDefinition;

require_once __DIR__ . '/vendor/autoload.php';

$cache = new FileCache(sys_get_temp_dir());
$anthropic = new AnthropicClient('sk-xxxxxx', $cache);
$chainClient = new LLMChainClient();

$currencyTool = new CallbackToolDefinition(
    name: 'currency_rates',
    description: 'Tool for getting current currency rates. Required input is currency code of source currency and currency code of target currency.',
    inputSchema: [
        'type' => 'object',
        'properties' => [
            'source_currency' => ['type' => 'string'],
            'target_currency' => ['type' => 'string'],
        ],
        'required' => ['source_currency', 'target_currency'],
    ],
    handler: function (array $input): PromiseInterface {
        $client = new Client();

        // Tool can return either a promise or a value
        return $client->getAsync('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/' . strtolower($input['source_currency']) . '.json')
            ->then(function (Response $response) use ($input) {
                $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

                return LLMMessageContents::fromArrayData([
                    'rate' => $data[strtolower($input['source_currency'])][strtolower($input['target_currency'])],
                ]);
            });
    }
);

$response = $chainClient->run(
    client: $anthropic,
    request: new LLMRequest(
        model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
        conversation: new LLMConversation([
            LLMMessage::createFromUserString('How much is 100 USD in EUR today?')
        ]),
        tools: [$currencyTool],
    )
);

echo $response->getLastText();
```

## Tool Handler Return Types

Tool handlers must return either:

1. **LLMMessageContents** - Structured response data (recommended)
2. **Promises** - For async operations (recommended for API calls)

```php
<?php
// LLMMessageContents (recommended for synchronous operations)
handler: function (array $input): LLMMessageContents {
    return LLMMessageContents::fromArrayData(['key' => 'value']);
}

// Promise (recommended for async operations like API calls)
handler: function (array $input): PromiseInterface {
    return $httpClient->getAsync($url)
        ->then(function ($response) {
            $data = json_decode($response->getBody(), true);
            return LLMMessageContents::fromArrayData($data);
        });
}
```

**Note:** Tool handlers cannot return plain arrays or scalar values. Always wrap your results in `LLMMessageContents::fromArrayData()`.

**Tip:** If you need to convert `LLMMessageContents` back to a plain array (e.g., for testing), use the `toArray()` method:
```php
<?php
$result = $tool->handle(['input' => 'value']);
$array = $result->toArray();  // Converts to plain array
```

## Built-in Tools

### Text Editor Tool

For building file-manipulation agents with Anthropic models, you can use the `TextEditorTool`. This tool requires a custom storage implementation.

**Note:** This is an advanced feature that requires implementing the `TextEditorStorage` interface to handle file operations securely.

```php
<?php
use Soukicz\Llm\Tool\TextEditor\TextEditorTool;
use Soukicz\Llm\Tool\TextEditor\TextEditorStorage;

// You need to implement TextEditorStorage interface
class MyTextEditorStorage implements TextEditorStorage {
    public function __construct(private string $basePath) {}

    public function read(string $path): string {
        // Implement secure file reading
        return file_get_contents($this->basePath . '/' . $path);
    }

    public function write(string $path, string $content): void {
        // Implement secure file writing
        file_put_contents($this->basePath . '/' . $path, $content);
    }

    // Implement other required methods...
}

$storage = new MyTextEditorStorage('/path/to/working/directory');
$textEditorTool = new TextEditorTool($storage);

$request = new LLMRequest(
    model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
    conversation: new LLMConversation([
        LLMMessage::createFromUserString('Create a new file called hello.txt with "Hello World"')
    ]),
    tools: [$textEditorTool],
);
```

The text editor tool supports:
- `view` - View file contents
- `create` - Create new files
- `str_replace` - Replace text in files
- `insert` - Insert text at specific line numbers

**Security Considerations:** When implementing `TextEditorStorage`, ensure proper path validation, access controls, and sandboxing to prevent unauthorized file access.

## Input Schema

The `inputSchema` follows JSON Schema specification. Common patterns:

### Simple Parameters
```php
<?php
'inputSchema' => [
    'type' => 'object',
    'properties' => [
        'query' => ['type' => 'string', 'description' => 'Search query'],
        'limit' => ['type' => 'integer', 'description' => 'Max results'],
    ],
    'required' => ['query'],
]
```

### Enums
```php
<?php
'properties' => [
    'priority' => [
        'type' => 'string',
        'enum' => ['low', 'medium', 'high'],
        'description' => 'Task priority level'
    ],
]
```

### Arrays
```php
<?php
'properties' => [
    'tags' => [
        'type' => 'array',
        'items' => ['type' => 'string'],
        'description' => 'List of tags'
    ],
]
```

### Nested Objects
```php
<?php
'properties' => [
    'location' => [
        'type' => 'object',
        'properties' => [
            'lat' => ['type' => 'number'],
            'lng' => ['type' => 'number'],
        ],
        'required' => ['lat', 'lng'],
    ],
]
```

## Best Practices

1. **Clear Descriptions** - Provide detailed descriptions for both the tool and each parameter
2. **Use Promises for I/O** - Return promises for async operations like API calls
3. **Validate Input** - The schema helps, but add runtime validation if needed
4. **Error Handling** - Handle errors gracefully and return meaningful error messages
5. **Keep Tools Focused** - One tool should do one thing well
6. **Document Side Effects** - If a tool modifies state, make it clear in the description

## Provider Support

- ✅ **Anthropic (Claude)** - Full support, including native tools
- ✅ **OpenAI (GPT)** - Full function calling support
- ✅ **Google Gemini** - Full function calling support
- ⚠️ **OpenAI-compatible** - Depends on the underlying model

## See Also

- [Feedback Loops](feedback-loops.md) - Validate tool outputs
- [Examples](../examples/index.md) - More tool examples
- [Provider Documentation](../providers/README.md) - Provider-specific tool features
