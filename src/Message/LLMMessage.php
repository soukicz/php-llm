<?php

namespace Soukicz\PhpLlm\Message;

class LLMMessage {
    private const TYPE_USER = 1;
    private const TYPE_ASSISTANT = 2;

    private int $type;
    /** @var LLMMessageContent[] */
    private array $content;

    private bool $continue;

    private function __construct(int $type, array $content, bool $continue = false) {
        $this->type = $type;
        $this->content = $content;
        $this->continue = $continue;
    }

    /**
     * @return LLMMessageContent[]
     */
    public function getContents(): array {
        return $this->content;
    }

    public function isUser(): bool {
        return $this->type === self::TYPE_USER;
    }

    public function isAssistant(): bool {
        return $this->type === self::TYPE_ASSISTANT;
    }

    public function isContinue(): bool {
        return $this->continue;
    }

    /**
     * @param LLMMessageContent[] $content
     */
    public static function createFromUser(array $content): LLMMessage {
        return new self(self::TYPE_USER, $content);
    }

    public static function createFromUserContinue(LLMMessageContent $content): LLMMessage {
        return new self(self::TYPE_USER, [$content], true);
    }

    /**
     * @param LLMMessageContent[] $content
     */
    public static function createFromAssistant(array $content): LLMMessage {
        return new self(self::TYPE_ASSISTANT, $content);
    }
}
