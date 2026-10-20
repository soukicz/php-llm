<?php

namespace Soukicz\Llm\Agent;

use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\Usage\TokenUsage;

/**
 * The agent run stopped before the model finished. The conversation is everything so far and is valid to
 * persist and replay: open tool uses are closed with error results, a refused turn is left out.
 */
final class AgentRunInterruptedException extends \RuntimeException {
    public const REASON_ITERATIONS = 'iterations';
    public const REASON_COST = 'cost';
    public const REASON_DEADLINE = 'deadline';
    public const REASON_CANCELLED = 'cancelled';
    public const REASON_REFUSAL = 'refusal';

    public function __construct(
        private readonly string $reason,
        private readonly LLMConversation $conversation,
        private readonly TokenUsage $usage,
        private readonly float $costUsd,
    ) {
        parent::__construct('Agent run interrupted: ' . $reason);
    }

    public function getReason(): string {
        return $this->reason;
    }

    public function getConversation(): LLMConversation {
        return $this->conversation;
    }

    public function getUsage(): TokenUsage {
        return $this->usage;
    }

    public function getCostUsd(): float {
        return $this->costUsd;
    }
}
