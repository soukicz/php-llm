<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

class AnthropicClaude41Opus extends AnthropicModel {
    public const VERSION_20250805 = '20250805';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'claude-opus-4-1-' . $this->version;
    }

    public function getInputPricePerMillionTokens(): float {
        return 15.0;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 75.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 18.75;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 1.5;
    }
}
