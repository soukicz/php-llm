<?php

namespace Soukicz\Llm\Config;

/**
 * Server-side context editing (Anthropic only; other providers ignore it). It edits what the model sees, not
 * the stored conversation, so it keeps replayed thinking blocks valid where client-side pruning would not.
 */
final readonly class ContextManagement {
    public function __construct(
        public ?ClearToolUses $clearToolUses = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toAnthropicEdits(): array {
        return $this->clearToolUses === null ? [] : [$this->clearToolUses->toArray()];
    }
}
