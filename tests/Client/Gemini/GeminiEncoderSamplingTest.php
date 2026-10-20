<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Gemini;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Gemini\GeminiEncoder;
use Soukicz\Llm\Client\Gemini\Model\Gemini38Flash;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

class GeminiEncoderSamplingTest extends TestCase {
    public function testNullTemperatureIsOmitted(): void {
        $encoded = (new GeminiEncoder())->encodeRequest(new LLMRequest(
            model: new Gemini38Flash(),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hi')]),
            temperature: null,
        ));

        $this->assertArrayNotHasKey('temperature', $encoded['generationConfig']);
        $this->assertArrayHasKey('maxOutputTokens', $encoded['generationConfig']);
    }
}
