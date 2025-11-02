<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\OpenAI;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\OpenAI\Model\GPT41;
use Soukicz\Llm\Client\OpenAI\OpenAIEncoder;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageImage;
use Soukicz\Llm\Message\LLMMessageText;

class OpenAIEncoderMediaTest extends TestCase {
    private OpenAIEncoder $encoder;

    protected function setUp(): void {
        $this->encoder = new OpenAIEncoder();
    }

    public function testImageContent(): void {
        // Create a message with image content
        $userMessage = LLMMessage::createFromUser(new LLMMessageContents([
            new LLMMessageText('Look at this image:'),
            new LLMMessageImage('base64', 'image/jpeg', 'imagedata123==', false),
        ]));

        $conversation = new LLMConversation([$userMessage]);

        $request = new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify content types
        $this->assertCount(1, $encoded['messages']);
        $this->assertEquals('user', $encoded['messages'][0]['role']);
        $this->assertCount(2, $encoded['messages'][0]['content']);

        // Check text content
        $this->assertEquals('text', $encoded['messages'][0]['content'][0]['type']);
        $this->assertEquals('Look at this image:', $encoded['messages'][0]['content'][0]['text']);

        // Check image content
        $this->assertEquals('image_url', $encoded['messages'][0]['content'][1]['type']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $encoded['messages'][0]['content'][1]['image_url']['url']);
        $this->assertStringContainsString('imagedata123==', $encoded['messages'][0]['content'][1]['image_url']['url']);
    }

    public function testMixedTextAndImageContent(): void {
        // Create a conversation with mixed content types across multiple messages
        $systemMessage = LLMMessage::createFromSystemString('You are a helpful image analyzer.');

        $userFirstMessage = LLMMessage::createFromUser(new LLMMessageContents([
            new LLMMessageText('Analyze this image:'),
            new LLMMessageImage('base64', 'image/png', 'pngdata123==', false),
        ]));

        $assistantResponse = LLMMessage::createFromAssistantString('This image appears to be a diagram of a process.');

        $userFollowUp = LLMMessage::createFromUserString('Can you explain in more detail?');

        $conversation = new LLMConversation([
            $systemMessage,
            $userFirstMessage,
            $assistantResponse,
            $userFollowUp,
        ]);

        $request = new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify message count
        $this->assertCount(4, $encoded['messages']);

        // Check system message
        $this->assertEquals('system', $encoded['messages'][0]['role']);
        $this->assertEquals('You are a helpful image analyzer.', $encoded['messages'][0]['content'][0]['text']);

        // Check first user message with image
        $this->assertEquals('user', $encoded['messages'][1]['role']);
        $this->assertCount(2, $encoded['messages'][1]['content']);
        $this->assertEquals('text', $encoded['messages'][1]['content'][0]['type']);
        $this->assertEquals('image_url', $encoded['messages'][1]['content'][1]['type']);
        $this->assertStringStartsWith('data:image/png;base64,', $encoded['messages'][1]['content'][1]['image_url']['url']);
        $this->assertStringContainsString('pngdata123==', $encoded['messages'][1]['content'][1]['image_url']['url']);

        // Check assistant response
        $this->assertEquals('assistant', $encoded['messages'][2]['role']);
        $this->assertEquals('This image appears to be a diagram of a process.', $encoded['messages'][2]['content'][0]['text']);

        // Check follow-up
        $this->assertEquals('user', $encoded['messages'][3]['role']);
        $this->assertEquals('Can you explain in more detail?', $encoded['messages'][3]['content'][0]['text']);
    }
}
