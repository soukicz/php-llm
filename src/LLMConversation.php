<?php

namespace Soukicz\Llm;

use Soukicz\Llm\Message\LLMMessage;

class LLMConversation implements JsonDeserializable {
    public function __construct(private readonly array $messages) {
        foreach ($messages as $message) {
            if (!$message instanceof LLMMessage) {
                throw new \InvalidArgumentException('Only LLMMessage instances are allowed');
            }
        }
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

        return new self($messages);
    }

    public function jsonSerialize(): array {
        return [
            'messages' => $this->messages,
        ];
    }

    public static function fromJson(array $data): self {
        return new self(array_map(static fn(array $message) => LLMMessage::fromJson($message), $data['messages']));
    }

}
