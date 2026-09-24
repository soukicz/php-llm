<?php

namespace Soukicz\Llm\Client\Gemini\Model;

/**
 * @deprecated Shut down by Google on June 1, 2026. Use Gemini36Flash instead.
 */
class Gemini20Flash extends GeminiModel {
    public function getCode(): string {
        return 'gemini-2.0-flash';
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.10;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 0.40;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return $this->getInputPricePerMillionTokens();
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return $this->getOutputPricePerMillionTokens();
    }
}
