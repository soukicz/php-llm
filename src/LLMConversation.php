<?php

namespace Soukicz\Llm;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Soukicz\Llm\Message\LLMMessage;

class LLMConversation implements JsonDeserializable {
    readonly private string $threadId;
    public function __construct(private readonly array $messages, ?string $threadId = null) {
        $this->threadId = $threadId ?? Uuid::uuid7()->toString();
        foreach ($messages as $message) {
            if (!$message instanceof LLMMessage) {
                throw new InvalidArgumentException('Only LLMMessage instances are allowed');
            }
        }
    }

    public function getThreadId(): string {
        return $this->threadId;
    }

    /**
     * @return LLMMessage[]
     */
    public function getMessages(): array {
        return $this->messages;
    }

    public function withMessage(LLMMessage $message): self {
        $messages = $this->messages;
        $messages[] = $message;

        return new self($messages, $this->threadId);
    }

    public function jsonSerialize(): array {
        return [
            'threadId' => $this->threadId,
            'messages' => $this->messages,
        ];
    }

    public static function fromJson(array $data): self {
        return new self(array_map(static fn(array $message) => LLMMessage::fromJson($message), $data['messages']), $data['threadId'] ?? null);
    }

    public function getLastMessage(): LLMMessage {
        return $this->messages[array_key_last($this->messages)];
    }
}
