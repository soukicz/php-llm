<?php

namespace Soukicz\Llm\Config;

class StructuredOutputConfig {
    /**
     * @param array $schema Raw JSON Schema array
     */
    public function __construct(private readonly array $schema) {
    }

    public function getSchema(): array {
        return $this->schema;
    }
}
