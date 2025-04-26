<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

class GPTo4Mini extends OpenAIModel {
    public const VERSION_2025_04_16 = '2025-04-16';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'o4-mini-' . $this->version;
    }

    public function getInputPricePerMillionTokens(): float {
        return 1.1;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 4.4;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.275;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
