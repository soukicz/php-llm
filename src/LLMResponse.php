<?php

namespace Soukicz\Llm;

use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\Message\LLMMessageStructuredData;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Usage\TokenUsage;

class LLMResponse {
    public function __construct(
        private readonly LLMRequest $request,
        private readonly StopReason $stopReason,
        private readonly int $inputTokens,
        private readonly int $outputTokens,
        private readonly int $maximumOutputTokens,
        private readonly ?float $inputPriceUsd,
        private readonly ?float $outputPriceUsd,
        private int $totalTimeMs,
        private readonly ?TokenUsage $usage = null,
        private readonly ?TokenUsage $stepUsage = null,
        private readonly ?array $stopDetails = null,
    ) {
    }

    public function getConversation(): LLMConversation {
        return $this->request->getConversation();
    }

    public function getRequest(): LLMRequest {
        return $this->request;
    }

    public function getLastText(): string {
        $lastMessage = $this->getConversation()->getLastMessage();
        foreach (array_reverse(iterator_to_array($lastMessage->getContents())) as $content) {
            if ($content instanceof LLMMessageText) {
                return $content->getText();
            }
        }

        throw new \RuntimeException('No text message found');
    }

    public function getLastStructuredData(): array {
        $lastMessage = $this->getConversation()->getLastMessage();
        foreach (array_reverse(iterator_to_array($lastMessage->getContents())) as $content) {
            if ($content instanceof LLMMessageStructuredData) {
                return $content->getData();
            }
        }

        throw new \RuntimeException('No structured data message found');
    }

    public function getStopReason(): StopReason {
        return $this->stopReason;
    }

    public function getInputTokens(): int {
        return $this->inputTokens;
    }

    public function getOutputTokens(): int {
        return $this->outputTokens;
    }

    public function getMaximumOutputTokens(): int {
        return $this->maximumOutputTokens;
    }

    public function getInputPriceUsd(): ?float {
        return $this->inputPriceUsd;
    }

    public function getOutputPriceUsd(): ?float {
        return $this->outputPriceUsd;
    }

    public function getTotalTimeMs(): int {
        return $this->totalTimeMs;
    }

    /**
     * Provider details of the stop (Anthropic `stop_details`: {type, category, explanation} on a refusal), null otherwise
     */
    public function getStopDetails(): ?array {
        return $this->stopDetails;
    }

    /**
     * Cumulative usage of the whole run (every model call of an agent loop)
     */
    public function getUsage(): TokenUsage {
        return $this->usage ?? new TokenUsage(input: $this->inputTokens, output: $this->outputTokens);
    }

    /**
     * Usage of the model call that produced this response only
     */
    public function getStepUsage(): TokenUsage {
        return $this->stepUsage ?? $this->getUsage();
    }

    /**
     * Cumulative cost in USD, cache writes and reads included (they are part of the input price)
     */
    public function getTotalCostUsd(): float {
        return ($this->inputPriceUsd ?? 0.0) + ($this->outputPriceUsd ?? 0.0);
    }

}
