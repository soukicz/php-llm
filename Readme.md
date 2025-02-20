### Examples

#### OpenAI

```php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\OpenAI\OpenAIClient;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\LLMRequest;

require_once __DIR__ . '/vendor/autoload.php';

$cache = new FileCache(sys_get_temp_dir());

$openAI = new OpenAIClient(
    'sk-xxxxx',
    'org-xxxxxxx',
    $cache
);

$response = $openAI->sendPrompt(new LLMRequest(
    model: OpenAIClient::GPT_4o_MINI,
    systemPrompt: 'Write a message to a friend',
    messages: [LLMMessage::createFromUser([new LLMMessageText('Hello, how are you?')])],
));

echo $response->getLastText();
```
