<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

/**
 * Claude Haiku 5.5: 1M context, 128K output, adaptive thinking on by default (effort low..max, default medium),
 * vision and PDF input. Priced by prompt length: a request whose prompt (input + cache reads + cache writes) is
 * over 100,000 tokens pays the long-prompt rate card for all of its tokens.
 *
 * @see https://platform.claude.com/docs/en/about-claude/pricing (read 2026-10-20)
 */
class AnthropicClaude55Haiku extends AnthropicModel {
    private const LONG_PROMPT_THRESHOLD_TOKENS = 100_000;

    public function getCode(): string {
        return 'claude-haiku-5-5';
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.1;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 0.5;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.125;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.01;
    }

    public function getPrices(int $promptTokens): AnthropicPrices {
        if ($promptTokens > self::LONG_PROMPT_THRESHOLD_TOKENS) {
            return new AnthropicPrices(input: 0.5, output: 2.5, cacheWrite5m: 0.625, cacheWrite1h: 1.0, cacheRead: 0.05);
        }

        return parent::getPrices($promptTokens);
    }

    /**
     * Non-default sampling values return a 400 on this model
     */
    public function supportsSamplingParameters(): bool {
        return false;
    }

    public function hasAdaptiveThinkingByDefault(): bool {
        return true;
    }

    public function supportsMidConversationSystemMessages(): bool {
        return true;
    }
}
