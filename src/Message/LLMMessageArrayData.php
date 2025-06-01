<?php

namespace Soukicz\Llm\Message;

class LLMMessageArrayData implements LLMMessageContent {
    public function __construct(private readonly array $data, private readonly bool $cached = false) {
    }

    public function getData(): array {
        return $this->data;
    }

    public function isCached(): bool {
        return $this->cached;
    }

    public function jsonSerialize(): array {
        return [
            'data' => $this->data,
            'cached' => $this->cached,
        ];
    }

    public static function fromJson(array $data): self {
        return new self($data['data'], $data['cached']);
    }
}
