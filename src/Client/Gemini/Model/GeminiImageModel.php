<?php

namespace Soukicz\Llm\Client\Gemini\Model;

interface GeminiImageModel {
    public function getAspectRatio(): ?string;

    public function getImageSize(): ?string;
}
