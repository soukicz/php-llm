<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude48Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude51Fable;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Haiku;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Fable;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicModel;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\Config\ThinkingDisplay;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

class AnthropicEncoderThinkingDisplayTest extends TestCase {
    private function request(AnthropicModel $model, ?ThinkingDisplay $display, ?ReasoningEffort $effort = null): LLMRequest {
        return new LLMRequest(
            model: $model,
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hi')]),
            reasoningConfig: $effort,
            thinkingDisplay: $display,
        );
    }

    public function testDisplayJoinsTheThinkingObject(): void {
        $encoded = (new AnthropicEncoder())->encodeRequest($this->request(new AnthropicClaude55Opus(), ThinkingDisplay::UPDATES, ReasoningEffort::HIGH));

        $this->assertSame(['type' => 'adaptive', 'display' => 'updates'], $encoded['thinking']);
        $this->assertSame('high', $encoded['output_config']['effort']);
    }

    public function testDisplayAloneOnAModelThatThinksByDefault(): void {
        $encoded = (new AnthropicEncoder())->encodeRequest($this->request(new AnthropicClaude55Opus(), ThinkingDisplay::SUMMARIZED));

        $this->assertSame(['type' => 'adaptive', 'display' => 'summarized'], $encoded['thinking']);
    }

    public function testDisplayIsIgnoredWhenNoThinkingRuns(): void {
        $encoded = (new AnthropicEncoder())->encodeRequest($this->request(new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929), ThinkingDisplay::SUMMARIZED));

        $this->assertArrayNotHasKey('thinking', $encoded);
    }

    public function testUpdatesNeedTheirBetaHeader(): void {
        $encoder = new AnthropicEncoder();

        $this->assertSame(['thinking-display-updates-2026-08-18'], $encoder->getRequestBetas($this->request(new AnthropicClaude55Opus(), ThinkingDisplay::UPDATES)));
        $this->assertSame([], $encoder->getRequestBetas($this->request(new AnthropicClaude55Opus(), ThinkingDisplay::SUMMARIZED)));
        $this->assertSame([], $encoder->getRequestBetas($this->request(new AnthropicClaude55Opus(), null)));
    }

    public function testUpdatesAreOnlySentToModelsThatSupportThem(): void {
        $encoder = new AnthropicEncoder();
        foreach ([new AnthropicClaude5Fable(), new AnthropicClaude51Fable(), new AnthropicClaude55Opus(), new AnthropicClaude55Sonnet()] as $model) {
            $request = $this->request($model, ThinkingDisplay::UPDATES);
            $this->assertSame('updates', $encoder->encodeRequest($request)['thinking']['display'], $model::class);
            $this->assertSame(['thinking-display-updates-2026-08-18'], $encoder->getRequestBetas($request), $model::class);
        }

        foreach ([new AnthropicClaude5Opus(), new AnthropicClaude48Opus(), new AnthropicClaude5Sonnet(), new AnthropicClaude55Haiku()] as $model) {
            $request = $this->request($model, ThinkingDisplay::UPDATES, ReasoningEffort::HIGH);
            $this->assertArrayNotHasKey('display', $encoder->encodeRequest($request)['thinking'] ?? [], $model::class);
            $this->assertSame([], $encoder->getRequestBetas($request), $model::class);
        }
    }

    public function testOtherDisplaysAreNotGatedByTheUpdatesFlag(): void {
        $encoded = (new AnthropicEncoder())->encodeRequest($this->request(new AnthropicClaude5Opus(), ThinkingDisplay::SUMMARIZED, ReasoningEffort::HIGH));

        $this->assertSame('summarized', $encoded['thinking']['display']);
    }
}
