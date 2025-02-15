<?php

namespace Soukicz\PhpLlm;

use Soukicz\PhpLlm\Message\LLMMessage;
use Soukicz\PhpLlm\Message\LLMMessageText;

class LLMRequest {
    /** @var LLMMessage[] */
    private array $messages;
    private string $model;
    private float $temperature;
    private int $maxTokens;

    /** @var ToolDefinition[] */
    private array $tools;

    private int $previousTimeMs = 0;

    private int $previousInputTokens = 0;
    private int $previousOutputTokens = 0;
    private int $previousMaximumOutputTokens = 0;

    private float $previousInputCostUSD = 0.0;
    private float $previousOutputCostUSD = 0.0;

    private array $stopSequences;

    /** @var ?callable */
    private $feedbackCallback;

    /** @var ?callable */
    private $continuationCallback;

    public function __construct(array $messages, string $model, float $temperature = 0.0, int $maxTokens = 4096, array $tools = [], array $stopSequences = [], ?callable $feedbackCallback = null, ?callable $continuationCallback = null) {
        $this->messages = $messages;
        $this->model = $model;
        $this->temperature = $temperature;
        $this->maxTokens = $maxTokens;
        $this->tools = $tools;
        $this->stopSequences = $stopSequences;
        $this->feedbackCallback = $feedbackCallback;
        $this->continuationCallback = $continuationCallback;
    }

    public function getMessages(): array {
        return $this->messages;
    }

    public function getModel(): string {
        return $this->model;
    }

    public function getTemperature(): float {
        return $this->temperature;
    }

    public function getMaxTokens(): int {
        return $this->maxTokens;
    }

    public function getFeedbackCallback(): ?callable {
        return $this->feedbackCallback;
    }

    public function getContinuationCallback(): ?callable {
        return $this->continuationCallback;
    }

    public function getStopSequences(): array {
        return $this->stopSequences;
    }

    /**
     * @return ToolDefinition[]
     */
    public function getTools(): array {
        return $this->tools;
    }

    public function withMessage(LLMMessage $message): self {
        $clone = clone $this;
        $clone->messages[] = $message;

        return $clone;
    }

    public function getPreviousInputTokens(): int {
        return $this->previousInputTokens;
    }

    public function getPreviousOutputTokens(): int {
        return $this->previousOutputTokens;
    }

    public function getPreviousMaximumOutputTokens(): int {
        return $this->previousMaximumOutputTokens;
    }

    public function getPreviousInputCostUSD(): float {
        return $this->previousInputCostUSD;
    }

    public function getPreviousOutputCostUSD(): float {
        return $this->previousOutputCostUSD;
    }

    public function getPreviousTimeMs(): int {
        return $this->previousTimeMs;
    }

    public function withCost(int $inputTokens, int $outputTokens, float $previousInputCostUSD, float $previousOutputCostUSD): self {
        $clone = clone $this;

        $clone->previousInputTokens += $inputTokens;
        $clone->previousOutputTokens += $outputTokens;
        if ($outputTokens > $this->previousMaximumOutputTokens) {
            $clone->previousMaximumOutputTokens = $outputTokens;
        }
        $clone->previousInputCostUSD += $previousInputCostUSD;
        $clone->previousOutputCostUSD += $previousOutputCostUSD;

        return $clone;
    }

    public function withTime(int $timeMs): self {
        $clone = clone $this;
        $clone->previousTimeMs += $timeMs;

        return $clone;
    }

    public function withMergedMessages(): self {
        $messages = [];
        $skip = null;
        foreach (array_values($this->messages) as $i => $message) {
            if ($message === $skip) {
                continue;
            }
            if ($message->isContinue()) {
                /** @var LLMMessage $previous */
                $previous = $messages[count($messages) - 1];
                foreach ($previous->getContents() as $content) {
                    if ($content instanceof LLMMessageText) {
                        $firstContent = $message->getContents()[0];
                        if ($firstContent instanceof LLMMessageText) {
                            $content->mergeWith($firstContent);
                        }
                    }
                }
                $skip = $messages[$i + 1];
            } else {
                $messages[] = $message;
            }
        }

        $clone = clone $this;
        $clone->messages = $messages;

        return $clone;
    }

    public function withPostProcessLastMessageText(callable $callback): self {
        $messages = $this->messages;
        $lastMessage = array_pop($messages);
        $contents = [];
        foreach ($lastMessage->getContents() as $content) {
            if ($content instanceof LLMMessageText) {
                $contents[] = new LLMMessageText($callback($content->getText()));
            } else {
                $contents[] = $content;
            }
        }
        if ($lastMessage->isUser()) {
            $messages[] = LLMMessage::createFromUser($contents);
        } else {
            $messages[] = LLMMessage::createFromAssistant($contents);
        }

        $clone = clone $this;
        $clone->messages = $messages;

        return $clone;
    }

    public function getLastMessage(): LLMMessage {
        return $this->messages[count($this->messages) - 1];
    }

}
