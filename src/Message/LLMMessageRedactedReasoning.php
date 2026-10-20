<?php

namespace Soukicz\Llm\Message;

/**
 * Encrypted reasoning (Anthropic `redacted_thinking`). Must be sent back unchanged when the conversation continues.
 */
class LLMMessageRedactedReasoning implements LLMMessageContent {
    public function __construct(private readonly string $data) {
    }

    public function getData(): string {
        return $this->data;
    }

    public function isCached(): bool {
        return false;
    }

    public function jsonSerialize(): array {
        return ['data' => $this->data];
    }

    public static function fromJson(array $data): self {
        return new self($data['data']);
    }
}
