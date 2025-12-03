<?php

namespace Soukicz\Llm\Client\Gemini\Model;

class Gemini25Flash extends Gemini25FlashImagePreview {
    public function getCode(): string {
        return 'gemini-2.5-flash';
    }
}
