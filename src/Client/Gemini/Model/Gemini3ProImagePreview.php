<?php

namespace Soukicz\Llm\Client\Gemini\Model;

/**
 * @see https://ai.google.dev/gemini-api/docs/pricing
 */
class Gemini3ProImagePreview extends GeminiModel implements GeminiImageModel {
    public function __construct(
        private readonly ?string $imageAspectRatio = null,
        private readonly ?string $imageSize = null,
    ) {
    }

    public function getAspectRatio(): ?string {
        return $this->imageAspectRatio;
    }

    public function getImageSize(): ?string {
        return $this->imageSize;
    }

    public function getCode(): string {
        return 'gemini-3-pro-image-preview';
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
