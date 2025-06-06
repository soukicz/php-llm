<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude35Sonnet;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageImage;
use Soukicz\Llm\Message\LLMMessagePdf;
use Soukicz\Llm\Message\LLMMessageText;

class AnthropicEncoderMediaTest extends TestCase {
    public function testImageContent(): void {
        $encoder = new AnthropicEncoder();

        // Create a message with image content
        $userMessage = LLMMessage::createFromUser(new LLMMessageContents([
            new LLMMessageText('Look at this image:'),
            new LLMMessageImage('base64', 'image/jpeg', 'imagedata123==', true),
        ]));

        $conversation = new LLMConversation([$userMessage]);

        $request = new LLMRequest(
            model: new AnthropicClaude35Sonnet(AnthropicClaude35Sonnet::VERSION_20241022),
            conversation: $conversation
        );

        $encoded = $encoder->encodeRequest($request);

        // Verify content types
        $this->assertCount(2, $encoded['messages'][0]['content']);

        // Check text content
        $this->assertEquals('text', $encoded['messages'][0]['content'][0]['type']);
        $this->assertEquals('Look at this image:', $encoded['messages'][0]['content'][0]['text']);

        // Check image content
        $this->assertEquals('image', $encoded['messages'][0]['content'][1]['type']);
        $this->assertEquals('base64', $encoded['messages'][0]['content'][1]['source']['type']);
        $this->assertEquals('image/jpeg', $encoded['messages'][0]['content'][1]['source']['media_type']);
        $this->assertEquals('imagedata123==', $encoded['messages'][0]['content'][1]['source']['data']);

        // Check cache control
        $this->assertArrayHasKey('cache_control', $encoded['messages'][0]['content'][1]);
        $this->assertEquals(['type' => 'ephemeral'], $encoded['messages'][0]['content'][1]['cache_control']);
    }

    public function testPdfContent(): void {
        $encoder = new AnthropicEncoder();

        // Create a message with PDF content
        $userMessage = LLMMessage::createFromUser(new LLMMessageContents([
            new LLMMessageText('Read this PDF:'),
            new LLMMessagePdf('base64', 'pdfdata123==', false),
        ]));

        $conversation = new LLMConversation([$userMessage]);

        $request = new LLMRequest(
            model: new AnthropicClaude35Sonnet(AnthropicClaude35Sonnet::VERSION_20241022),
            conversation: $conversation
        );

        $encoded = $encoder->encodeRequest($request);

        // Verify content types
        $this->assertCount(2, $encoded['messages'][0]['content']);

        // Check PDF content
        $this->assertEquals('document', $encoded['messages'][0]['content'][1]['type']);
        $this->assertEquals('base64', $encoded['messages'][0]['content'][1]['source']['type']);
        $this->assertEquals('application/pdf', $encoded['messages'][0]['content'][1]['source']['media_type']);
        $this->assertEquals('pdfdata123==', $encoded['messages'][0]['content'][1]['source']['data']);

        // Confirm no cache control since caching is not enabled
        $this->assertArrayNotHasKey('cache_control', $encoded['messages'][0]['content'][1]);
    }
}
