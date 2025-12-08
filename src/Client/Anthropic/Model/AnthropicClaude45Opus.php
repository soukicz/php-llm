<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

class AnthropicClaude45Opus extends AnthropicModel {
    public const VERSION_20251101 = '20251101';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'claude-opus-4-5-' . $this->version;
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
