<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\OpenAI;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\OpenAI\OpenAIEncoder;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;

class OpenAIEncoderTextTest extends TestCase {
    private OpenAIEncoder $encoder;

    protected function setUp(): void {
        $this->encoder = new OpenAIEncoder();
    }

    public function testSimpleTextRequest(): void {
        // Create a simple request with text only
        $conversation = new LLMConversation([
            LLMMessage::createFromSystem([new LLMMessageText('You are a helpful assistant.')]),
            LLMMessage::createFromUser([new LLMMessageText('Hello, how are you?')]),
        ]);

        $request = new LLMRequest(
            model: 'gpt-4o-2024-08-06',
            conversation: $conversation,
            temperature: 0.7,
            maxTokens: 1000
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify encoded structure
        $this->assertEquals('gpt-4o-2024-08-06', $encoded['model']);
        $this->assertEquals(1000, $encoded['max_tokens']);
        $this->assertEquals(0.7, $encoded['temperature']);

        // Verify messages structure
        $this->assertCount(2, $encoded['messages']);

        // Check system message
        $this->assertEquals('system', $encoded['messages'][0]['role']);
        $this->assertIsArray($encoded['messages'][0]['content']);
        $this->assertCount(1, $encoded['messages'][0]['content']);
        $this->assertEquals('text', $encoded['messages'][0]['content'][0]['type']);
        $this->assertEquals('You are a helpful assistant.', $encoded['messages'][0]['content'][0]['text']);

        // Check user message
        $this->assertEquals('user', $encoded['messages'][1]['role']);
        $this->assertIsArray($encoded['messages'][1]['content']);
        $this->assertCount(1, $encoded['messages'][1]['content']);
        $this->assertEquals('text', $encoded['messages'][1]['content'][0]['type']);
        $this->assertEquals('Hello, how are you?', $encoded['messages'][1]['content'][0]['text']);
    }

    public function testMultipleMessagesInConversation(): void {
        // Create a conversation with multiple messages
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([new LLMMessageText('What is machine learning?')]),
            LLMMessage::createFromAssistant([new LLMMessageText('Machine learning is a field of AI...')]),
            LLMMessage::createFromUser([new LLMMessageText('Can you provide some examples?')]),
        ]);

        $request = new LLMRequest(
            model: 'gpt-4o-mini-2024-07-18',
            conversation: $conversation
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify message count
        $this->assertCount(3, $encoded['messages']);

        // Verify message roles
        $this->assertEquals('user', $encoded['messages'][0]['role']);
        $this->assertEquals('assistant', $encoded['messages'][1]['role']);
        $this->assertEquals('user', $encoded['messages'][2]['role']);

        // Verify messages content
        $this->assertEquals('What is machine learning?', $encoded['messages'][0]['content'][0]['text']);
        $this->assertEquals('Machine learning is a field of AI...', $encoded['messages'][1]['content'][0]['text']);
        $this->assertEquals('Can you provide some examples?', $encoded['messages'][2]['content'][0]['text']);
    }

    public function testRequestWithStopSequences(): void {
        // Create a request with stop sequences
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([new LLMMessageText('Tell me a story')]),
        ]);

        $request = new LLMRequest(
            model: 'gpt-4o-2024-08-06',
            conversation: $conversation,
            stopSequences: ['END', 'FINISH']
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify stop sequences
        $this->assertArrayHasKey('stop', $encoded);
        $this->assertCount(2, $encoded['stop']);
        $this->assertEquals(['END', 'FINISH'], $encoded['stop']);
    }
}
