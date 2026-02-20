<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

class AnthropicClaude46Sonnet extends AnthropicModel {
    public function getCode(): string {
        return 'claude-sonnet-4-6';
    }

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
