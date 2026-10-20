<?php

namespace Soukicz\Llm\Agent;

use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessageContents;

/**
 * Progress events of LLMAgentClient::run(). Calls happen synchronously inside the run; an exception thrown
 * here ends the run.
 */
interface AgentListener {
    /**
     * One model API call finished; $stepResponse->getStepUsage() is that call's usage
     */
    public function onModelResponse(LLMResponse $stepResponse): void;

    public function onToolCallStarted(string $toolName, array $input): void;

    public function onToolCallFinished(string $toolName, array $input, LLMMessageContents $result, bool $isError, int $durationMs): void;
}
