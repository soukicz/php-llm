<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

class AnthropicClaude35Sonnet extends AnthropicModel {
    public const VERSION_20241022 = '20241022';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'claude-3-5-sonnet-' . $this->version;
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
