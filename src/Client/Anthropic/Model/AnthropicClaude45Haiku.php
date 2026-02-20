<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

class AnthropicClaude45Haiku extends AnthropicModel {
    public const VERSION_20251001 = '20251001';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'claude-haiku-4-5-' . $this->version;
    }

    public function getInputPricePerMillionTokens(): float {
        return 1.0;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 5.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 1.25;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.10;
    }
}
