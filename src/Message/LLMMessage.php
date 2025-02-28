<?php

namespace Soukicz\Llm\Message;

use Soukicz\Llm\JsonDeserializable;

class LLMMessage implements JsonDeserializable {
    private const TYPE_SYSTEM = 'system';
    private const TYPE_USER = 'user';
    private const TYPE_ASSISTANT = 'assistant';

    /**
     * @param LLMMessageContent[] $content
     */
    private function __construct(private readonly string $type, private readonly array $content, private readonly bool $continue = false) {
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

    public function isSystem(): bool {
        return $this->type === self::TYPE_SYSTEM;
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

    /**
     * @param LLMMessageContent[] $content
     */
    public static function createFromSystem(array $content): LLMMessage {
        return new self(self::TYPE_SYSTEM, $content);
    }

    public function jsonSerialize(): array {
        return [
            'type' => $this->type,
            'content' => array_map(static fn(LLMMessageContent $content) => ['class' => $content::class, 'data' => $content], $this->content),
            'continue' => $this->continue,
        ];
    }

    public static function fromJson(array $data): self {
        /** @var LLMMessageContent[] $content */
        $content = [];
        foreach ($data['content'] as $item) {
            $class = $item['class'];
            if (!is_subclass_of($class, LLMMessageContent::class)) {
                throw new \InvalidArgumentException("Class $class does not implement LLMMessageContent");
            }
            $content[] = $class::fromJson($item['data']);
        }

        return new self($data['type'], $content, $data['continue']);
    }
}
