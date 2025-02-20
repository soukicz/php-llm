<?php

namespace Soukicz\Llm;

class ToolResponse {
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
}
