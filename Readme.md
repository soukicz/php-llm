## DISCLAIMER - HIGHLY EXPERIMENTAL!

This package is highly experimental - I am still trying different approaches and API is wildly changing. Use at your own risk.

## Features

- universal API for multiple language models
- tool support
- caching
- async requests
- feedback loop handling
- token limit handling (auto continue)

## Supported models
 - Anthropic (Claude)
 - OpenAI (GPT)
 - AWS Bedrock (package `soukicz/llm-aws-bedrock`)

### Caching

All clients support caching. You can use the provided FileCache or implement your own cache by implementing the CacheInterface. There is also DynamoDB cache implementation in the `soukicz/llm-cache-dynamodb` package.

Cache is handled on HTTP level - it's important to use specific model names instead of "latest" to avoid using cached responses from older models. Responses are reporting original response times regardless of cache usage.

### Debug
There is MarkdownDebugFormatter that will convert LLMRequest or LLMResponse to markdown. This is useful for debugging and logging.

LLM clients also have optional argument for Guzzle middleware that can be used for http logging.

### Saving state
LLMConversation object supports JSON serialization and also has static method for deserialization. This can be used to save conversation state and continue conversation later.

### Basic usage

#### Simple request and response

```php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;

require_once __DIR__ . '/vendor/autoload.php';

$cache = new FileCache(sys_get_temp_dir());
$anthropic = new \Soukicz\Llm\Client\Anthropic\AnthropicClient('sk-xxxxx', $cache);

/////////////////////////////
// simple synchronous request
$response = $anthropic->sendPrompt(new LLMRequest(
    model: AnthropicClient::MODEL_SONNET_37_20250219,
    conversation: new LLMConversation([LLMMessage::createFromUser([new LLMMessageText('Hello, how are you?')])]),
));
echo $response->getLastText();

////////////////////////
// simple async request
$anthropic->sendPromptAsync(new LLMRequest(
    model: AnthropicClient::MODEL_SONNET_37_20250219,
    conversation: new LLMConversation([LLMMessage::createFromUser([new LLMMessageText('Hello, how are you?')])]),
))->then(function (LLMResponse $response) {
    echo $response->getLastText();
});
```

#### Tools

```php

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Tool\ToolDefinition;

require_once __DIR__ . '/vendor/autoload.php';

$cache = new FileCache(sys_get_temp_dir());
$anthropic = new AnthropicClient('sk-xxxxxx', $cache);

$currencyTool = new ToolDefinition(
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

$response = $anthropic->sendPrompt(new LLMRequest(
    model: AnthropicClient::MODEL_SONNET_37_20250219,
    conversation: new LLMConversation([LLMMessage::createFromUser([new LLMMessageText('How much is 100 USD in EUR today?')])]),
    tools: [$currencyTool],
));
echo $response->getLastText();
```

### Feedback loop handling

Use LLMChainClient to handle feedback loop. Define callback that will be called after each response and decide if the response is valid or not. If not, return a message that will be sent back to LLM.
LLMChainClient will loop conversation until feedback loop returns null.

You should always include counter to block infinite loops.

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
    LLMClient: $anthropic,
    LLMRequest: new LLMRequest(
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

### Feedback loop handling - nested LLM

It is also possible to call LLM in feedback loop. This is useful to check task without easily validatable output. In this example, we are checking if the work was completed by checking the response with the nested LLM.

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
    LLMClient: $anthropic,
    LLMRequest: new LLMRequest(
        model: AnthropicClient::MODEL_SONNET_37_20250219,
        conversation: new LLMConversation([LLMMessage::createFromUser([new LLMMessageText('List all US states in JSON array and wrap this array in XML tag named "states"')])]),
    ),
    feedbackCallback: function (LLMResponse $llmResponse) use ($anthropic): ?LLMMessage {
        if (preg_match('~</states>(.+)~s', $llmResponse->getLastText(), $m)) {
            $suffix = trim(trim(trim($m[3]), '`'));
            if (empty($suffix)) {
                return null;
            }

            $checkResponse = $anthropic->sendPrompt(new LLMRequest(
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
            ));

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

### Token limit handling

It is possible to handle long outputs by providing continuationCallback. This callback will be called if response was cutoff due to token limit. You can manipulate conversation history by merging relevant parts of the conversation and removing "Continue" messages.

There is also a helper method `LLMChainClient::continueTagResponse` that will handle common use case of merging content split to multiple parts by wrapping it in a tag.

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
    LLMClient: $anthropic,
    LLMRequest: new LLMRequest(
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
