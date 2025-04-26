<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

class GPT41Mini extends OpenAIModel {
    public const VERSION_2025_04_14 = '2025-04-14';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'gpt-4.1-mini-' . $this->version;
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.4;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 1.6;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.1;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
