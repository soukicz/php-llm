## Features

 - Unified API for multiple language models
 - Tool integration
 - Response caching
 - Asynchronous requests
 - Feedback loop handling
 - Automatic token limit handling (continuation support)

## Supported models
 - Anthropic (Claude)
 - OpenAI (GPT)
 - Google (Gemini)
 - AWS Bedrock (package `soukicz/llm-aws-bedrock`)

## Installation

```bash
composer require soukicz/llm
```

## Caching

All clients support caching. You can use the provided `FileCache` or implement your own cache by extending `CacheInterface`. A DynamoDB cache implementation is also available in the `soukicz/llm-cache-dynamodb` package.

Caching operates at the HTTP request level. To ensure correct caching behavior, always specify exact model names instead of using general terms like "latest," to prevent cached responses from older models. Cached responses still report the original response time.

## Debugging
Use `MarkdownDebugFormatter` to convert `LLMRequest` or `LLMResponse` objects to markdown format, aiding debugging and logging.

LLM clients also support an optional Guzzle middleware for HTTP-level logging.

## Saving state
The `LLMConversation` object supports JSON serialization and deserialization. This allows you to save conversation states and resume them later.

## Simple request and response

```php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\LLMChainClient;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;

require_once __DIR__ . '/vendor/autoload.php';

$cache = new FileCache(sys_get_temp_dir());
$anthropic = new AnthropicClient('sk-xxxxx', $cache);
$chainClient = new LLMChainClient();

/////////////////////////////
// simple synchronous request
$response = $chainClient->run(
    client: $anthropic,
    request: new LLMRequest(
        model: AnthropicClient::MODEL_SONNET_37_20250219,
        conversation: new LLMConversation([LLMMessage::createFromUser([new LLMMessageText('Hello, how are you?')])]),
    )
);
echo $response->getLastText();

////////////////////////
// simple async request
$response = $chainClient->runAsync(
    client: $anthropic,
    request: new LLMRequest(
        model: AnthropicClient::MODEL_SONNET_37_20250219,
        conversation: new LLMConversation([LLMMessage::createFromUser([new LLMMessageText('Hello, how are you?')])]),
    )
)->then(function (LLMResponse $response) {
    echo $response->getLastText();
});
```

### Tools

```php

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\LLMChainClient;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
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

        // tool can return either a promise or a value
        return $client->getAsync('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/' . strtolower($input['source_currency']) . '.json')
            ->then(function (Response $response) use ($input) {
                $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

                return [
                    'rate' => $data[strtolower($input['source_currency'])][strtolower($input['target_currency'])],
                ];
            });
    }
);

$response = $chainClient->run(
    client:$anthropic,
    request: new LLMRequest(
        model: AnthropicClient::MODEL_SONNET_37_20250219,
        conversation: new LLMConversation([LLMMessage::createFromUser([new LLMMessageText('How much is 100 USD in EUR today?')])]),
        tools: [$currencyTool],
    )
);
echo $response->getLastText();
```

## Feedback loop handling

`LLMChainClient` manages feedback loops. Define a callback function to validate responses and optionally request a retry. Always include a loop counter to prevent infinite loops.

```php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\LLMChainClient;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;

require_once __DIR__ . '/vendor/autoload.php';

$cache = new FileCache(sys_get_temp_dir());
$anthropic = new AnthropicClient('sk-xxxxxx', $cache);
$chainClient = new LLMChainClient();

$response = $chainClient->run(
    client: $anthropic,
    request: new LLMRequest(
        model: AnthropicClient::MODEL_SONNET_37_20250219,
        conversation: new LLMConversation([LLMMessage::createFromUser([new LLMMessageText('List 5 animals in JSON array and wrap this array in XML tag named "animals"')])]),
    ),
    feedbackCallback: function (LLMResponse $llmResponse): ?LLMMessage {
        if (preg_match('~<animals>(.+)</animals>~s', $llmResponse->getLastText(), $m)) {
            try {
                json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

                return null;
            } catch (JsonException $e) {
                return LLMMessage::createFromUser([new LLMMessageText('I am sorry, but the response is not a valid JSON (' . $e->getMessage() . '). Please respond again.')]);
            }
        }

        return LLMMessage::createFromUser([new LLMMessageText('I am sorry, but I could not find animals tag in the response. Please respond again.')]);
    }
);

echo $response->getLastText();
```

## Feedback loop handling - nested LLM

You can use nested LLM calls within a feedback loop to validate complex responses through an additional LLM evaluation step.

```php

use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\LLMChainClient;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;

require_once __DIR__ . '/vendor/autoload.php';

$cache = new FileCache(sys_get_temp_dir());
$anthropic = new AnthropicClient('sk-xxxxx', $cache);
$chainClient = new LLMChainClient();

$response = $chainClient->run(
    client: $anthropic,
    request: new LLMRequest(
        model: AnthropicClient::MODEL_SONNET_37_20250219,
        conversation: new LLMConversation([LLMMessage::createFromUser([new LLMMessageText('List all US states in JSON array and wrap this array in XML tag named "states"')])]),
    ),
    feedbackCallback: function (LLMResponse $llmResponse) use ($anthropic, $chainClient): ?LLMMessage {
        if (preg_match('~</states>(.+)~s', $llmResponse->getLastText(), $m)) {
            $suffix = trim(trim(trim($m[3]), '`'));
            if (empty($suffix)) {
                return null;
            }

            $checkResponse = $chainClient->run(
                client: $anthropic,
                request: new LLMRequest(
                    model: AnthropicClient::MODEL_HAIKU_35_20241022, // use cheap and fast model for this simple task
                    conversation: new LLMConversation([
                        LLMMessage::createFromUser([
                            new LLMMessageText(<<<EOT
I need help with understanding of text. I have submitted work and I have received following text at the end of response:

<response-text>
$suffix
</response-text>

I need you to decide if this means that work was completed or if I should request continuation of work. Briefly explain what you see in response and finally output WORK_COMPLETED or WORK_NOT_COMPLETED. This is automated process and I need one of these two outputs.
EOT
                            ),
                        ]),
                    ]),
                )
            );

            if (str_contains($checkResponse->getLastText(), 'WORK_COMPLETED')) {
                return null;
            }

            return LLMMessage::createFromUser([new LLMMessageText('Please continue')]);
        }

        return null;
    }
);

echo $response->getLastText();
```

## Token limit handling

Handle long responses using `continuationCallback`. The helper method `LLMChainClient::continueTagResponse` simplifies splitting long outputs into multiple parts.

```php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\LLMChainClient;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;

require_once __DIR__ . '/vendor/autoload.php';

$cache = new FileCache(sys_get_temp_dir());
$anthropic = new AnthropicClient('sk-xxxx', $cache);
$chainClient = new LLMChainClient();

$response = $chainClient->run(
    client: $anthropic,
    request: new LLMRequest(
        model: AnthropicClient::MODEL_SONNET_37_20250219,
        conversation: new LLMConversation([LLMMessage::createFromUser([new LLMMessageText('List all US states. Batch output by 5 states and output each batch as JSON array and wrap this array in XML tag named "states"')])]),
        maxTokens: 55
    ),
    continuationCallback: function (LLMResponse $llmResponse): LLMRequest {
        return LLMChainClient::continueTagResponse($llmResponse->getRequest(), ['states'], 'Continue');
    }
);

echo $response->getLastText();
```
