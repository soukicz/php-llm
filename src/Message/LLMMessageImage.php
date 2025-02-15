<?php

namespace Soukicz\PhpLlm\Message;

class LLMMessageImage implements LLMMessageContent {
    private string $encoding;
    private string $mediaType;
    private string $data;

    public function __construct(string $encoding, string $mediaType, string $data) {
        $this->encoding = $encoding;
        $this->mediaType = $mediaType;
        $this->data = $data;
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

}
