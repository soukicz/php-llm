<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude35Sonnet;
use Soukicz\Llm\Config\ReasoningBudget;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageReasoning;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;
use Soukicz\Llm\Tool\CallbackToolDefinition;

class AnthropicEncoderToolsTest extends TestCase {
    public function testToolDefinitions(): void {
        $encoder = new AnthropicEncoder();

        // Define a tool
        $weatherTool = new CallbackToolDefinition(
            'weather',
            'Get current weather',
            [
                'type' => 'object',
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'City name',
                    ],
                ],
                'required' => ['location'],
            ],
            fn() => [] // Empty handler for test
        );

        // Create a simple request with tool
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('What is the weather?'),
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude35Sonnet(AnthropicClaude35Sonnet::VERSION_20241022),
            conversation: $conversation,
            tools: [$weatherTool]
        );

        $encoded = $encoder->encodeRequest($request);

        // Verify tool config
        $this->assertArrayHasKey('tools', $encoded);
        $this->assertCount(1, $encoded['tools']);

        // Check tool properties
        $this->assertEquals('weather', $encoded['tools'][0]['name']);
        $this->assertEquals('Get current weather', $encoded['tools'][0]['description']);
        $this->assertArrayHasKey('input_schema', $encoded['tools'][0]);

        // Check schema definition
        $schema = $encoded['tools'][0]['input_schema'];
        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('location', $schema['properties']);

        // Verify tool_choice
        $this->assertArrayHasKey('tool_choice', $encoded);
        $this->assertEquals('auto', $encoded['tool_choice']['type']);
    }

    public function testToolUseAndResults(): void {
        $encoder = new AnthropicEncoder();

        // Create a conversation with tool use and results
        $userMessage = LLMMessage::createFromUserString('What is 2+2?');

        $assistantMessage = LLMMessage::createFromAssistant(new LLMMessageContents([
            new LLMMessageReasoning('I should use the calculator', 'sig123', false),
            new LLMMessageToolUse('tool-abc', 'calculator', ['expression' => '2+2'], false),
        ]));

        $userToolResultMessage = LLMMessage::createFromUser(new LLMMessageContents([
            new LLMMessageToolResult('tool-abc', LLMMessageContents::fromArrayData(['result' => 4]), false),
        ]));

        $conversation = new LLMConversation([
            $userMessage,
            $assistantMessage,
            $userToolResultMessage,
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude35Sonnet(AnthropicClaude35Sonnet::VERSION_20241022),
            conversation: $conversation
        );

        $encoded = $encoder->encodeRequest($request);

        // Verify message count
        $this->assertCount(3, $encoded['messages']);

        // Check assistant message with tool use
        $this->assertEquals('assistant', $encoded['messages'][1]['role']);
        $this->assertCount(2, $encoded['messages'][1]['content']);

        // Check reasoning
        $this->assertEquals('thinking', $encoded['messages'][1]['content'][0]['type']);
        $this->assertEquals('I should use the calculator', $encoded['messages'][1]['content'][0]['thinking']);
        $this->assertEquals('sig123', $encoded['messages'][1]['content'][0]['signature']);

        // Check tool use
        $this->assertEquals('tool_use', $encoded['messages'][1]['content'][1]['type']);
        $this->assertEquals('tool-abc', $encoded['messages'][1]['content'][1]['id']);
        $this->assertEquals('calculator', $encoded['messages'][1]['content'][1]['name']);
        $this->assertEquals(['expression' => '2+2'], $encoded['messages'][1]['content'][1]['input']);

        // Check tool result
        $this->assertEquals('user', $encoded['messages'][2]['role']);
        $this->assertEquals('tool_result', $encoded['messages'][2]['content'][0]['type']);
        $this->assertEquals('tool-abc', $encoded['messages'][2]['content'][0]['tool_use_id']);
        $this->assertEquals('{"result":4}', $encoded['messages'][2]['content'][0]['content']);
    }

    public function testReasoningConfig(): void {
        $encoder = new AnthropicEncoder();

        // Create a request with reasoning budget
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Solve this complex problem'),
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude35Sonnet(AnthropicClaude35Sonnet::VERSION_20241022),
            conversation: $conversation,
            reasoningConfig: new ReasoningBudget(2000)
        );

        $encoded = $encoder->encodeRequest($request);

        // Verify reasoning config
        $this->assertArrayHasKey('thinking', $encoded);
        $this->assertEquals('enabled', $encoded['thinking']['type']);
        $this->assertEquals(2000, $encoded['thinking']['budget_tokens']);
    }
}
