<?php

namespace Soukicz\Llm\Client\Gemini\Model;

class Gemini25Pro extends GeminiModel {
    public function getCode(): string {
        return 'gemini-2.5-pro';
    }

    public function getInputPricePerMillionTokens(): float {
        return 1.25;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 10.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return $this->getInputPricePerMillionTokens();
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return $this->getOutputPricePerMillionTokens();
    }
}