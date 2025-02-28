<?php

namespace Soukicz\Llm;

use Soukicz\Llm\Config\ReasoningConfig;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Tool\ToolDefinition;

class LLMRequest {

    /** @var ?callable */
    private $feedbackCallback;

    /** @var ?callable */
    private $continuationCallback;

    /**
     * @param ToolDefinition[] $tools
     * @param string[] $stopSequences
     */
    public function __construct(
        private readonly string  $model,
        private readonly ?string $systemPrompt,
        private LLMConversation  $conversation,
        private readonly float   $temperature = 0.0,
        private readonly int     $maxTokens = 4096,
        private readonly array   $tools = [],
        private readonly array   $stopSequences = [],
        private readonly ReasoningConfig|ReasoningEffort|null $reasoningConfig = null,
        ?callable                $feedbackCallback = null,
        ?callable                $continuationCallback = null,
        private int              $previousInputTokens = 0,
        private int              $previousOutputTokens = 0,
        private int              $previousMaximumOutputTokens = 0,
        private float            $previousInputCostUSD = 0.0,
        private float            $previousOutputCostUSD = 0.0,
        private int              $previousTimeMs = 0
    ) {
        $this->feedbackCallback = $feedbackCallback;
        $this->continuationCallback = $continuationCallback;
    }

    public function getConversation(): LLMConversation {
        return $this->conversation;
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
        $clone->conversation = $this->conversation->withMessage($message);

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
        /** @var ?LLMMessage $previous */
        $previous = null;
        $lastWasContinue = false;
        foreach ($this->getConversation()->getMessages() as $message) {
            if ($message->isContinue()) {
                $lastWasContinue = true;
                continue;
            }
            if ($previous && $lastWasContinue) {
                foreach ($previous->getContents() as $content) {
                    if ($content instanceof LLMMessageText) {
                        $firstContent = $message->getContents()[0];
                        if ($firstContent instanceof LLMMessageText) {
                            $content->mergeWith($firstContent);
                        }
                    }
                }
            } else {
                $messages[] = $message;
                $previous = $message;
            }
            $lastWasContinue = false;
        }

        $clone = clone $this;
        $clone->conversation = new LLMConversation($messages);

        return $clone;
    }

    public function withPostProcessLastMessageText(callable $callback): self {
        $messages = $this->getConversation()->getMessages();
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
        $clone->conversation = new LLMConversation($messages);

        return $clone;
    }

    public function getLastMessage(): LLMMessage {
        return $this->getConversation()->getMessages()[count($this->getConversation()->getMessages()) - 1];
    }

    public function getReasoningConfig(): ReasoningConfig|ReasoningEffort|null {
        return $this->reasoningConfig;
    }

}
