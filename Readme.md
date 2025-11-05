# PHP LLM - Agentic AI Framework for PHP

Build powerful **AI agents** that can use tools, self-correct, and take autonomous actions. A unified PHP framework for Large Language Models with support for Anthropic Claude, OpenAI GPT, Google Gemini, and more.

> **What is Agentic AI?** Agents that can call functions, validate outputs, iterate on responses, and make decisions autonomously - not just generate text.

```bash
composer require soukicz/llm
```

---

## 📚 Full Documentation

**→ Complete guides, API reference, and examples: [soukicz.github.io/php-llm](https://soukicz.github.io/php-llm/)**

---

## Why PHP LLM?

- 🤖 **Build AI Agents** - Create autonomous agents with tools, feedback loops, and state management
- 🔄 **Unified API** - One interface for Anthropic, OpenAI, Gemini, and more
- 🛠️ **Function Calling** - Empower agents to interact with external systems and APIs
- 📝 **Built-in Tools** - TextEditorTool for file manipulation, embeddings API, and more
- ✅ **Self-Correcting** - Validate and refine outputs with feedback loops
- 📸 **Multimodal** - Process images and PDFs alongside text (with caching support)
- 🧠 **Reasoning Models** - Advanced thinking with o3 and o4-mini reasoning models
- ⚡ **Async & Caching** - Fast, cost-effective operations with prompt caching
- 💾 **State Persistence** - Save and resume conversations with thread IDs
- 📊 **Monitoring** - Built-in logging, cost tracking, and debugging interfaces

## Key Concepts

Before you start, understanding these core concepts will help you use the library effectively:

### Async by Default
All LLM clients in this library are **asynchronous by default** using Guzzle Promises. The `run()` method is a convenience wrapper that calls `runAsync()->wait()` internally. For production applications handling multiple requests, use the async methods directly for better performance.

### Two Types of Clients

- **LLM Clients** (`AnthropicClient`, `OpenAIClient`, etc.) - Low-level API clients that send a single request and return a single response. Use these when you need direct control over individual API calls.

- **Agent Client** (`LLMAgentClient`) - High-level orchestrator that handles multi-turn conversations, automatic tool calling, feedback loops, and retries. Use this for building agents that need to iterate or use tools.

### Model Versions
Anthropic and OpenAI models require explicit version constants:
```php
<?php
new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929)
new GPTo3(GPTo3::VERSION_2025_04_16)
```
Google Gemini models do NOT require versions - just instantiate them directly.

### Conversations & State
`LLMConversation` manages the message history and can be serialized/deserialized for persistence. Each conversation has an optional `threadId` (UUID) for tracking across sessions.

## Quick Start

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

// Optional: Enable prompt caching to reduce costs
$cache = new FileCache(sys_get_temp_dir());

// Create the API client (low-level, sends single requests)
$client = new AnthropicClient('sk-xxxxx', $cache);

// Create the agent client (high-level, handles tool calls and feedback loops)
$agentClient = new LLMAgentClient();

// Run a request (this is synchronous - use runAsync() for better performance)
$response = $agentClient->run(
    client: $client,
    request: new LLMRequest(
        model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
        conversation: new LLMConversation([
            LLMMessage::createFromUserString('What is PHP?')
        ]),
    )
);

// Get the assistant's response text
echo $response->getLastText();
```

### Async Usage

```php
<?php
// For better performance, use async operations
$promise = $agentClient->runAsync($client, $request);

$promise->then(
    function (LLMResponse $response) {
        echo $response->getLastText();
    },
    function (Exception $error) {
        echo "Error: " . $error->getMessage();
    }
);
```

### Provider-Specific Setup

```php
<?php
// Anthropic Claude
$client = new AnthropicClient(
    apiKey: 'sk-ant-xxxxx',
    cache: $cache,
    customHttpMiddleware: null,
    betaFeatures: [] // e.g., ['text-editor-20250116'] for TextEditorTool
);

// OpenAI (organization parameter is required)
$client = new OpenAIClient(
    apiKey: 'sk-xxxxx',
    apiOrganization: 'org-xxxxx', // Required parameter
    cache: $cache
);

// Google Gemini
$client = new GeminiClient(
    apiKey: 'your-key',
    cache: $cache
);
```

**→ [More Examples](docs/examples/quick-start.md)**

## Core Features

### 🛠️ Function Calling (Tools)

Enable AI agents to call external functions and APIs:

```php
use Soukicz\Llm\Tool\CallbackToolDefinition;
use Soukicz\Llm\Message\LLMMessageContents;

$weatherTool = new CallbackToolDefinition(
    name: 'get_weather',
    description: 'Get current weather for a location',
    inputSchema: ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
    handler: fn($input) => LLMMessageContents::fromArrayData([
        'temperature' => 22,
        'condition' => 'sunny'
    ])
);

$response = $agentClient->run($client, new LLMRequest(
    model: $model,
    conversation: $conversation,
    tools: [$weatherTool],
));
```

> **Note:** Tool handlers must return `LLMMessageContents` or a Promise. See [Tools Documentation](docs/guides/tools.md) for complete examples.

**→ [Tools Documentation](docs/guides/tools.md)**

### ✅ Feedback Loops

Build self-correcting agents that validate and improve their outputs:

```php
$response = $agentClient->run(
    client: $client,
    request: $request,
    feedbackCallback: function ($response) {
        if (!isValid($response->getLastText())) {
            return LLMMessage::createFromUserString('Please try again with valid JSON');
        }
        return null; // Valid, stop iteration
    }
);
```

**→ [Feedback Loops Documentation](docs/guides/feedback-loops.md)**

### 📸 Multimodal Support

Process images and PDFs alongside text:

```php
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageImage;
use Soukicz\Llm\Message\LLMMessagePdf;
use Soukicz\Llm\Message\LLMMessageText;

// Images
$imageData = base64_encode(file_get_contents('/path/to/image.jpg'));
$message = LLMMessage::createFromUser(new LLMMessageContents([
    new LLMMessageText('What is in this image?'),
    new LLMMessageImage('base64', 'image/jpeg', $imageData, cached: true) // Enable prompt caching
]));

// PDFs
$pdfData = base64_encode(file_get_contents('/path/to/document.pdf'));
$message = LLMMessage::createFromUser(new LLMMessageContents([
    new LLMMessageText('Summarize this document'),
    new LLMMessagePdf('base64', $pdfData, cached: true) // Optimize with caching
]));
```

> **Tip:** Use the `cached: true` parameter on large images/PDFs to enable prompt caching and reduce costs.

**→ [Multimodal Documentation](docs/guides/multimodal.md)**

### 🧠 Reasoning Models

Use advanced reasoning for complex problems:

```php
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\Config\ReasoningBudget;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\OpenAI\Model\GPT5;

// Control reasoning with effort level (for supported models)
$request = new LLMRequest(
    model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
    conversation: $conversation,
    reasoningConfig: ReasoningEffort::HIGH // LOW, MEDIUM, or HIGH
);

// Or use token-based budget control (for supported models)
$request = new LLMRequest(
    model: new GPT5(GPT5::VERSION_2025_08_07),
    conversation: $conversation,
    reasoningConfig: new ReasoningBudget(10000) // Max reasoning tokens
);
```

**→ [Reasoning Models Documentation](docs/guides/reasoning.md)**

## Advanced Features

### 📝 TextEditorTool - Built-in File Manipulation

Empower agents to read, write, and manage files with the built-in TextEditorTool:

```php
use Soukicz\Llm\Tool\TextEditorTool;
use Soukicz\Llm\Tool\TextEditorStorageFilesystem;

// Create filesystem storage with sandboxing
$storage = new TextEditorStorageFilesystem('/safe/workspace/path');
$textEditorTool = new TextEditorTool($storage);

// Enable for Anthropic Claude with beta features
$client = new AnthropicClient(
    apiKey: 'sk-ant-xxxxx',
    cache: $cache,
    betaFeatures: ['text-editor-20250116'] // Required for TextEditorTool
);

$response = $agentClient->run($client, new LLMRequest(
    model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
    conversation: new LLMConversation([
        LLMMessage::createFromUserString('Create a PHP file with a hello world function')
    ]),
    tools: [$textEditorTool]
));
```

**→ [Tools Documentation](docs/guides/tools.md)** for complete TextEditorTool examples

### 🔢 Embeddings API

Generate embeddings for semantic search, clustering, and RAG applications:

```php
use Soukicz\Llm\Client\OpenAI\OpenAIClient;

$client = new OpenAIClient('sk-xxxxx', 'your-org-id');

$embeddings = $client->getBatchEmbeddings(
    texts: ['Hello world', 'PHP is great', 'AI embeddings'],
    model: 'text-embedding-3-small',
    dimensions: 512
);

// Returns array of float arrays (embeddings)
foreach ($embeddings as $i => $embedding) {
    echo "Text {$i} embedding dimensions: " . count($embedding) . "\n";
}
```

### 📊 Monitoring & Debugging

Built-in interfaces for logging and monitoring:

```php
use Soukicz\Llm\Log\LLMLogger;

// Implement custom logger
class MyLogger implements LLMLogger {
    public function log(LLMRequest $request, LLMResponse $response): void {
        // Log requests, responses, costs, tokens, etc.
        $cost = ($response->getInputPriceUsd() ?? 0) + ($response->getOutputPriceUsd() ?? 0);
        echo "Cost: $" . $cost . "\n";
        echo "Tokens: {$response->getInputTokens()} in, {$response->getOutputTokens()} out\n";
    }
}

// Attach to agent client
$agentClient = new LLMAgentClient(logger: new MyLogger());
```

**→ [Logging & Debugging Documentation](docs/examples/logging-debugging.md)**

### ⚙️ Advanced Request Configuration

Fine-tune your requests with additional parameters:

```php
use Soukicz\Llm\LLMRequest;

$request = new LLMRequest(
    model: $model,
    conversation: $conversation,
    tools: $tools,

    // Custom stop sequences to halt generation
    stopSequences: ['END', '---'],

    // Reasoning configuration (for o3/o4-mini models)
    reasoningConfig: ReasoningEffort::HIGH,
    // OR
    reasoningConfig: new ReasoningBudget(10000),
);

// Access cost and token information
$response = $agentClient->run($client, $request);
$cost = ($response->getInputPriceUsd() ?? 0) + ($response->getOutputPriceUsd() ?? 0);
echo "Cost: $" . $cost . "\n";
echo "Input tokens: " . $response->getInputTokens() . "\n";
echo "Output tokens: " . $response->getOutputTokens() . "\n";
echo "Stop reason: " . $response->getStopReason()->value . "\n"; // END_TURN, TOOL_USE, MAX_TOKENS, STOP_SEQUENCE
```

## Supported Providers

- **Anthropic (Claude)** - Claude 3.5, 3.7, 4.0, 4.1, and 4.5 series models
- **OpenAI (GPT)** - GPT-4o, GPT-4.1, o3 and o4-mini (reasoning), and GPT-5 series models
- **Google Gemini** - Gemini 2.0 and 2.5 series models
- **OpenAI-Compatible** - OpenRouter, local servers (Ollama, llama-server), and more
- **AWS Bedrock** - Via separate package ([`soukicz/llm-aws-bedrock`](https://github.com/soukicz/llm-aws-bedrock))

**→ [Provider Comparison](docs/providers/README.md)**

## Documentation

### Getting Started
- [Quick Start Examples](docs/examples/quick-start.md) - Get up and running in minutes
- [Configuration Guide](docs/guides/configuration.md) - Configure clients and requests
- [Provider Overview](docs/providers/README.md) - Choose the right provider
- [Best Practices](docs/examples/best-practices.md) - Production-ready patterns

### Core Features
- [Tools & Function Calling](docs/guides/tools.md) - External tools, TextEditorTool, custom functions
- [Feedback Loops](docs/guides/feedback-loops.md) - Self-correcting agents and validation
- [Multimodal Support](docs/guides/multimodal.md) - Images, PDFs, and caching
- [Reasoning Models](docs/guides/reasoning.md) - o3/o4-mini with effort and budget control

### Advanced Features
- [Caching](docs/guides/caching.md) - Prompt caching and cost reduction
- [Batch Processing](docs/guides/batch-processing.md) - High-volume async operations
- [State Management](docs/examples/state-management.md) - Persistence and thread IDs
- [Logging & Debugging](docs/examples/logging-debugging.md) - Monitor and debug

## Common Use Cases

### AI Agent with Tools
```php
use Soukicz\Llm\Tool\CallbackToolDefinition;
use Soukicz\Llm\Message\LLMMessageContents;

// Create custom tools for the agent
$calculatorTool = new CallbackToolDefinition(
    name: 'calculate',
    description: 'Perform mathematical calculations',
    inputSchema: [
        'type' => 'object',
        'properties' => [
            'expression' => ['type' => 'string', 'description' => 'Math expression to evaluate']
        ]
    ],
    handler: fn($input) => LLMMessageContents::fromArrayData([
        'result' => eval('return ' . $input['expression'] . ';')
    ])
);

$searchTool = new CallbackToolDefinition(
    name: 'search_database',
    description: 'Search the product database',
    inputSchema: [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string']
        ]
    ],
    handler: function($input) use ($pdo) {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE name LIKE ?');
        $stmt->execute(['%' . $input['query'] . '%']);
        return LLMMessageContents::fromArrayData($stmt->fetchAll());
    }
);

// Agent will automatically use tools as needed
$response = $agentClient->run($client, new LLMRequest(
    model: $model,
    conversation: new LLMConversation([
        LLMMessage::createFromUserString('Find products with "laptop" and calculate 15% discount on $999')
    ]),
    tools: [$searchTool, $calculatorTool],
));
```

### Self-Correcting JSON Parser
```php
// Agent that validates and corrects its own output
$response = $agentClient->run(
    client: $client,
    request: new LLMRequest(
        model: $model,
        conversation: new LLMConversation([
            LLMMessage::createFromUserString('Extract user data as JSON: John Doe, age 30, email john@example.com')
        ])
    ),
    feedbackCallback: function ($response) {
        $text = $response->getLastText();
        json_decode($text);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return LLMMessage::createFromUserString(
                'Invalid JSON: ' . json_last_error_msg() . '. Please fix the syntax.'
            );
        }

        return null; // Valid JSON, stop iteration
    },
    maxIterations: 3 // Limit retry attempts
);
```

### Multimodal Document Analysis
```php
use Soukicz\Llm\Message\{LLMMessageContents, LLMMessageText, LLMMessageImage, LLMMessagePdf};

// Agent that analyzes multiple document types
$chartData = base64_encode(file_get_contents('/sales-chart.png'));
$reportData = base64_encode(file_get_contents('/quarterly-report.pdf'));

$response = $agentClient->run($client, new LLMRequest(
    model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
    conversation: new LLMConversation([
        LLMMessage::createFromUser(new LLMMessageContents([
            new LLMMessageText('Analyze these documents and summarize the key insights'),
            new LLMMessageImage('base64', 'image/png', $chartData, cached: true),
            new LLMMessagePdf('base64', $reportData, cached: true),
        ]))
    ])
));

echo $response->getLastText();
```

## Frequently Asked Questions

### What's the difference between "agentic" and regular LLM usage?

**Agentic AI** refers to LLMs that can autonomously take actions, use tools, and iterate on their responses. Instead of just generating text, agentic systems:
- Call external functions and APIs (tool use)
- Validate and self-correct their outputs (feedback loops)
- Make decisions about which tools to use
- Persist state across multiple interactions

This library is designed specifically to make building such agents easy in PHP.

### How do I reduce API costs?

1. **Enable caching**: Pass a `FileCache` instance to reduce repeated prompts
2. **Use prompt caching**: Set `cached: true` on images/PDFs
3. **Choose appropriate models**: Smaller models for simpler tasks
4. **Use stop sequences**: Define custom stop sequences to prevent over-generation

### Can I use this with local models?

Yes! Use the `OpenAICompatibleClient` to connect to:
- Ollama (local models)
- llama-server
- OpenRouter
- Any service with OpenAI-compatible API

### How do I save and resume conversations?

```php
// Save conversation
$json = json_encode($conversation);
file_put_contents('conversation.json', $json);

// Resume conversation
$data = json_decode(file_get_contents('conversation.json'), true);
$conversation = LLMConversation::fromJson($data);
```

## Development

### Running Tests

```bash
# Copy environment template
cp .env.example .env

# Add your API keys to .env
# ANTHROPIC_API_KEY=sk-ant-xxxxx
# OPENAI_API_KEY=sk-xxxxx
# GEMINI_API_KEY=your-key

# Run tests
vendor/bin/phpunit
```

### Requirements

- PHP 8.3 or higher
- Composer

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is open-sourced software licensed under the BSD-3-Clause license.

## Links

- [Documentation](docs/) - Full documentation
- [GitHub](https://github.com/soukicz/llm) - Source code
- [Packagist](https://packagist.org/packages/soukicz/llm) - Composer package

---

**Built for modern PHP** • Requires PHP 8.3+ • BSD-3-Clause Licensed
