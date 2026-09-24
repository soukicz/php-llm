<?php

namespace Soukicz\Llm\Client\Gemini\Model;

/**
 * @deprecated Shut down by Google on December 2, 2025. Use Gemini31ProPreview instead.
 */
class Gemini25ProPreview extends GeminiModel {
    public const VERSION_03_25 = '03-25';

    public function __construct(
        private string $version
    ) {
    }

    public function getCode(): string {
        return 'gemini-2.5-pro-preview-' . $this->version;
    }

    public function getInputPricePerMillionTokens(): float {
        return 1.25;
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
