<?php

namespace Soukicz\Llm\Message;

class LLMMessageText implements LLMMessageContent {
    public function __construct(private string $text, private readonly bool $cached = false) {
    }

    public function getText(): string {
        return $this->text;
    }


    public function isCached(): bool {
        return $this->cached;
    }

    public function jsonSerialize(): array {
        return [
            'text' => $this->text,
            'cached' => $this->cached,
        ];
    }

    public static function fromJson(array $data): self {
        return new self($data['text'], $data['cached']);
    }
}
