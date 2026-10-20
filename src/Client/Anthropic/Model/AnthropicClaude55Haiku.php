<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

/**
 * Prices for prompts up to 100k tokens; longer prompts cost $0.50 input / $2.50 output.
 */
class AnthropicClaude55Haiku extends AnthropicModel {
    public function getCode(): string {
        return 'claude-haiku-5-5';
    }

    /**
     * Cache reads are billed at 0.1x the base input price.
     *
     * @see https://platform.claude.com/docs/en/about-claude/pricing
     */
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
}
