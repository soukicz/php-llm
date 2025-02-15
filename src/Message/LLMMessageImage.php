<?php

namespace Soukicz\PhpLlm\Message;

class LLMMessageImage implements LLMMessageContent {

    public function __construct(private readonly string $encoding, private readonly string $mediaType, private readonly string $data, private readonly bool $cached = false) {
    }

    public function getEncoding(): string {
        return $this->encoding;
    }

    public function getMediaType(): string {
        return $this->mediaType;
    }

    public function getData(): string {
        return $this->data;
    }

    public function isCached(): bool {
        return $this->cached;
    }

}
