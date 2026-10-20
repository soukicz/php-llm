<?php

namespace Soukicz\Llm\Config;

/**
 * Server-side clearing of old tool results (Anthropic `clear_tool_uses_20250919`). Clearing invalidates the
 * prompt cache from the cleared point on; clearAtLeastInputTokens makes each clearing worth it.
 *
 * @see https://platform.claude.com/docs/en/build-with-claude/context-editing
 */
final readonly class ClearToolUses {
    /**
     * @param int|null $triggerInputTokens prompt size that starts clearing (API default 100,000)
     * @param int|null $keepToolUses most recent tool use/result pairs kept (API default 3)
     * @param bool $clearToolInputs also clear the tool_use inputs, not only the results
     * @param string[] $excludeTools tool names never cleared
     * @param int|null $clearAtLeastInputTokens skip clearing that would free fewer tokens
     */
    public function __construct(
        public ?int $triggerInputTokens = null,
        public ?int $keepToolUses = null,
        public bool $clearToolInputs = false,
        public array $excludeTools = [],
        public ?int $clearAtLeastInputTokens = null,
    ) {
    }

    public function toArray(): array {
        $edit = ['type' => 'clear_tool_uses_20250919'];
        if ($this->triggerInputTokens !== null) {
            $edit['trigger'] = ['type' => 'input_tokens', 'value' => $this->triggerInputTokens];
        }
        if ($this->keepToolUses !== null) {
            $edit['keep'] = ['type' => 'tool_uses', 'value' => $this->keepToolUses];
        }
        if ($this->clearAtLeastInputTokens !== null) {
            $edit['clear_at_least'] = ['type' => 'input_tokens', 'value' => $this->clearAtLeastInputTokens];
        }
        if ($this->excludeTools !== []) {
            $edit['exclude_tools'] = array_values($this->excludeTools);
        }
        if ($this->clearToolInputs) {
            $edit['clear_tool_inputs'] = true;
        }

        return $edit;
    }
}
