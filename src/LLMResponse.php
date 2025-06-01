<?php

namespace Soukicz\Llm;

use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\Message\LLMMessageText;

class LLMResponse {
    public function __construct(private readonly LLMRequest $request, private readonly StopReason $stopReason, private readonly int $inputTokens, private readonly int $outputTokens, private readonly int $maximumOutputTokens, private readonly ?float $inputPriceUsd, private readonly ?float $outputPriceUsd, private int $totalTimeMs) {
    }

    public function getConversation(): LLMConversation {
        return $this->request->getConversation();
    }

    public function getRequest(): LLMRequest {
        return $this->request;
    }

    public function getLastText(): string {
        $lastMessage = $this->getConversation()->getMessages()[count($this->getConversation()->getMessages()) - 1];
        foreach (array_reverse(iterator_to_array($lastMessage->getContents())) as $content) {
            if ($content instanceof LLMMessageText) {
                return $content->getText();
            }
        }

        throw new \RuntimeException('No text message found');
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

}
