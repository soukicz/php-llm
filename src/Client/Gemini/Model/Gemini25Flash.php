<?php

namespace Soukicz\Llm\Client\Gemini\Model;

class Gemini25Flash extends GeminiModel {
    public function getCode(): string {
        return 'gemini-2.5-flash';
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.30;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 2.50;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return $this->getInputPricePerMillionTokens();
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return $this->getOutputPricePerMillionTokens();
    }
}
