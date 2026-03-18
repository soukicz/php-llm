<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

class GPT54Mini extends OpenAIModel {
    public const VERSION_2026_03_17 = '2026-03-17';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'gpt-5.4-mini-' . $this->version;
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.75;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 4.5;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.075;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
