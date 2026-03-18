<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

class GPT54 extends OpenAIModel {
    public const VERSION_2026_03_05 = '2026-03-05';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'gpt-5.4-' . $this->version;
    }

    public function getInputPricePerMillionTokens(): float {
        return 2.5;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 15.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.25;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
