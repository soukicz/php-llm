<?php

namespace Soukicz\Llm;

use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;

class LLMResponse implements \JsonSerializable {
    /**
     * @param LLMMessage[] $messages
     */
    public function __construct(private readonly array $messages, private readonly string $stopReason, private readonly int $inputTokens, private readonly int $outputTokens, private readonly int $maximumOutputTokens, private readonly ?float $inputPriceUsd, private readonly ?float $outputPriceUsd, private int $totalTimeMs) {
    }

    /**
     * @return LLMMessage[]
     */
    public function getMessages(): array {
        return $this->messages;
    }

    public function getLastText(): string {
        $lastMessage = $this->messages[count($this->messages) - 1];
        foreach (array_reverse($lastMessage->getContents()) as $content) {
            if ($content instanceof LLMMessageText) {
                return $content->getText();
            }
        }

        throw new \RuntimeException('No text message found');
    }

    public function getStopReason(): string {
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

    public function jsonSerialize(): array {
        return [
            'text' => $this->getLastText(),
            'stopReason' => $this->stopReason,
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
            'maximumOutputTokens' => $this->maximumOutputTokens,
            'inputPriceUsd' => $this->inputPriceUsd,
            'outputPriceUsd' => $this->outputPriceUsd,
            'totalTimeMs' => $this->totalTimeMs,
        ];
    }

    public static function fromJsonString(array $inputMessages, string $input): LLMResponse {
        $json = json_decode($input, true, 512, JSON_THROW_ON_ERROR);

        $inputMessages[] = LLMMessage::createFromAssistant([new LLMMessageText($json['text'])]);

        return new LLMResponse(
            $inputMessages,
            $json['stopReason'],
            $json['inputTokens'] ?? 0,
            $json['outputTokens'] ?? 0,
            $json['maximumOutputTokens'] ?? 0,
            $json['inputPriceUsd'] ?? null,
            $json['outputPriceUsd'] ?? null,
            $json['totalTimeMs'] ?? 0
        );
    }
}
