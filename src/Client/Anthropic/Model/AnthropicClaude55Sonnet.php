<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

class AnthropicClaude55Sonnet extends AnthropicModel {
    public function getCode(): string {
        return 'claude-sonnet-5-5';
    }

    /**
     * Same pricing as Claude Sonnet 5. Cache reads are billed at 0.1x the base input price.
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
