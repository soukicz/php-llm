### Examples

#### OpenAI

```php
use Soukicz\PhpLlm\Cache\FileCache;
use Soukicz\PhpLlm\Client\OpenAI\OpenAIClient;
use Soukicz\PhpLlm\Message\LLMMessage;
use Soukicz\PhpLlm\Message\LLMMessageText;
use Soukicz\PhpLlm\LLMRequest;

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
