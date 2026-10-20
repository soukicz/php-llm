<?php

namespace Soukicz\Llm\Agent;

/**
 * Per-run listener and safety limits of LLMAgentClient::run(). Hitting a limit throws AgentRunInterruptedException.
 */
final readonly class AgentRunOptions {
    /**
     * @param int|null $maxIterations model calls allowed; the next one throws ('iterations')
     * @param float|null $maxCostUsd cumulative cost ceiling, checked after each model response ('cost')
     * @param float|null $deadline unix time (microtime(true) scale), checked before each model call and tool call ('deadline')
     * @param \Closure|null $shouldCancel fn(): bool, checked before each model call and tool call ('cancelled')
     */
    public function __construct(
        public ?AgentListener $listener = null,
        public ?int $maxIterations = null,
        public ?float $maxCostUsd = null,
        public ?float $deadline = null,
        public ?\Closure $shouldCancel = null,
    ) {
    }
}
