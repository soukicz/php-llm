<?php

namespace Soukicz\Llm\Message;

class LLMMessageToolResult implements LLMMessageContent {
    public function __construct(private readonly string $id, private readonly mixed $content, private readonly bool $cached = false) {
    }

    public function getId(): string {
        return $this->id;
    }

    public function getContent() {
        return $this->content;
    }

    public function isCached(): bool {
        return $this->cached;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'cached' => $this->cached,
        ];
    }

    public static function fromJson(array $data): self {
        return new self($data['id'], $data['content'], $data['cached']);
    }

}
