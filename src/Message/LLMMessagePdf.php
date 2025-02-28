<?php

namespace Soukicz\Llm\Message;

class LLMMessagePdf implements LLMMessageContent {

    public function __construct(private readonly string $encoding, private readonly string $data, private readonly bool $cached = false) {
    }

    public function getEncoding(): string {
        return $this->encoding;
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
            'data' => $this->data,
            'cached' => $this->cached,
        ];
    }

    public static function fromJson(array $data): self {
        return new self($data['encoding'], $data['data'], $data['cached']);
    }
}
