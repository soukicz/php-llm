<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

class GPTo3 extends OpenAIModel {
    public const VERSION_2025_04_16 = '2025-04-16';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'o3-' . $this->version;
    }

    public function getInputPricePerMillionTokens(): float {
        return 10;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 40.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 2.5;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
