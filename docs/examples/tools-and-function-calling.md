# Tools & Function Calling

Enable AI agents to interact with external systems, databases, APIs, and custom code through tool/function calling.

## What are Tools?

Tools (also called function calling) allow LLMs to:
- **Read data**: Fetch information from databases, APIs, files
- **Perform calculations**: Execute complex computations
- **Take actions**: Send emails, update databases, call webhooks
- **Access real-time data**: Get current weather, stock prices, etc.

The LLM decides when to use tools based on your prompts and the tool descriptions you provide.

## Simple Tool Example

```php
<?php
use Soukicz\Llm\Tool\CallbackToolDefinition;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\LLMRequest;

// Define a simple calculator tool
$calculator = new CallbackToolDefinition(
    name: 'calculator',
    description: 'Perform basic arithmetic calculations',
    inputSchema: [
        'type' => 'object',
        'properties' => [
            'expression' => [
                'type' => 'string',
                'description' => 'Mathematical expression to evaluate (e.g., "2 + 2", "10 * 5")',
            ],
        ],
        'required' => ['expression'],
    ],
    handler: function (array $input): LLMMessageContents {
        $expression = $input['expression'];

        // Safety: Basic eval protection (use a proper math parser in production!)
        if (!preg_match('/^[\d\s+\-*\/().]+$/', $expression)) {
            return LLMMessageContents::fromArrayData([
                'error' => 'Invalid expression'
            ]);
        }

        try {
            $result = eval("return $expression;");
            return LLMMessageContents::fromArrayData([
                'result' => $result,
                'expression' => $expression
            ]);
        } catch (\Throwable $e) {
            return LLMMessageContents::fromArrayData([
                'error' => $e->getMessage()
            ]);
        }
    }
);

// Use the tool in a request
$request = new LLMRequest(
    model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
    conversation: new LLMConversation([
        LLMMessage::createFromUserString('What is 157 * 832?')
    ]),
    tools: [$calculator]
);

$response = $agentClient->run($client, $request);
echo $response->getLastText(); // "The result of 157 * 832 is 130,624."
```

## Database Query Tool

```php
<?php
use PDO;

// Define a database query tool
function createDatabaseTool(PDO $pdo): CallbackToolDefinition {
    return new CallbackToolDefinition(
        name: 'query_users',
        description: 'Query the users database to find user information',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the user to look up',
                ],
            ],
            'required' => ['user_id'],
        ],
        handler: function (array $input) use ($pdo): LLMMessageContents {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
            $stmt->execute(['id' => $input['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return LLMMessageContents::fromArrayData([
                    'found' => false,
                    'message' => 'User not found'
                ]);
            }

            return LLMMessageContents::fromArrayData([
                'found' => true,
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'created_at' => $user['created_at'],
                ]
            ]);
        }
    );
}

// Use in a customer support chatbot
$request = new LLMRequest(
    model: $model,
    conversation: new LLMConversation([
        LLMMessage::createFromUserString('Look up information for user ID 1234')
    ]),
    tools: [createDatabaseTool($pdo)]
);
```

## Weather API Tool

```php
<?php
// Tool that calls an external API
$weatherTool = new CallbackToolDefinition(
    name: 'get_weather',
    description: 'Get current weather for a city',
    inputSchema: [
        'type' => 'object',
        'properties' => [
            'city' => [
                'type' => 'string',
                'description' => 'City name (e.g., "London", "New York")',
            ],
            'units' => [
                'type' => 'string',
                'enum' => ['celsius', 'fahrenheit'],
                'description' => 'Temperature units',
            ],
        ],
        'required' => ['city'],
    ],
    handler: function (array $input): LLMMessageContents {
        $city = $input['city'];
        $units = $input['units'] ?? 'celsius';

        // Call weather API (example using OpenWeatherMap)
        $apiKey = getenv('OPENWEATHER_API_KEY');
        $url = sprintf(
            'https://api.openweathermap.org/data/2.5/weather?q=%s&units=%s&appid=%s',
            urlencode($city),
            $units === 'fahrenheit' ? 'imperial' : 'metric',
            $apiKey
        );

        $response = file_get_contents($url);
        $data = json_decode($response, true);

        if (isset($data['cod']) && $data['cod'] !== 200) {
            return LLMMessageContents::fromArrayData([
                'error' => 'City not found or API error'
            ]);
        }

        return LLMMessageContents::fromArrayData([
            'city' => $data['name'],
            'temperature' => $data['main']['temp'],
            'feels_like' => $data['main']['feels_like'],
            'humidity' => $data['main']['humidity'],
            'description' => $data['weather'][0]['description'],
            'units' => $units,
        ]);
    }
);
```

## Multiple Tools

Provide multiple tools and let the LLM choose which to use:

```php
<?php
$tools = [
    // Weather tool
    new CallbackToolDefinition(
        name: 'get_weather',
        description: 'Get current weather for a location',
        inputSchema: [...],
        handler: fn($input) => // weather logic
    ),

    // Stock price tool
    new CallbackToolDefinition(
        name: 'get_stock_price',
        description: 'Get current stock price for a ticker symbol',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'ticker' => [
                    'type' => 'string',
                    'description' => 'Stock ticker symbol (e.g., "AAPL", "GOOGL")',
                ],
            ],
            'required' => ['ticker'],
        ],
        handler: fn($input) => // stock API logic
    ),

    // Calculator
    $calculator,
];

$request = new LLMRequest(
    model: $model,
    conversation: new LLMConversation([
        LLMMessage::createFromUserString(
            'What is the weather in London, and what is Apple stock price?'
        )
    ]),
    tools: $tools
);

// The LLM will automatically call both tools and synthesize the results
$response = $agentClient->run($client, $request);
```

## Multi-Step Tool Usage

Handle conversations where the LLM uses tools multiple times:

```php
<?php
$conversation = new LLMConversation([
    LLMMessage::createFromUserString('Calculate 50 * 30, then add 100 to the result')
]);

// First request
$response = $agentClient->run(
    client: $client,
    request: new LLMRequest(
        model: $model,
        conversation: $conversation,
        tools: [$calculator]
    )
);

// Check if the LLM used a tool
if ($response->getLastMessage()->hasToolUse()) {
    // Add the assistant's response (including tool use) to conversation
    $conversation = $conversation->withMessage($response->getLastMessage());

    // Execute the tool and add result
    foreach ($response->getLastMessage()->getContents()->getToolUses() as $toolUse) {
        $tool = $tools[$toolUse->getName()] ?? null;

        if ($tool) {
            $result = $tool->handle($toolUse->getInput());
            $conversation = $conversation->withMessage(
                LLMMessage::createFromUser(
                    new LLMMessageContents([
                        new LLMMessageToolResult(
                            toolUseId: $toolUse->getId(),
                            content: $result,
                            isError: false
                        )
                    ])
                )
            );
        }
    }

    // Continue the conversation
    $finalResponse = $agentClient->run(
        client: $client,
        request: new LLMRequest(
            model: $model,
            conversation: $conversation,
            tools: [$calculator]
        )
    );

    echo $finalResponse->getLastText();
}
```

## Tool Input Schema Best Practices

The `inputSchema` follows JSON Schema format:

```php
<?php
$inputSchema = [
    'type' => 'object',
    'properties' => [
        'query' => [
            'type' => 'string',
            'description' => 'Clear description of what this parameter does',
        ],
        'limit' => [
            'type' => 'integer',
            'description' => 'Maximum number of results',
            'minimum' => 1,
            'maximum' => 100,
        ],
        'category' => [
            'type' => 'string',
            'enum' => ['tech', 'sports', 'politics'],
            'description' => 'Category to filter by',
        ],
        'tags' => [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'description' => 'Array of tags',
        ],
    ],
    'required' => ['query'], // Mandatory fields
];
```

**Best practices:**
- Provide clear, detailed descriptions for each property
- Use `enum` for constrained choices
- Set `minimum`/`maximum` for numbers
- Mark required fields in the `required` array
- Keep schemas simple - complex nested objects can confuse the model

## Error Handling in Tools

Always handle errors gracefully:

```php
<?php
$tool = new CallbackToolDefinition(
    name: 'send_email',
    description: 'Send an email',
    inputSchema: [...],
    handler: function (array $input): LLMMessageContents {
        try {
            // Validate input
            if (!filter_var($input['to'], FILTER_VALIDATE_EMAIL)) {
                return LLMMessageContents::fromArrayData([
                    'success' => false,
                    'error' => 'Invalid email address'
                ]);
            }

            // Send email
            $sent = mail($input['to'], $input['subject'], $input['body']);

            return LLMMessageContents::fromArrayData([
                'success' => $sent,
                'message' => $sent ? 'Email sent successfully' : 'Failed to send email'
            ]);
        } catch (\Throwable $e) {
            return LLMMessageContents::fromArrayData([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
);
```

## Security Considerations

**Important security guidelines:**

1. **Validate all inputs**: Never trust tool inputs blindly
2. **Use allowlists**: Restrict what tools can access
3. **Avoid eval()**: Never use eval() with user input
4. **Rate limiting**: Prevent abuse of expensive tools
5. **Audit logging**: Log all tool executions
6. **Permissions**: Check user permissions before executing

```php
<?php
// Example: Secure database tool
function createSecureDatabaseTool(PDO $pdo, string $userId): CallbackToolDefinition {
    return new CallbackToolDefinition(
        name: 'query_data',
        description: 'Query your data',
        inputSchema: [...],
        handler: function (array $input) use ($pdo, $userId): LLMMessageContents {
            // Only allow accessing user's own data
            $stmt = $pdo->prepare('
                SELECT * FROM data
                WHERE user_id = :user_id AND id = :id
            ');

            $stmt->execute([
                'user_id' => $userId,  // Security: Scope to current user
                'id' => $input['id']
            ]);

            // Audit log
            error_log("Tool executed by user $userId: query_data");

            // Return results
            return LLMMessageContents::fromArrayData([
                'results' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ]);
        }
    );
}
```

## Testing Tools

Test tools independently before using with LLMs:

```php
<?php
// Unit test a tool
$calculator = new CallbackToolDefinition(...);

$result = $calculator->handle(['expression' => '2 + 2']);
assert($result->toArray()['result'] === 4);

$result = $calculator->handle(['expression' => 'invalid']);
assert(isset($result->toArray()['error']));
```

## See Also

- [Quick Start](quick-start.md) - Basic usage examples
- [State Management](state-management.md) - Saving tool-using conversations
