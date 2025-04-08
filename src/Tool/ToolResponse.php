<?php

namespace Soukicz\Llm\Tool;

use Soukicz\Llm\JsonDeserializable;

class ToolResponse implements JsonDeserializable {
    private mixed $data;

    public function __construct(mixed $data) {
        $this->data = $data;
    }

    /**
     * @return mixed
     */
    public function getData() {
        return $this->data;
    }

    public function jsonSerialize(): array {
        return [
            'data' => $this->data,
        ];
    }

    public static function fromJson(array $data): self {
        return new self($data['data']);
    }
}
