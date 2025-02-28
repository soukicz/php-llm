<?php

namespace Soukicz\Llm\Message;

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

    public function jsonSerialize(): array {
        return [
            'encoding' => $this->encoding,
            'mediaType' => $this->mediaType,
            'data' => $this->data,
            'cached' => $this->cached,
        ];
    }

    public static function fromJson(array $data): self {
        return new self(
            $data['encoding'],
            $data['mediaType'],
            $data['data'],
            $data['cached'],
        );
    }
}
