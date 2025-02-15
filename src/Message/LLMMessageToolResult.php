<?php

namespace Soukicz\PhpLlm\Message;

class LLMMessageToolResult implements LLMMessageContent {
    private string $id;

    /** @var mixed */
    private $content;

    public function __construct(string $id, $content) {
        $this->id = $id;
        $this->content = $content;
    }

    public function getId(): string {
        return $this->id;
    }

    public function getContent() {
        return $this->content;
    }

}
