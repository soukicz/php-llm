<?php

namespace Soukicz\Llm\Message;

use Soukicz\Llm\JsonDeserializable;

class LLMMessage implements JsonDeserializable {
    private const TYPE_SYSTEM = 'system';
    private const TYPE_USER = 'user';
    private const TYPE_ASSISTANT = 'assistant';

    private function __construct(private readonly string $type, private readonly LLMMessageContents $content) {
    }

    public function getContents(): LLMMessageContents {
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


    public static function createFromUser(LLMMessageContents $content): LLMMessage {
        return new self(self::TYPE_USER, $content);
    }

    public static function createFromUserString(string $content): LLMMessage {
        return new self(self::TYPE_USER, new LLMMessageContents([new LLMMessageText($content)]));
    }


    public static function createFromAssistant(LLMMessageContents $content): LLMMessage {
        return new self(self::TYPE_ASSISTANT, $content);
    }

    public static function createFromAssistantString(string $content): LLMMessage {
        return new self(self::TYPE_ASSISTANT, new LLMMessageContents([new LLMMessageText($content)]));
    }

    public static function createFromSystem(LLMMessageContents $content): LLMMessage {
        return new self(self::TYPE_SYSTEM, $content);
    }

    public static function createFromSystemString(string $content): LLMMessage {
        return new self(self::TYPE_SYSTEM, new LLMMessageContents([new LLMMessageText($content)]));
    }


    public function jsonSerialize(): array {
        return [
            'type' => $this->type,
            'content' => $this->content,
        ];
    }

    public static function fromJson(array $data): self {
        return new self($data['type'], LLMMessageContents::fromJson($data['content']));
    }
}
