<?php

namespace Soukicz\Llm\Message;

class LLMMessageReasoning implements LLMMessageContent {
    public function __construct(private string $text, private readonly ?string $signature, private readonly bool $cached = false) {
    }

    public function getText(): string {
        return $this->text;
    }

    public function getSignature(): ?string {
        return $this->signature;
    }


    public function isCached(): bool {
        return $this->cached;
    }

    public function jsonSerialize(): array {
        return [
            'text' => $this->text,
            'signature' => $this->signature,
            'cached' => $this->cached,
        ];
    }

    public static function fromJson(array $data): self {
        return new self(
            $data['text'],
            $data['signature'] ?? null,
            $data['cached'] ?? false,
        );
    }
}
