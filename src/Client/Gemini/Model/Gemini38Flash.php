<?php

namespace Soukicz\Llm\Client\Gemini\Model;

/**
 * Promotional pricing through December 31, 2026; from January 1, 2027 the
 * price doubles to $1.50 input / $7.50 output per 1M tokens.
 *
 * @see https://ai.google.dev/gemini-api/docs/pricing
 */
class Gemini38Flash extends GeminiModel {
    public function supportsThinkingLevel(): bool {
        return true;
    }

    public function getCode(): string {
        return 'gemini-3.8-flash';
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.75;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 3.75;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return $this->getInputPricePerMillionTokens();
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return $this->getOutputPricePerMillionTokens();
    }
}
