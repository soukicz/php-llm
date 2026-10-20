<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\OpenAI;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\OpenAI\Model\GPT54;
use Soukicz\Llm\Client\OpenAI\OpenAIEncoder;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

class OpenAIEncoderEffortTest extends TestCase {
    /**
     * @return array<string, array{ReasoningEffort}>
     */
    public static function topEfforts(): array {
        return ['xhigh' => [ReasoningEffort::XHIGH], 'max' => [ReasoningEffort::MAX], 'extra_high' => [ReasoningEffort::EXTRA_HIGH]];
    }

    /**
     * @dataProvider topEfforts
     */
    public function testTopEffortsMapToXhigh(ReasoningEffort $effort): void {
        $encoded = (new OpenAIEncoder())->encodeRequest(new LLMRequest(
            model: new GPT54(GPT54::VERSION_2026_03_05),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hi')]),
            reasoningConfig: $effort,
        ));

        $this->assertSame('xhigh', $encoded['reasoning_effort']);
    }
}
