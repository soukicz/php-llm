<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Agent;

use Soukicz\Llm\Agent\AgentListener;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessageContents;

final class RecordingAgentListener implements AgentListener {
    /** @var list<array> */
    public array $events = [];

    /** @var list<int> */
    public array $durationsMs = [];

    public function onModelResponse(LLMResponse $stepResponse): void {
        $this->events[] = ['model', $stepResponse->getStopReason()->value, $stepResponse->getStepUsage()->input];
    }

    public function onToolCallStarted(string $toolName, array $input): void {
        $this->events[] = ['start', $toolName, $input];
    }

    public function onToolCallFinished(string $toolName, array $input, LLMMessageContents $result, bool $isError, int $durationMs): void {
        $this->events[] = ['finish', $toolName, $isError];
        $this->durationsMs[] = $durationMs;
    }
}
