<?php

namespace Soukicz\Llm\Client\Gemini\Model;

class Gemini20FlashLite extends GeminiModel {
    public function getCode(): string {
        return 'gemini-2.0-flash-lite';
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.075;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 0.30;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return $this->getInputPricePerMillionTokens();
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return $this->getOutputPricePerMillionTokens();
    }
}
