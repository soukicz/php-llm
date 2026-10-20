<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Gemini;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Gemini\GeminiEncoder;
use Soukicz\Llm\Client\Gemini\Model\Gemini38Flash;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

class GeminiEncoderEffortTest extends TestCase {
    /**
     * @return array<string, array{ReasoningEffort}>
     */
    public static function topEfforts(): array {
        return ['xhigh' => [ReasoningEffort::XHIGH], 'max' => [ReasoningEffort::MAX]];
    }

    /**
     * @dataProvider topEfforts
     */
    public function testTopEffortsMapToHigh(ReasoningEffort $effort): void {
        $encoded = (new GeminiEncoder())->encodeRequest(new LLMRequest(
            model: new Gemini38Flash(),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hi')]),
            reasoningConfig: $effort,
        ));

        $this->assertSame(['thinkingLevel' => 'high'], $encoded['generationConfig']['thinkingConfig']);
    }
}
