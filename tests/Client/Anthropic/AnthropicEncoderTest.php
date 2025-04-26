<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude35Sonnet;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;

class AnthropicEncoderTest extends TestCase {
    private AnthropicEncoder $encoder;

    protected function setUp(): void {
        $this->encoder = new AnthropicEncoder();
    }

    public function testBasicTextRequestEncoding(): void {
        // Create a simple request with text only
        $conversation = new LLMConversation([
            LLMMessage::createFromSystem([new LLMMessageText('System instruction')]),
            LLMMessage::createFromUser([new LLMMessageText('User message')]),
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude35Sonnet(AnthropicClaude35Sonnet::VERSION_20241022),
            conversation: $conversation,
            temperature: 0.5,
            maxTokens: 500,
            stopSequences: ['STOP']
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Basic structure checks
        $this->assertEquals('claude-3-5-sonnet-20241022', $encoded['model']);
        $this->assertEquals(500, $encoded['max_tokens']);
        $this->assertEquals(0.5, $encoded['temperature']);
        $this->assertEquals('System instruction', $encoded['system']);
        $this->assertEquals(['STOP'], $encoded['stop_sequences']);

        // Message structure
        $this->assertCount(1, $encoded['messages']);
        $this->assertEquals('user', $encoded['messages'][0]['role']);
        $this->assertCount(1, $encoded['messages'][0]['content']);
        $this->assertEquals('text', $encoded['messages'][0]['content'][0]['type']);
        $this->assertEquals('User message', $encoded['messages'][0]['content'][0]['text']);
    }
}
