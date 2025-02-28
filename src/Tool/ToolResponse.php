<?php

namespace Soukicz\Llm\Tool;

use Soukicz\Llm\JsonDeserializable;

class ToolResponse implements JsonDeserializable {
    private string $id;
    private $data;

    public function __construct(string $id, $data) {
        $this->id = $id;
        $this->data = $data;
    }

    public function getId(): string {
        return $this->id;
    }

    /**
     * @return mixed
     */
    public function getData() {
        return $this->data;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'data' => $this->data,
        ];
    }

    public static function fromJson(array $data): self {
        return new self($data['id'], $data['data']);
    }
}
