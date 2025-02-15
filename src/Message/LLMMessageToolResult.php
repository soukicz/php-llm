<?php

namespace Soukicz\PhpLlm\Message;

class LLMMessageToolResult implements LLMMessageContent {
    public function __construct(private readonly string $id, private readonly mixed $content) {
    }

    public function getId(): string {
        return $this->id;
    }

    public function getContent() {
        return $this->content;
    }

}
