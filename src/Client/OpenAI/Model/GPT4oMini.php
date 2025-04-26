<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

class GPT4oMini extends OpenAIModel {
    public const VERSION_2024_07_18 = '2024-07-18';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'gpt-4o-mini-' . $this->version;
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.15;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 0.6;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.075;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
