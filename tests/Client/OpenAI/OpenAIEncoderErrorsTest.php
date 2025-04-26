<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\OpenAI;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\OpenAI\Model\GPT41;
use Soukicz\Llm\Client\OpenAI\OpenAIEncoder;
use Soukicz\Llm\Config\ReasoningBudget;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageReasoning;
use Soukicz\Llm\Message\LLMMessageText;

class OpenAIEncoderErrorsTest extends TestCase {
    private OpenAIEncoder $encoder;

    protected function setUp(): void {
        $this->encoder = new OpenAIEncoder();
    }

    public function testUnsupportedMessageTypeThrowsException(): void {
        // Create a conversation with an unsupported message type
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([
                new LLMMessageReasoning('This is reasoning', 'sig123', false),
            ]),
        ]);

        $request = new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported message type');

        $this->encoder->encodeRequest($request);
    }

    public function testUnsupportedReasoningConfigThrowsException(): void {
        // Create a request with unsupported reasoning config
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([new LLMMessageText('Hello')]),
        ]);

        $request = new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation,
            reasoningConfig: new ReasoningBudget(1000) // OpenAI only supports ReasoningEffort
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported reasoning config type');

        $this->encoder->encodeRequest($request);
    }

    public function testReasoningEffortValues(): void {
        // Test all enum values for ReasoningEffort
        $conversation = new LLMConversation([
            LLMMessage::createFromUser([new LLMMessageText('Hello')]),
        ]);

        // Test with LOW
        $requestLow = new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation,
            reasoningConfig: ReasoningEffort::LOW
        );

        $encodedLow = $this->encoder->encodeRequest($requestLow);
        $this->assertEquals('low', $encodedLow['reasoning_effort']);

        // Test with MEDIUM
        $requestMedium = new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation,
            reasoningConfig: ReasoningEffort::MEDIUM
        );

        $encodedMedium = $this->encoder->encodeRequest($requestMedium);
        $this->assertEquals('medium', $encodedMedium['reasoning_effort']);

        // Test with HIGH
        $requestHigh = new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: $conversation,
            reasoningConfig: ReasoningEffort::HIGH
        );

        $encodedHigh = $this->encoder->encodeRequest($requestHigh);
        $this->assertEquals('high', $encodedHigh['reasoning_effort']);
    }
}
