<?php

namespace Soukicz\Llm\Client\Gemini\Model;

/**
 * @see https://ai.google.dev/gemini-api/docs/pricing
 */
class Gemini35FlashLite extends GeminiModel {
    public function supportsThinkingLevel(): bool {
        return true;
    }

    public function getCode(): string {
        return 'gemini-3.5-flash-lite';
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.3;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 2.5;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return $this->getInputPricePerMillionTokens();
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return $this->getOutputPricePerMillionTokens();
    }
}
