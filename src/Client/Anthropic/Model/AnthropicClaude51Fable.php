<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

class AnthropicClaude51Fable extends AnthropicModel {
    public function getCode(): string {
        return 'claude-fable-5-1';
    }

    public function supportsMidConversationSystemMessages(): bool {
        return true;
    }

    public function hasAdaptiveThinkingByDefault(): bool {
        return true;
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
     * Cache reads are billed at 0.025x the base input price.
     *
     * @see https://platform.claude.com/docs/en/about-claude/pricing
     */
    public function getInputPricePerMillionTokens(): float {
        return 10.0;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 50.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 12.5;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.25;
    }
}
