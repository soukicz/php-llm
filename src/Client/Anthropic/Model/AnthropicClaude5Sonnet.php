<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

class AnthropicClaude5Sonnet extends AnthropicModel {
    public function getCode(): string {
        return 'claude-sonnet-5';
    }

    /**
     * The $2 / $10 introductory pricing became the standard price; the
     * previously announced increase to $3 / $15 on September 1, 2026 did not happen.
     *
     * @see https://platform.claude.com/docs/en/about-claude/pricing
     */
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
        return 0.2;
    }
}
