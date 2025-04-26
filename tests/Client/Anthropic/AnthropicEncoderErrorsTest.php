<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude35Sonnet;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageImage;
use Soukicz\Llm\Message\LLMMessageText;

class AnthropicEncoderErrorsTest extends TestCase {
    public function testMultipleSystemMessagesThrowsException(): void {
        $encoder = new AnthropicEncoder();

        // Create a conversation with multiple system messages
        $conversation = new LLMConversation([
            LLMMessage::createFromSystem([new LLMMessageText('First system message')]),
            LLMMessage::createFromSystem([new LLMMessageText('Second system message')]),
            LLMMessage::createFromUser([new LLMMessageText('Hello')]),
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude35Sonnet(AnthropicClaude35Sonnet::VERSION_20241022),
            conversation: $conversation
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiple system messages');

        $encoder->encodeRequest($request);
    }

    public function testNonTextSystemMessageThrowsException(): void {
        $encoder = new AnthropicEncoder();

        // Create a conversation with a non-text system message
        $conversation = new LLMConversation([
            LLMMessage::createFromSystem([new LLMMessageImage('base64', 'image/jpeg', 'data', false)]),
            LLMMessage::createFromUser([new LLMMessageText('Hello')]),
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude35Sonnet(AnthropicClaude35Sonnet::VERSION_20241022),
            conversation: $conversation
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported system message type');

        $encoder->encodeRequest($request);
    }

    public function testInvalidSystemMessageTypeThrowsException(): void {
        $encoder = new AnthropicEncoder();

        // Create a conversation with multiple content blocks in system message
        $conversation = new LLMConversation([
            LLMMessage::createFromSystem([
                new LLMMessageText('System message'),
                new LLMMessageText('Another system message'),
            ]),
            LLMMessage::createFromUser([new LLMMessageText('Hello')]),
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude35Sonnet(AnthropicClaude35Sonnet::VERSION_20241022),
            conversation: $conversation
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('System message supports only one content block');

        $encoder->encodeRequest($request);
    }
}
