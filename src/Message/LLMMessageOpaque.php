<?php

namespace Soukicz\Llm\Message;

/**
 * A provider content block this library has no type for (server tool use and results, future block types).
 * Kept exactly as received and sent back unchanged to the same provider; other providers reject it.
 */
class LLMMessageOpaque implements LLMMessageContent {
    public function __construct(private readonly string $provider, private readonly array $block) {
    }

    public function getProvider(): string {
        return $this->provider;
    }

    public function getBlock(): array {
        return $this->block;
    }

    public function getType(): string {
        return (string) ($this->block['type'] ?? '');
    }

    public function isCached(): bool {
        return false;
    }

    public function jsonSerialize(): array {
        return ['provider' => $this->provider, 'block' => $this->block];
    }

    public static function fromJson(array $data): self {
        return new self($data['provider'], $data['block']);
    }
}
