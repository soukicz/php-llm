<?php

namespace Soukicz\Llm\Client\Gemini\Model;

/**
 * @see https://ai.google.dev/gemini-api/docs/pricing
 * @see https://ai.google.dev/gemini-api/docs/image-generation
 */
class GeminiNanoBanana21 extends GeminiModel implements GeminiImageModel {
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

    public function supportsThinkingLevel(): bool {
        return true;
    }

    public function getCode(): string {
        return 'gemini-nano-banana-2.1';
    }

    public function getInputPricePerMillionTokens(): float {
        return 1.5;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 30.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return $this->getInputPricePerMillionTokens();
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return $this->getOutputPricePerMillionTokens();
    }
}
