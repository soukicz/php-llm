<?php

namespace Soukicz\Llm\Message;

class LLMMessageText implements LLMMessageContent {
    public function __construct(private string $text, private readonly bool $cached = false) {
    }

    public function getText(): string {
        return $this->text;
    }

    public function mergeWith(self $text):self {
        $this->text .= $text->getText();

        return $this;
    }

    public function isCached(): bool {
        return $this->cached;
    }
}
