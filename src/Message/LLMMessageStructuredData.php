<?php

namespace Soukicz\Llm\Message;

class LLMMessageStructuredData implements LLMMessageContent {
    /**
     * @param array $data The parsed JSON data (decoded from the model's JSON response)
     * @param string $rawJson The original raw JSON string from the model
     */
    public function __construct(
        private readonly array $data,
        private readonly string $rawJson,
        private readonly bool $cached = false,
    ) {
    }

    public function getData(): array {
        return $this->data;
    }

    public function getRawJson(): string {
        return $this->rawJson;
    }

    public function isCached(): bool {
        return $this->cached;
    }

    public function jsonSerialize(): array {
        return [
            'data' => $this->data,
            'rawJson' => $this->rawJson,
            'cached' => $this->cached,
        ];
    }

    public static function fromJson(array $data): self {
        return new self(
            $data['data'],
            $data['rawJson'],
            $data['cached'] ?? false,
        );
    }
}
