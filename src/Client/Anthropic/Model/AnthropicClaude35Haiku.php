<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

class AnthropicClaude35Haiku extends AnthropicModel {
    public const VERSION_20241022 = '20241022';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'claude-3-5-haiku-' . $this->version;
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.8;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 4.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 1.0;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.08;
    }
}
