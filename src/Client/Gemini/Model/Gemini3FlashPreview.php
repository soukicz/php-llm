<?php

namespace Soukicz\Llm\Client\Gemini\Model;

/**
 * @see https://ai.google.dev/gemini-api/docs/pricing
 */
class Gemini3FlashPreview extends GeminiModel {
    public function supportsThinkingLevel(): bool {
        return true;
    }

    public function getCode(): string {
        return 'gemini-3-flash-preview';
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.5;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 3.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return $this->getInputPricePerMillionTokens();
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return $this->getOutputPricePerMillionTokens();
    }
}
