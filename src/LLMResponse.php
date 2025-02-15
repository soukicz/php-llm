<?php

namespace Soukicz\PhpLlm;

use Soukicz\PhpLlm\Message\LLMMessage;
use Soukicz\PhpLlm\Message\LLMMessageText;

class LLMResponse implements \JsonSerializable {
    /** @var LLMMessage[] */
    private array $messages;
    private string $stopReason;
    private ?float $inputPriceUsd;
    private ?float $outputPriceUsd;

    public function __construct(array $messages, string $stopReason, ?float $inputPriceUsd, ?float $outputPriceUsd) {
        $this->messages = $messages;
        $this->stopReason = $stopReason;
        $this->inputPriceUsd = $inputPriceUsd;
        $this->outputPriceUsd = $outputPriceUsd;
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

    public function getInputPriceUsd(): ?float {
        return $this->inputPriceUsd;
    }

    public function getOutputPriceUsd(): ?float {
        return $this->outputPriceUsd;
    }

    public function jsonSerialize(): array {
        return [
            'text' => $this->getLastText(),
            'stopReason' => $this->stopReason,
            'inputPriceUsd' => $this->inputPriceUsd,
            'outputPriceUsd' => $this->outputPriceUsd,
        ];
    }

    public static function fromJsonString(array $inputMessages, string $input): LLMResponse {
        $json = json_decode($input, true, 512, JSON_THROW_ON_ERROR);

        $inputMessages[] = LLMMessage::createFromAssistant([new LLMMessageText($json['text'])]);

        return new LLMResponse(
            $inputMessages,
            $json['stopReason'],
            $json['inputPriceUsd'] ?? null,
            $json['outputPriceUsd'] ?? null,
        );
    }
}
