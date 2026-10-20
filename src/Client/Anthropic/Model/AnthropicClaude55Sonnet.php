<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

/**
 * Claude Sonnet 5.5: 1M context, 128K output, adaptive thinking on by default (effort low..max, default high),
 * vision and PDF input. Cache reads are billed at 0.05x the base input price.
 *
 * @see https://platform.claude.com/docs/en/about-claude/pricing (read 2026-10-20)
 */
class AnthropicClaude55Sonnet extends AnthropicModel {
    public function getCode(): string {
        return 'claude-sonnet-5-5';
    }

    public function getInputPricePerMillionTokens(): float {
        return 2.0;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 10.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 2.5;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.1;
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
