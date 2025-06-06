<?php

namespace Soukicz\Llm\Message;

class LLMMessageToolUse implements LLMMessageContent {

    public function __construct(private readonly string $id, private readonly string $name, private readonly array $input, private readonly bool $cached = false) {
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

    public function isCached(): bool {
        return $this->cached;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'input' => $this->input,
            'cached' => $this->cached,
        ];
    }

    public static function fromJson(array $data): self {
        return new self($data['id'], $data['name'], $data['input'], $data['cached']);
    }

}
