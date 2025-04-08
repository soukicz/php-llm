<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Gemini;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Gemini\GeminiEncoder;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;
use Soukicz\Llm\Tool\CallbackToolDefinition;
use Soukicz\Llm\Tool\Tool;

class GeminiEncoderToolsTest extends TestCase {
    private GeminiEncoder $encoder;

    protected function setUp(): void {
        $this->encoder = new GeminiEncoder();
    }

    public function testRequestWithTools(): void {
        // Create a simple request with tools
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([new LLMMessageText('What is the weather like in Prague?')]),
        ]);

        $weatherTool = new CallbackToolDefinition(
            'get_weather',
            'Get the current weather for a location',
            [
                'type' => 'object',
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'The city and state, e.g. Prague, CZ',
                    ],
                ],
                'required' => ['location'],
            ],
            function (array $input) {
                return ['temperature' => 22, 'condition' => 'sunny'];
            }
        );

        $request = new LLMRequest(
            model: 'gemini-2.0-flash',
            conversation: $conversation,
            tools: [$weatherTool]
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify tools structure
        $this->assertArrayHasKey('tools', $encoded);
        $this->assertCount(1, $encoded['tools']);
        $this->assertArrayHasKey('functionDeclarations', $encoded['tools'][0]);
        $this->assertCount(1, $encoded['tools'][0]['functionDeclarations']);

        // Verify tool properties
        $functionDeclaration = $encoded['tools'][0]['functionDeclarations'][0];
        $this->assertEquals('get_weather', $functionDeclaration['name']);
        $this->assertEquals('Get the current weather for a location', $functionDeclaration['description']);
        $this->assertEquals('object', $functionDeclaration['parameters']['type']);
        $this->assertArrayHasKey('properties', $functionDeclaration['parameters']);
        $this->assertArrayHasKey('location', $functionDeclaration['parameters']['properties']);
        $this->assertEquals(['location'], $functionDeclaration['parameters']['required']);
    }

    public function testFunctionCallMessage(): void {
        // Test a conversation with a function call from the assistant
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([new LLMMessageText('What is the weather like in Prague?')]),
            LLMMessage::createFromAssistant([
                new LLMMessageToolUse(
                    'tool_1',
                    'get_weather',
                    ['location' => 'Prague, CZ']
                ),
            ]),
        ]);

        $request = new LLMRequest(
            model: 'gemini-2.0-flash',
            conversation: $conversation
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify function call message
        $this->assertCount(2, $encoded['contents']); // User + function call

        // Check function call structure
        $functionCall = $encoded['contents'][1];
        $this->assertEquals('model', $functionCall['role']);
        $this->assertCount(1, $functionCall['parts']);
        $this->assertArrayHasKey('function_call', $functionCall['parts'][0]);
        $this->assertEquals('get_weather', $functionCall['parts'][0]['function_call']['name']);
        $this->assertEquals(['location' => 'Prague, CZ'], $functionCall['parts'][0]['function_call']['args']);
    }

    public function testFunctionResultMessage(): void {
        // Test a conversation with a function result message
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([new LLMMessageText('What is the weather like in Prague?')]),
            LLMMessage::createFromAssistant([
                new LLMMessageToolUse(
                    'tool_1',
                    'get_weather',
                    ['location' => 'Prague, CZ']
                ),
            ]),
            LLMMessage::createFromUser([
                new LLMMessageToolResult(
                    'tool_1',
                    ['temperature' => 22, 'condition' => 'sunny']
                ),
            ]),
        ]);

        $request = new LLMRequest(
            model: 'gemini-2.0-flash',
            conversation: $conversation
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify function result message
        $this->assertCount(3, $encoded['contents']); // User + function call + function result

        // Check function result structure
        $functionResult = $encoded['contents'][2];
        $this->assertEquals('function', $functionResult['role']);
        $this->assertCount(1, $functionResult['parts']);
        $this->assertArrayHasKey('function_response', $functionResult['parts'][0]);
    }

    public function testCompleteFunctionFlow(): void {
        // Test a complete conversation with a user query, function call, function result, and final answer
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([new LLMMessageText('What is the weather like in Prague?')]),
            LLMMessage::createFromAssistant([
                new LLMMessageToolUse(
                    'tool_1',
                    'get_weather',
                    ['location' => 'Prague, CZ']
                ),
            ]),
            LLMMessage::createFromUser([
                new LLMMessageToolResult(
                    'tool_1',
                    ['temperature' => 22, 'condition' => 'sunny']
                ),
            ]),
            LLMMessage::createFromAssistant([
                new LLMMessageText('The weather in Prague is sunny with a temperature of 22°C.'),
            ]),
        ]);

        $request = new LLMRequest(
            model: 'gemini-2.0-flash',
            conversation: $conversation
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Verify the entire conversation flow
        $this->assertCount(4, $encoded['contents']);

        // Check final assistant response
        $finalResponse = $encoded['contents'][3];
        $this->assertEquals('model', $finalResponse['role']);
        $this->assertCount(1, $finalResponse['parts']);
        $this->assertEquals(
            'The weather in Prague is sunny with a temperature of 22°C.',
            $finalResponse['parts'][0]['text']
        );
    }
}
