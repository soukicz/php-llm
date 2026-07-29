<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

class AnthropicClaude5Opus extends AnthropicModel {
    public function getCode(): string {
        return 'claude-opus-5';
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
