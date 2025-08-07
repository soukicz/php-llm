<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

class GPT5Mini extends OpenAIModel {
    public const VERSION_2025_08_07 = '2025-08-07';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'gpt-5-mini-' . $this->version;
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.25;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 2.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.025;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
