<?php

namespace Soukicz\Llm\Client\Gemini\Model;

/**
 * Gemini 4 Argon (stable, 1M context). Pricing through November 30, 2026; from December 1, 2026 it doubles to
 * $4.00 input / $20.00 output per 1M tokens.
 *
 * @see https://ai.google.dev/gemini-api/docs/pricing (read 2026-10-20)
 * @see https://ai.google.dev/gemini-api/docs/models/gemini-4-argon
 */
class Gemini4Argon extends GeminiModel {
    public function supportsThinkingLevel(): bool {
        return true;
    }

    public function getCode(): string {
        return 'gemini-4-argon';
    }

    public function getInputPricePerMillionTokens(): float {
        return 2.0;
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
