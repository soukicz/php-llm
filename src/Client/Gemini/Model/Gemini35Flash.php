<?php

namespace Soukicz\Llm\Client\Gemini\Model;

/**
 * @see https://ai.google.dev/gemini-api/docs/pricing
 */
class Gemini35Flash extends GeminiModel {
    public function supportsThinkingLevel(): bool {
        return true;
    }

    public function getCode(): string {
        return 'gemini-3.5-flash';
    }

    public function getInputPricePerMillionTokens(): float {
        return 1.5;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 9.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return $this->getInputPricePerMillionTokens();
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return $this->getOutputPricePerMillionTokens();
    }
}
