<?php

namespace Soukicz\Llm\Client\Gemini\Model;

class Gemini25FlashImagePreview extends GeminiModel {
    public function getCode(): string {
        return 'gemini-2.5-flash-image-preview';
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.30;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 30;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return $this->getInputPricePerMillionTokens();
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return $this->getOutputPricePerMillionTokens();
    }
}
