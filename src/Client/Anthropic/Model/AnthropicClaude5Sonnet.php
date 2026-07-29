<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

class AnthropicClaude5Sonnet extends AnthropicModel {
    public function getCode(): string {
        return 'claude-sonnet-5';
    }

    /**
     * Standard pricing, effective September 1, 2026. Introductory pricing of
     * $2 / $10 per MTok (cache write $2.50, cache read $0.20) applies through
     * August 31, 2026.
     *
     * @see https://platform.claude.com/docs/en/about-claude/pricing
     */
    public function getInputPricePerMillionTokens(): float {
        return 3.0;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 15.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 3.75;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.3;
    }
}
