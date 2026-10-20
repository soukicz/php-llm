<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

class AnthropicClaude48Opus extends AnthropicModel {
    public function getCode(): string {
        return 'claude-opus-4-8';
    }

    /**
     * Sampling parameters return a 400 on this model
     *
     * @see https://platform.claude.com/docs/en/about-claude/models/overview
     */
    public function supportsSamplingParameters(): bool {
        return false;
    }

    public function getInputPricePerMillionTokens(): float {
        return 5.0;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 25.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 6.25;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.5;
    }
}
