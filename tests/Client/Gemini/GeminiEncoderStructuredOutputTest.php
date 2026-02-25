<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Gemini;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Gemini\GeminiEncoder;
use Soukicz\Llm\Client\Gemini\Model\Gemini20Flash;
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\Config\StructuredOutputConfig;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageStructuredData;
use Soukicz\Llm\Message\LLMMessageText;

class GeminiEncoderStructuredOutputTest extends TestCase {
    private GeminiEncoder $encoder;

    private array $testSchema = [
        'type' => 'object',
        'properties' => [
            'city' => ['type' => 'string'],
            'population' => ['type' => 'integer'],
        ],
        'required' => ['city', 'population'],
    ];

    protected function setUp(): void {
        $this->encoder = new GeminiEncoder();
    }

    public function testEncodeRequestWithStructuredOutput(): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Tell me about Prague'),
        ]);

        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: $conversation,
            structuredOutputConfig: new StructuredOutputConfig($this->testSchema),
        );

        $encoded = $this->encoder->encodeRequest($request);

        $this->assertEquals('application/json', $encoded['generationConfig']['responseMimeType']);
        $this->assertEquals($this->testSchema, $encoded['generationConfig']['responseSchema']);
    }

    public function testEncodeRequestWithoutStructuredOutput(): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Hello'),
        ]);

        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: $conversation,
        );

        $encoded = $this->encoder->encodeRequest($request);

        $this->assertArrayNotHasKey('responseMimeType', $encoded['generationConfig']);
        $this->assertArrayNotHasKey('responseSchema', $encoded['generationConfig']);
    }

    public function testDecodeResponseWithStructuredOutput(): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Tell me about Prague'),
        ]);

        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: $conversation,
            structuredOutputConfig: new StructuredOutputConfig($this->testSchema),
        );

        $responseData = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => '{"city":"Prague","population":1300000}'],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 8,
                'candidatesTokenCount' => 6,
            ],
        ];

        $result = $this->encoder->decodeResponse($request, new ModelResponse($responseData, 120));

        $this->assertInstanceOf(LLMResponse::class, $result);
        $lastMessage = $result->getConversation()->getLastMessage();
        $contents = iterator_to_array($lastMessage->getContents());
        $this->assertCount(1, $contents);
        $this->assertInstanceOf(LLMMessageStructuredData::class, $contents[0]);
        $this->assertEquals(['city' => 'Prague', 'population' => 1300000], $contents[0]->getData());
        $this->assertEquals('{"city":"Prague","population":1300000}', $contents[0]->getRawJson());
    }

    public function testDecodeResponseWithoutStructuredOutputCreatesText(): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Hello'),
        ]);

        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: $conversation,
        );

        $responseData = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Hello there!'],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 3,
                'candidatesTokenCount' => 2,
            ],
        ];

        $result = $this->encoder->decodeResponse($request, new ModelResponse($responseData, 80));

        $this->assertInstanceOf(LLMResponse::class, $result);
        $lastMessage = $result->getConversation()->getLastMessage();
        $contents = iterator_to_array($lastMessage->getContents());
        $this->assertInstanceOf(LLMMessageText::class, $contents[0]);
    }

    public function testEncodeStructuredDataContentForRoundTripping(): void {
        $structuredData = new LLMMessageStructuredData(
            ['city' => 'Prague', 'population' => 1300000],
            '{"city":"Prague","population":1300000}',
        );

        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Tell me about Prague'),
            LLMMessage::createFromAssistant(new LLMMessageContents([$structuredData])),
            LLMMessage::createFromUserString('Now tell me about Brno'),
        ]);

        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: $conversation,
        );

        $encoded = $this->encoder->encodeRequest($request);

        // The assistant message with structured data should be encoded as text part
        $this->assertEquals('model', $encoded['contents'][1]['role']);
        $this->assertEquals('{"city":"Prague","population":1300000}', $encoded['contents'][1]['parts'][0]['text']);
    }

    public function testStripsAdditionalProperties(): void {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];

        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Test'),
        ]);

        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: $conversation,
            structuredOutputConfig: new StructuredOutputConfig($schema),
        );

        $encoded = $this->encoder->encodeRequest($request);

        $this->assertArrayNotHasKey('additionalProperties', $encoded['generationConfig']['responseSchema']);
    }

    public function testStripsNestedAdditionalProperties(): void {
        $schema = [
            'type' => 'object',
            'properties' => [
                'address' => [
                    'type' => 'object',
                    'properties' => [
                        'city' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['address'],
        ];

        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('Test'),
        ]);

        $request = new LLMRequest(
            model: new Gemini20Flash(),
            conversation: $conversation,
            structuredOutputConfig: new StructuredOutputConfig($schema),
        );

        $encoded = $this->encoder->encodeRequest($request);

        $this->assertArrayNotHasKey('additionalProperties', $encoded['generationConfig']['responseSchema']);
        $this->assertArrayNotHasKey('additionalProperties', $encoded['generationConfig']['responseSchema']['properties']['address']);
    }
}
