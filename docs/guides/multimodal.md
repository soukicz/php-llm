# Multimodal Support

PHP LLM supports multimodal AI agents that can process both text and other content types like images and PDFs alongside your prompts.

## Sending Images

AI agents can analyze images using the `LLMMessageImage` class. Images must be base64-encoded.

### From File Path

```php
<?php
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageImage;
use Soukicz\Llm\Message\LLMMessageText;

// Load and encode the image
$imageData = base64_encode(file_get_contents('/path/to/image.jpg'));

$message = LLMMessage::createFromUser(new LLMMessageContents([
    new LLMMessageText('What do you see in this image?'),
    new LLMMessageImage('base64', 'image/jpeg', $imageData)
]));
```

### Different Image Types

```php
<?php
// JPEG image
$jpegData = base64_encode(file_get_contents('/path/to/photo.jpg'));
$message = LLMMessage::createFromUser(new LLMMessageContents([
    new LLMMessageText('Describe this image'),
    new LLMMessageImage('base64', 'image/jpeg', $jpegData)
]));

// PNG image
$pngData = base64_encode(file_get_contents('/path/to/diagram.png'));
$message = LLMMessage::createFromUser(new LLMMessageContents([
    new LLMMessageText('Analyze this diagram'),
    new LLMMessageImage('base64', 'image/png', $pngData)
]));

// WebP image
$webpData = base64_encode(file_get_contents('/path/to/image.webp'));
$message = LLMMessage::createFromUser(new LLMMessageContents([
    new LLMMessageText('What is in this image?'),
    new LLMMessageImage('base64', 'image/webp', $webpData)
]));
```

## Sending PDFs

AI agents can read and analyze PDF documents using the `LLMMessagePdf` class. PDFs must be base64-encoded.

### From File Path

```php
<?php
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessagePdf;
use Soukicz\Llm\Message\LLMMessageText;

// Load and encode the PDF
$pdfData = base64_encode(file_get_contents('/path/to/document.pdf'));

$message = LLMMessage::createFromUser(new LLMMessageContents([
    new LLMMessageText('Summarize this PDF document'),
    new LLMMessagePdf('base64', $pdfData)
]));
```

### Extract Specific Information

```php
<?php
$pdfData = base64_encode(file_get_contents('/path/to/invoice.pdf'));

$message = LLMMessage::createFromUser(new LLMMessageContents([
    new LLMMessageText('Extract the invoice number, date, and total amount from this PDF'),
    new LLMMessagePdf('base64', $pdfData)
]));
```

## Complete Example

```php
<?php
use Soukicz\Llm\Cache\FileCache;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\LLMAgentClient;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageImage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;

$cache = new FileCache(sys_get_temp_dir());
$anthropic = new AnthropicClient('sk-xxxxx', $cache);
$chainClient = new LLMAgentClient();

// Load and encode the image
$imageData = base64_encode(file_get_contents('/path/to/photo.jpg'));

$response = $chainClient->run(
    client: $anthropic,
    request: new LLMRequest(
        model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
        conversation: new LLMConversation([
            LLMMessage::createFromUser(new LLMMessageContents([
                new LLMMessageText('What objects are in this image?'),
                new LLMMessageImage('base64', 'image/jpeg', $imageData)
            ]))
        ]),
    )
);

echo $response->getLastText();
```

## Combining Multiple Media

You can include multiple images and/or PDFs in a single message:

```php
<?php
$image1Data = base64_encode(file_get_contents('/path/to/chart.png'));
$image2Data = base64_encode(file_get_contents('/path/to/graph.jpg'));
$pdfData = base64_encode(file_get_contents('/path/to/report.pdf'));

$message = LLMMessage::createFromUser(new LLMMessageContents([
    new LLMMessageText('Compare the data in these images with the PDF report'),
    new LLMMessageImage('base64', 'image/png', $image1Data),
    new LLMMessageImage('base64', 'image/jpeg', $image2Data),
    new LLMMessagePdf('base64', $pdfData)
]));
```

## Provider Support

**Image Support:**
- ✅ Anthropic (Claude) - All models
- ✅ OpenAI (GPT) - GPT-4o and later models
- ✅ Google Gemini - All 2.0+ models
- ⚠️ OpenAI-compatible - Depends on the underlying model

**PDF Support:**
- ✅ Anthropic (Claude) - All models
- ✅ OpenAI (GPT) - GPT-4o and later models
- ❌ Google Gemini - Not currently supported
- ⚠️ OpenAI-compatible - Depends on the underlying model

## Provider-Specific Notes

### OpenAI Image Requirements
OpenAI requires images to be encoded as base64 data URIs. The library handles the data URI formatting automatically.

### Anthropic PDF Support
Anthropic's Claude models have excellent PDF parsing capabilities and can handle complex document layouts, tables, and multi-column text.

### File Size Limits
Be aware of file size limits:
- Images are typically limited to 5-20MB depending on provider
- PDFs may have similar size restrictions
- Large files will consume more tokens and increase costs

## See Also

- [Providers Documentation](../providers/README.md) - Provider-specific multimodal features
- [Tools Guide](tools.md) - Building agents that use tools with multimodal inputs
- [Examples](../examples/index.md) - More multimodal examples
