<?php

namespace Soukicz\PhpLlm\Client;

class ModelResponse {
    public function __construct(private readonly array $data, private readonly int $responseTimeMs) {

    }

    public function getData(): array {
        return $this->data;
    }

    public function getResponseTimeMs(): int {
        return $this->responseTimeMs;
    }
}
