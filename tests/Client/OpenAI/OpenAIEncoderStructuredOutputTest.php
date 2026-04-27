<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\OpenAI;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\Client\OpenAI\Model\GPT41;
use Soukicz\Llm\Client\OpenAI\OpenAIEncoder;
use Soukicz\Llm\Config\StructuredOutputConfig;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageStructuredData;
use Soukicz\Llm\Message\LLMMessageText;

class OpenAIEncoderStructuredOutputTest extends TestCase {
    private OpenAIEncoder $encoder;

    private array $testSchema = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
        ],
        'required' => ['name', 'age'],
        'additionalProperties' => false,
    ];

    protected function setUp(): void {
        $this->encoder = new OpenAIEncoder();
    }

    public function testEncodeRequestWithStructuredOutput(): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Extract: John is 30 years old'),
        ]);

        $request = new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation,
            structuredOutputConfig: new StructuredOutputConfig($this->testSchema),
        );

        $encoded = $this->encoder->encodeRequest($request);

        $this->assertArrayHasKey('response_format', $encoded);
        $this->assertEquals('json_schema', $encoded['response_format']['type']);
        $this->assertEquals('response_schema', $encoded['response_format']['json_schema']['name']);
        $this->assertTrue($encoded['response_format']['json_schema']['strict']);
        $this->assertEquals($this->testSchema, $encoded['response_format']['json_schema']['schema']);
    }

    public function testEncodeRequestWithStrictDisabled(): void {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
        ];

        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Test'),
        ]);

        $request = new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation,
            structuredOutputConfig: new StructuredOutputConfig($schema, strict: false),
        );

        $encoded = $this->encoder->encodeRequest($request);

        $this->assertFalse($encoded['response_format']['json_schema']['strict']);
        $this->assertFalse($encoded['response_format']['json_schema']['schema']['additionalProperties']);
    }

    public function testEncodeRequestWithoutStructuredOutput(): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Hello'),
        ]);

        $request = new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation,
        );

        $encoded = $this->encoder->encodeRequest($request);

        $this->assertArrayNotHasKey('response_format', $encoded);
    }

    public function testDecodeResponseWithStructuredOutput(): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Extract: John is 30 years old'),
        ]);

        $request = new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation,
            structuredOutputConfig: new StructuredOutputConfig($this->testSchema),
        );

        $responseData = [
            'choices' => [
                [
                    'message' => [
                        'content' => '{"name":"John","age":30}',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ];

        $result = $this->encoder->decodeResponse($request, new ModelResponse($responseData, 100));

        $this->assertInstanceOf(LLMResponse::class, $result);
        $lastMessage = $result->getConversation()->getLastMessage();
        $contents = iterator_to_array($lastMessage->getContents());
        $this->assertCount(1, $contents);
        $this->assertInstanceOf(LLMMessageStructuredData::class, $contents[0]);
        $this->assertEquals(['name' => 'John', 'age' => 30], $contents[0]->getData());
        $this->assertEquals('{"name":"John","age":30}', $contents[0]->getRawJson());
    }

    public function testDecodeResponseWithoutStructuredOutputCreatesText(): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Hello'),
        ]);

        $request = new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation,
        );

        $responseData = [
            'choices' => [
                [
                    'message' => [
                        'content' => 'Hello there!',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 5,
                'completion_tokens' => 3,
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
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation,
            structuredOutputConfig: new StructuredOutputConfig($schema),
        );

        $encoded = $this->encoder->encodeRequest($request);

        $normalizedSchema = $encoded['response_format']['json_schema']['schema'];
        $this->assertFalse($normalizedSchema['additionalProperties']);
        $this->assertFalse($normalizedSchema['properties']['address']['additionalProperties']);
    }

    public function testEncodeStructuredDataContentForRoundTripping(): void {
        $structuredData = new LLMMessageStructuredData(
            ['name' => 'John', 'age' => 30],
            '{"name":"John","age":30}',
        );

        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Extract info'),
            LLMMessage::createFromAssistant(new LLMMessageContents([$structuredData])),
            LLMMessage::createFromUserString('Now extract email too'),
        ]);

        $request = new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation,
        );

        $encoded = $this->encoder->encodeRequest($request);

        // The assistant message with structured data should be encoded as text
        $this->assertEquals('assistant', $encoded['messages'][1]['role']);
        $this->assertEquals('text', $encoded['messages'][1]['content'][0]['type']);
        $this->assertEquals('{"name":"John","age":30}', $encoded['messages'][1]['content'][0]['text']);
    }
}
