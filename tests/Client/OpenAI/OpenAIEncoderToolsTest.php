<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\OpenAI;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\OpenAI\OpenAIEncoder;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;
use Soukicz\Llm\Tool\ToolDefinition;

class OpenAIEncoderToolsTest extends TestCase {
    private OpenAIEncoder $encoder;

    protected function setUp(): void {
        $this->encoder = new OpenAIEncoder();
    }

    public function testToolDefinitions(): void {
        // Define a tool
        $weatherTool = new ToolDefinition(
            'weather',
            'Get current weather information',
            [
                'type' => 'object',
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'The city and state, e.g. San Francisco, CA',
                    ],
                ],
                'required' => ['location'],
            ],
            fn() => [] // Empty handler for test
        );

        // Create a simple request with tool
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([new LLMMessageText('What is the weather in New York?')]),
        ]);

        $request = new LLMRequest(
            model: 'gpt-4o-2024-08-06',
            conversation: $conversation,
            tools: [$weatherTool]
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify tools array exists
        $this->assertArrayHasKey('tools', $encoded);
        $this->assertCount(1, $encoded['tools']);

        // Check tool properties
        $this->assertEquals('function', $encoded['tools'][0]['type']);
        $this->assertArrayHasKey('function', $encoded['tools'][0]);

        // Check function properties
        $function = $encoded['tools'][0]['function'];
        $this->assertEquals('weather', $function['name']);
        $this->assertEquals('Get current weather information', $function['description']);

        // Check schema
        $this->assertArrayHasKey('parameters', $function);
        $this->assertEquals('object', $function['parameters']['type']);
        $this->assertArrayHasKey('properties', $function['parameters']);
        $this->assertArrayHasKey('location', $function['parameters']['properties']);
        $this->assertEquals(['location'], $function['parameters']['required']);
    }

    public function testToolUseEncoding(): void {
        // Create a message with tool use content
        $toolUseMessage = LLMMessage::createFromAssistant([
            new LLMMessageToolUse(
                'tool-123',
                'weather',
                ['location' => 'New York, NY'],
                false
            ),
        ]);

        $conversation = new LLMConversation([$toolUseMessage]);

        $request = new LLMRequest(
            model: 'gpt-4o-2024-08-06',
            conversation: $conversation
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify tool_calls structure
        $this->assertCount(1, $encoded['messages']);
        $this->assertEquals('assistant', $encoded['messages'][0]['role']);
        $this->assertNull($encoded['messages'][0]['content']);
        $this->assertArrayHasKey('tool_calls', $encoded['messages'][0]);

        // Check tool call details
        $toolCall = $encoded['messages'][0]['tool_calls'][0];
        $this->assertEquals('tool-123', $toolCall['id']);
        $this->assertEquals('function', $toolCall['type']);
        $this->assertEquals('weather', $toolCall['function']['name']);
        $this->assertEquals('{"location":"New York, NY"}', $toolCall['function']['arguments']);
    }

    public function testToolResultEncoding(): void {
        // Create a message with tool result content
        $toolResultMessage = LLMMessage::createFromUser([
            new LLMMessageToolResult(
                'tool-123',
                ['temperature' => 72, 'conditions' => 'sunny'],
                false
            ),
        ]);

        $conversation = new LLMConversation([$toolResultMessage]);

        $request = new LLMRequest(
            model: 'gpt-4o-2024-08-06',
            conversation: $conversation
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify tool result structure
        $this->assertCount(1, $encoded['messages']);
        $this->assertEquals('tool', $encoded['messages'][0]['role']);
        $this->assertEquals('{"temperature":72,"conditions":"sunny"}', $encoded['messages'][0]['content']);
        $this->assertEquals('tool-123', $encoded['messages'][0]['tool_call_id']);
    }

    public function testToolConversationFlow(): void {
        // Create a conversation with tool use and tool result
        $userMessage = LLMMessage::createFromUser([
            new LLMMessageText('What is the weather in New York?'),
        ]);

        $assistantToolUse = LLMMessage::createFromAssistant([
            new LLMMessageToolUse(
                'tool-456',
                'weather',
                ['location' => 'New York, NY'],
                false
            ),
        ]);

        $userToolResult = LLMMessage::createFromUser([
            new LLMMessageToolResult(
                'tool-456',
                'The current temperature is 72°F with sunny conditions.',
                false
            ),
        ]);

        $assistantResponse = LLMMessage::createFromAssistant([
            new LLMMessageText('The weather in New York is currently sunny with a temperature of 72°F.'),
        ]);

        $conversation = new LLMConversation([
            $userMessage,
            $assistantToolUse,
            $userToolResult,
            $assistantResponse,
        ]);

        $request = new LLMRequest(
            model: 'gpt-4o-2024-08-06',
            conversation: $conversation
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify message count and types
        $this->assertCount(4, $encoded['messages']);

        // User message
        $this->assertEquals('user', $encoded['messages'][0]['role']);
        $this->assertEquals('What is the weather in New York?', $encoded['messages'][0]['content'][0]['text']);

        // Assistant tool use
        $this->assertEquals('assistant', $encoded['messages'][1]['role']);
        $this->assertNull($encoded['messages'][1]['content']);
        $this->assertArrayHasKey('tool_calls', $encoded['messages'][1]);
        $this->assertEquals('tool-456', $encoded['messages'][1]['tool_calls'][0]['id']);

        // Tool result
        $this->assertEquals('tool', $encoded['messages'][2]['role']);
        $this->assertEquals('The current temperature is 72°F with sunny conditions.', $encoded['messages'][2]['content']);
        $this->assertEquals('tool-456', $encoded['messages'][2]['tool_call_id']);

        // Assistant response
        $this->assertEquals('assistant', $encoded['messages'][3]['role']);
        $this->assertEquals('The weather in New York is currently sunny with a temperature of 72°F.', $encoded['messages'][3]['content'][0]['text']);
    }

    public function testReasoningEffort(): void {
        // Create a request with reasoning effort
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([new LLMMessageText('What is 15 * 17?')]),
        ]);

        $request = new LLMRequest(
            model: 'gpt-4o-2024-08-06',
            conversation: $conversation,
            reasoningConfig: ReasoningEffort::HIGH
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify reasoning effort
        $this->assertArrayHasKey('reasoning_effort', $encoded);
        $this->assertEquals('high', $encoded['reasoning_effort']);
    }
}
