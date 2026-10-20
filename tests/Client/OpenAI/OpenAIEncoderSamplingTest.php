<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\OpenAI;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\OpenAI\Model\GPT41;
use Soukicz\Llm\Client\OpenAI\OpenAIEncoder;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

class OpenAIEncoderSamplingTest extends TestCase {
    private function encode(?float $temperature): array {
        return (new OpenAIEncoder())->encodeRequest(new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hi')]),
            temperature: $temperature,
        ));
    }

    public function testNullTemperatureIsOmitted(): void {
        $this->assertArrayNotHasKey('temperature', $this->encode(null));
    }

    public function testExplicitTemperatureIsSent(): void {
        $this->assertSame(0.5, $this->encode(0.5)['temperature']);
    }
}
