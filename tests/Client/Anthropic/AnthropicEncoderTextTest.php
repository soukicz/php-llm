<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;

class AnthropicEncoderTextTest extends TestCase {
    public function testTextOnlyRequest(): void {
        $encoder = new AnthropicEncoder();

        // Create a conversation with only text
        $conversation = new LLMConversation([
            LLMMessage::createFromSystem([new LLMMessageText('You are a helpful assistant.')]),
            LLMMessage::createFromUser([new LLMMessageText('Hello')]),
        ]);

        $request = new LLMRequest(
            model: 'claude-3-sonnet-20240229',
            conversation: $conversation,
            maxTokens: 100
        );

        $encoded = $encoder->encodeRequest($request);

        // Verify basic structure
        $this->assertEquals('claude-3-sonnet-20240229', $encoded['model']);
        $this->assertEquals(100, $encoded['max_tokens']);
        $this->assertEquals('You are a helpful assistant.', $encoded['system']);

        // Verify message format
        $this->assertCount(1, $encoded['messages']);
        $this->assertEquals('user', $encoded['messages'][0]['role']);

        // Verify message content
        $this->assertCount(1, $encoded['messages'][0]['content']);
        $this->assertEquals('text', $encoded['messages'][0]['content'][0]['type']);
        $this->assertEquals('Hello', $encoded['messages'][0]['content'][0]['text']);
    }

    public function testMultipleMessagesInConversation(): void {
        $encoder = new AnthropicEncoder();

        // Create a conversation with multiple messages
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([new LLMMessageText('First message')]),
            LLMMessage::createFromAssistant([new LLMMessageText('First response')]),
            LLMMessage::createFromUser([new LLMMessageText('Second message')]),
        ]);

        $request = new LLMRequest(
            model: 'claude-3-sonnet-20240229',
            conversation: $conversation
        );

        $encoded = $encoder->encodeRequest($request);

        // Verify message count
        $this->assertCount(3, $encoded['messages']);

        // Verify message roles
        $this->assertEquals('user', $encoded['messages'][0]['role']);
        $this->assertEquals('assistant', $encoded['messages'][1]['role']);
        $this->assertEquals('user', $encoded['messages'][2]['role']);

        // Verify messages content
        $this->assertEquals('First message', $encoded['messages'][0]['content'][0]['text']);
        $this->assertEquals('First response', $encoded['messages'][1]['content'][0]['text']);
        $this->assertEquals('Second message', $encoded['messages'][2]['content'][0]['text']);
    }
}
