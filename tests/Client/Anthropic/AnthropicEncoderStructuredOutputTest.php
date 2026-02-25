<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Opus;
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\Config\StructuredOutputConfig;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageStructuredData;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Tool\CallbackToolDefinition;

class AnthropicEncoderStructuredOutputTest extends TestCase {
    private AnthropicEncoder $encoder;

    private array $testSchema = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string'],
        ],
        'required' => ['name', 'email'],
        'additionalProperties' => false,
    ];

    protected function setUp(): void {
        $this->encoder = new AnthropicEncoder();
    }

    public function testEncodeRequestWithStructuredOutput(): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Extract contact info'),
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude46Opus(),
            conversation: $conversation,
            structuredOutputConfig: new StructuredOutputConfig($this->testSchema),
        );

        $encoded = $this->encoder->encodeRequest($request);

        $this->assertArrayHasKey('output_config', $encoded);
        $this->assertEquals('json_schema', $encoded['output_config']['format']['type']);
        $this->assertEquals($this->testSchema, $encoded['output_config']['format']['schema']);
    }

    public function testEncodeRequestWithoutStructuredOutput(): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Hello'),
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude46Opus(),
            conversation: $conversation,
        );

        $encoded = $this->encoder->encodeRequest($request);

        $this->assertArrayNotHasKey('output_config', $encoded);
    }

    public function testEncodeRequestWithStructuredOutputAndReasoningEffort(): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Analyze this'),
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude46Opus(),
            conversation: $conversation,
            reasoningConfig: ReasoningEffort::HIGH,
            structuredOutputConfig: new StructuredOutputConfig($this->testSchema),
        );

        $encoded = $this->encoder->encodeRequest($request);

        // Both should coexist in output_config
        $this->assertArrayHasKey('output_config', $encoded);
        $this->assertEquals('high', $encoded['output_config']['effort']);
        $this->assertEquals('json_schema', $encoded['output_config']['format']['type']);
        $this->assertEquals($this->testSchema, $encoded['output_config']['format']['schema']);

        // Thinking should also be present
        $this->assertArrayHasKey('thinking', $encoded);
        $this->assertEquals('adaptive', $encoded['thinking']['type']);
    }

    public function testEncodeRequestWithStructuredOutputAndReasoningNone(): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Hello'),
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude46Opus(),
            conversation: $conversation,
            reasoningConfig: ReasoningEffort::NONE,
            structuredOutputConfig: new StructuredOutputConfig($this->testSchema),
        );

        $encoded = $this->encoder->encodeRequest($request);

        // output_config should have format but NOT effort
        $this->assertArrayHasKey('output_config', $encoded);
        $this->assertArrayNotHasKey('effort', $encoded['output_config']);
        $this->assertEquals('json_schema', $encoded['output_config']['format']['type']);

        // No thinking block
        $this->assertArrayNotHasKey('thinking', $encoded);
    }

    public function testToolDefinitionsIncludeStrict(): void {
        $tool = new CallbackToolDefinition(
            'weather',
            'Get current weather',
            [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string'],
                ],
                'required' => ['location'],
            ],
            fn() => []
        );

        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('What is the weather?'),
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude46Opus(),
            conversation: $conversation,
            tools: [$tool],
        );

        $encoded = $this->encoder->encodeRequest($request);

        $this->assertArrayHasKey('tools', $encoded);
        $this->assertTrue($encoded['tools'][0]['strict']);
    }

    public function testDecodeResponseWithStructuredOutput(): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Extract contact info'),
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude46Opus(),
            conversation: $conversation,
            structuredOutputConfig: new StructuredOutputConfig($this->testSchema),
        );

        $responseData = [
            'content' => [
                [
                    'type' => 'text',
                    'text' => '{"name":"Jane","email":"jane@example.com"}',
                ],
            ],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 15,
                'output_tokens' => 8,
            ],
        ];

        $result = $this->encoder->decodeResponse($request, new ModelResponse($responseData, 150));

        $this->assertInstanceOf(LLMResponse::class, $result);
        $lastMessage = $result->getConversation()->getLastMessage();
        $contents = iterator_to_array($lastMessage->getContents());
        $this->assertCount(1, $contents);
        $this->assertInstanceOf(LLMMessageStructuredData::class, $contents[0]);
        $this->assertEquals(['name' => 'Jane', 'email' => 'jane@example.com'], $contents[0]->getData());
        $this->assertEquals('{"name":"Jane","email":"jane@example.com"}', $contents[0]->getRawJson());
    }

    public function testDecodeResponseWithoutStructuredOutputCreatesText(): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Hello'),
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude46Opus(),
            conversation: $conversation,
        );

        $responseData = [
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Hello there!',
                ],
            ],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 5,
                'output_tokens' => 3,
            ],
        ];

        $result = $this->encoder->decodeResponse($request, new ModelResponse($responseData, 100));

        $this->assertInstanceOf(LLMResponse::class, $result);
        $lastMessage = $result->getConversation()->getLastMessage();
        $contents = iterator_to_array($lastMessage->getContents());
        $this->assertInstanceOf(LLMMessageText::class, $contents[0]);
    }

    public function testEncodeRequestAddsAdditionalPropertiesFalse(): void {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'address' => [
                    'type' => 'object',
                    'properties' => [
                        'city' => ['type' => 'string'],
                    ],
                ],
            ],
            'required' => ['name'],
        ];

        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Test'),
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude46Opus(),
            conversation: $conversation,
            structuredOutputConfig: new StructuredOutputConfig($schema),
        );

        $encoded = $this->encoder->encodeRequest($request);

        $normalizedSchema = $encoded['output_config']['format']['schema'];
        $this->assertFalse($normalizedSchema['additionalProperties']);
        $this->assertFalse($normalizedSchema['properties']['address']['additionalProperties']);
    }

    public function testEncodeStructuredDataContentForRoundTripping(): void {
        $structuredData = new LLMMessageStructuredData(
            ['name' => 'Jane', 'email' => 'jane@example.com'],
            '{"name":"Jane","email":"jane@example.com"}',
        );

        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Extract info'),
            LLMMessage::createFromAssistant(new LLMMessageContents([$structuredData])),
            LLMMessage::createFromUserString('Now extract phone too'),
        ]);

        $request = new LLMRequest(
            model: new AnthropicClaude46Opus(),
            conversation: $conversation,
        );

        $encoded = $this->encoder->encodeRequest($request);

        // The assistant message with structured data should be encoded as text
        $this->assertEquals('assistant', $encoded['messages'][1]['role']);
        $this->assertEquals('text', $encoded['messages'][1]['content'][0]['type']);
        $this->assertEquals('{"name":"Jane","email":"jane@example.com"}', $encoded['messages'][1]['content'][0]['text']);
    }
}
