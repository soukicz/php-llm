<?php

namespace Soukicz\PhpLlm\Message;

class LLMMessageToolUse implements LLMMessageContent {
    private string $id;
    private string $name;
    private array $input;

    public function __construct(string $id, string $name, array $input) {
        $this->id = $id;
        $this->name = $name;
        $this->input = $input;
    }

    public function getId(): string {
        return $this->id;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getInput(): array {
        return $this->input;
    }

}
