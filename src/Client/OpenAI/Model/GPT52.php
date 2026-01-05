<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

class GPT52 extends OpenAIModel {
    public const VERSION_2025_12_11 = '2025-12-11';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'gpt-5.2-' . $this->version;
    }

    public function getInputPricePerMillionTokens(): float {
        return 1.75;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 14.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.175;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
