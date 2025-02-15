<?php

namespace Soukicz\PhpLlm\Message;

class LLMMessagePdf implements LLMMessageContent {

    public function __construct(private readonly string $encoding, private readonly string $data) {
    }

    public function getEncoding(): string {
        return $this->encoding;
    }

    public function getData(): string {
        return $this->data;
    }

}
