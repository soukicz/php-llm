<?php

namespace Soukicz\PhpLlm\Message;

class LLMMessageText implements LLMMessageContent {
    public function __construct(private string $text) {
    }

    public function getText(): string {
        return $this->text;
    }

    public function mergeWith(self $text):self {
        $this->text .= $text->getText();

        return $this;
    }
}
