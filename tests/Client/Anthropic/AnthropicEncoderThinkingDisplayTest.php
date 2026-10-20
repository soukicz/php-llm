<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Opus;
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
}
