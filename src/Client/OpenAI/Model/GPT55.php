<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

class GPT55 extends OpenAIModel {
    public const VERSION_2026_04_23 = '2026-04-23';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'gpt-5.5-' . $this->version;
    }

    public function getInputPricePerMillionTokens(): float {
        return 5.0;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 30.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.5;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
