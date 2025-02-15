<?php

namespace Soukicz\PhpLlm\Message;

class LLMMessageText implements LLMMessageContent {
    private string $text;

    public function __construct(string $text) {
        $this->text = $text;
    }

    public function getText(): string {
        return $this->text;
    }

    public function mergeWith(self $text):self {
        $this->text .= $text->getText();

        return $this;
    }
}
