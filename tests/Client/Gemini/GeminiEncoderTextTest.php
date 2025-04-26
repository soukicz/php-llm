<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Gemini;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Gemini\GeminiEncoder;
use Soukicz\Llm\Client\Gemini\Model\Gemini20Flash;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;

class GeminiEncoderTextTest extends TestCase {
    private GeminiEncoder $encoder;

    protected function setUp(): void {
        $this->encoder = new GeminiEncoder();
    }

    public function testSimpleTextRequest(): void {
        // Create a simple request with text only
        $conversation = new LLMConversation([
            LLMMessage::createFromSystem([new LLMMessageText('You are a helpful assistant.')]),
            LLMMessage::createFromUser([new LLMMessageText('Hello, how are you?')]),
        ]);

        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: $conversation,
            temperature: 0.7,
            maxTokens: 1000
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify encoded structure
        $this->assertEquals(0.7, $encoded['generationConfig']['temperature']);
        $this->assertEquals(1000, $encoded['generationConfig']['maxOutputTokens']);

        // Verify system instruction
        $this->assertArrayHasKey('systemInstruction', $encoded);
        $this->assertEquals('You are a helpful assistant.', $encoded['systemInstruction']['parts'][0]['text']);

        // Verify messages structure (should only include user message, not system which is handled differently)
        $this->assertCount(1, $encoded['contents']);

        // Check user message
        $this->assertEquals('user', $encoded['contents'][0]['role']);
        $this->assertIsArray($encoded['contents'][0]['parts']);
        $this->assertCount(1, $encoded['contents'][0]['parts']);
        $this->assertEquals('Hello, how are you?', $encoded['contents'][0]['parts'][0]['text']);
    }

    public function testMultipleMessagesInConversation(): void {
        // Create a conversation with multiple messages
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([new LLMMessageText('What is machine learning?')]),
            LLMMessage::createFromAssistant([new LLMMessageText('Machine learning is a field of AI...')]),
            LLMMessage::createFromUser([new LLMMessageText('Can you provide some examples?')]),
        ]);

        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: $conversation
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify message count
        $this->assertCount(3, $encoded['contents']);

        // Verify message roles (in Gemini, assistant is "model")
        $this->assertEquals('user', $encoded['contents'][0]['role']);
        $this->assertEquals('model', $encoded['contents'][1]['role']);
        $this->assertEquals('user', $encoded['contents'][2]['role']);

        // Verify messages content
        $this->assertEquals('What is machine learning?', $encoded['contents'][0]['parts'][0]['text']);
        $this->assertEquals('Machine learning is a field of AI...', $encoded['contents'][1]['parts'][0]['text']);
        $this->assertEquals('Can you provide some examples?', $encoded['contents'][2]['parts'][0]['text']);
    }

    public function testRequestWithStopSequences(): void {
        // Create a request with stop sequences
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([new LLMMessageText('Tell me a story')]),
        ]);

        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: $conversation,
            stopSequences: ['END', 'FINISH']
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify stop sequences
        $this->assertArrayHasKey('generationConfig', $encoded);
        $this->assertArrayHasKey('stopSequences', $encoded['generationConfig']);
        $this->assertCount(2, $encoded['generationConfig']['stopSequences']);
        $this->assertEquals(['END', 'FINISH'], $encoded['generationConfig']['stopSequences']);
    }
}
