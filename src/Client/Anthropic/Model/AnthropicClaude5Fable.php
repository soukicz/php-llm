<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

class AnthropicClaude5Fable extends AnthropicModel {
    public function getCode(): string {
        return 'claude-fable-5';
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
        return 1.0;
    }
}
