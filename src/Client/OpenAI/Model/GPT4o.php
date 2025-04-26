<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

class GPT4o extends OpenAIModel {
    public const VERSION_2024_11_20 = '2024-11-20';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'gpt-4o-' . $this->version;
    }

    public function getInputPricePerMillionTokens(): float {
        return 2.5;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 10.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 1.25;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
