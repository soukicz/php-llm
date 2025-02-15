<?php

namespace Soukicz\PhpLlm\Message;

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

}
