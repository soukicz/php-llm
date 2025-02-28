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

    public function mergeWith(self $text): self {
        $this->text .= $text->getText();

        return $this;
    }

    public function isCached(): bool {
        return $this->cached;
    }
}
