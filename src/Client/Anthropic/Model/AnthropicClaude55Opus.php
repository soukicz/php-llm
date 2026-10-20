<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

class AnthropicClaude55Opus extends AnthropicModel {
    public function getCode(): string {
        return 'claude-opus-5-5';
    }

    /**
     * Sampling parameters return a 400 on this model
     *
     * @see https://platform.claude.com/docs/en/about-claude/models/overview
     */
    public function supportsSamplingParameters(): bool {
        return false;
    }

    /**
     * Cache reads are billed at 0.05x the base input price.
     *
     * @see https://platform.claude.com/docs/en/about-claude/pricing
     */
    public function getInputPricePerMillionTokens(): float {
        return 4.0;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 20.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 5.0;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.2;
    }
}
