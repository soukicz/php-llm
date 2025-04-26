<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Gemini;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Gemini\GeminiEncoder;
use Soukicz\Llm\Client\Gemini\Model\Gemini20Flash;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageImage;
use Soukicz\Llm\Message\LLMMessagePdf;
use Soukicz\Llm\Message\LLMMessageText;

class GeminiEncoderMediaTest extends TestCase {
    private GeminiEncoder $encoder;

    protected function setUp(): void {
        $this->encoder = new GeminiEncoder();
    }

    public function testImageRequest(): void {
        // Create a request with an image
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([
                new LLMMessageText('What is in this image?'),
                new LLMMessageImage('base64', 'image/jpeg', 'base64encodeddata'),
            ]),
        ]);

        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: $conversation
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify message structure
        $this->assertCount(1, $encoded['contents']);

        // Verify user message with image
        $this->assertEquals('user', $encoded['contents'][0]['role']);
        $this->assertCount(2, $encoded['contents'][0]['parts']);

        // Verify text part
        $this->assertEquals('What is in this image?', $encoded['contents'][0]['parts'][0]['text']);

        // Verify image part
        $this->assertArrayHasKey('inline_data', $encoded['contents'][0]['parts'][1]);
        $this->assertEquals('image/jpeg', $encoded['contents'][0]['parts'][1]['inline_data']['mime_type']);
        $this->assertEquals('base64encodeddata', $encoded['contents'][0]['parts'][1]['inline_data']['data']);
    }

    public function testMixedMediaRequest(): void {
        // Create a request with text, then an image, then more text
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([
                new LLMMessageText('Here is a picture of a cat:'),
                new LLMMessageImage('base64', 'image/jpeg', 'base64encodedcatimage'),
                new LLMMessageText('What breed is it?'),
            ]),
        ]);

        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: $conversation
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify message structure
        $this->assertCount(1, $encoded['contents']);

        // Verify user message parts
        $this->assertEquals('user', $encoded['contents'][0]['role']);
        $this->assertCount(3, $encoded['contents'][0]['parts']);

        // Verify first text part
        $this->assertEquals('Here is a picture of a cat:', $encoded['contents'][0]['parts'][0]['text']);

        // Verify image part
        $this->assertArrayHasKey('inline_data', $encoded['contents'][0]['parts'][1]);
        $this->assertEquals('image/jpeg', $encoded['contents'][0]['parts'][1]['inline_data']['mime_type']);
        $this->assertEquals('base64encodedcatimage', $encoded['contents'][0]['parts'][1]['inline_data']['data']);

        // Verify second text part
        $this->assertEquals('What breed is it?', $encoded['contents'][0]['parts'][2]['text']);
    }

    public function testPdfRequestShouldThrowException(): void {
        // PDF is not supported by Gemini directly
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([
                new LLMMessageText('Analyze this PDF:'),
                new LLMMessagePdf('base64', 'base64encodedpdf'),
            ]),
        ]);

        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: $conversation
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PDF content type not supported for Gemini');

        $this->encoder->encodeRequest($request);
    }
}
