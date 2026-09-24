<?php

namespace Soukicz\Llm\Client\Gemini\Model;

/**
 * Prices for prompts up to 200k tokens; longer prompts cost $4 input / $18 output.
 *
 * @see https://ai.google.dev/gemini-api/docs/pricing
 */
class Gemini31ProPreview extends GeminiModel {
    public function supportsThinkingLevel(): bool {
        return true;
    }

    public function getCode(): string {
        return 'gemini-3.1-pro-preview';
    }

    public function getInputPricePerMillionTokens(): float {
        return 2.0;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 12.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return $this->getInputPricePerMillionTokens();
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return $this->getOutputPricePerMillionTokens();
    }
}
