<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

class GPT41Nano extends OpenAIModel {
    public const VERSION_2025_04_14 = '2025-04-14';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'gpt-4.1-nano-' . $this->version;
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.1;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 0.4;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.025;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
