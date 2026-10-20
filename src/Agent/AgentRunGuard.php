<?php

namespace Soukicz\Llm\Agent;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;
use Soukicz\Llm\Usage\TokenUsage;

/**
 * @internal state of one LLMAgentClient run: counts model calls, enforces AgentRunOptions and forwards events
 */
final class AgentRunGuard {
    private int $modelCalls = 0;

    private ?LLMResponse $lastResponse = null;

    public function __construct(private readonly AgentRunOptions $options) {
    }

    public function beforeModelCall(LLMRequest $request): void {
        if ($this->options->maxIterations !== null && $this->modelCalls >= $this->options->maxIterations) {
            throw $this->interrupt(AgentRunInterruptedException::REASON_ITERATIONS, $request->getConversation());
        }
        $reason = $this->deadlineOrCancelReason();
        if ($reason !== null) {
            throw $this->interrupt($reason, $request->getConversation());
        }
    }

    public function afterModelResponse(LLMResponse $response): void {
        $this->modelCalls++;
        $this->lastResponse = $response;
        $this->options->listener?->onModelResponse($response);

        if ($response->getStopReason() === StopReason::REFUSAL) {
            throw $this->interrupt(AgentRunInterruptedException::REASON_REFUSAL, self::withoutLastMessage($response->getConversation()));
        }
        if ($this->options->maxCostUsd !== null && $response->getTotalCostUsd() > $this->options->maxCostUsd) {
            throw $this->interrupt(
                AgentRunInterruptedException::REASON_COST,
                self::closeOpenToolUses($response->getConversation(), [], AgentRunInterruptedException::REASON_COST)
            );
        }
    }

    /**
     * @param array<int, PromiseInterface> $startedResults promises of LLMMessageToolResult already started for this response
     */
    public function beforeToolCall(LLMResponse $response, array $startedResults): void {
        $reason = $this->deadlineOrCancelReason();
        if ($reason === null) {
            return;
        }
        /** @var LLMMessageToolResult[] $done */
        $done = Utils::unwrap($startedResults);

        throw $this->interrupt($reason, self::closeOpenToolUses($response->getConversation(), $done, $reason));
    }

    public function toolCallStarted(string $toolName, array $input): void {
        $this->options->listener?->onToolCallStarted($toolName, $input);
    }

    public function toolCallFinished(string $toolName, array $input, LLMMessageContents $result, int $durationMs): void {
        $this->options->listener?->onToolCallFinished($toolName, $input, $result, $result->isError(), $durationMs);
    }

    private function deadlineOrCancelReason(): ?string {
        if ($this->options->shouldCancel !== null && ($this->options->shouldCancel)() === true) {
            return AgentRunInterruptedException::REASON_CANCELLED;
        }
        if ($this->options->deadline !== null && microtime(true) >= $this->options->deadline) {
            return AgentRunInterruptedException::REASON_DEADLINE;
        }

        return null;
    }

    private function interrupt(string $reason, LLMConversation $conversation): AgentRunInterruptedException {
        return new AgentRunInterruptedException(
            $reason,
            $conversation,
            $this->lastResponse?->getUsage() ?? new TokenUsage(),
            $this->lastResponse?->getTotalCostUsd() ?? 0.0,
        );
    }

    /**
     * @param LLMMessageToolResult[] $done
     */
    private static function closeOpenToolUses(LLMConversation $conversation, array $done, string $reason): LLMConversation {
        if ($conversation->getMessages() === []) {
            return $conversation;
        }
        $last = $conversation->getLastMessage();
        if (!$last->isAssistant()) {
            return $conversation;
        }

        $doneById = [];
        foreach ($done as $result) {
            $doneById[$result->getId()] = $result;
        }
        $results = [];
        foreach ($last->getContents() as $content) {
            if ($content instanceof LLMMessageToolUse) {
                $results[] = $doneById[$content->getId()] ?? new LLMMessageToolResult(
                    $content->getId(),
                    LLMMessageContents::fromErrorString('ERROR: not run, the agent run was interrupted (' . $reason . ')')
                );
            }
        }

        return $results === [] ? $conversation : $conversation->withMessage(LLMMessage::createFromUser(new LLMMessageContents($results)));
    }

    private static function withoutLastMessage(LLMConversation $conversation): LLMConversation {
        $messages = $conversation->getMessages();
        array_pop($messages);

        return new LLMConversation(array_values($messages), $conversation->getThreadId());
    }
}
