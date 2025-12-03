<?php

namespace Soukicz\Llm\Client\Gemini\Model;

/**
 * @see https://ai.google.dev/gemini-api/docs/pricing
 */
class Gemini25FlashImage extends Gemini25FlashImagePreview {
    public function getCode(): string {
        return 'gemini-2.5-flash-image';
    }
}
