<?php

namespace Soukicz\Llm\Message;

use ArrayAccess;
use Countable;
use InvalidArgumentException;
use Iterator;
use JsonSerializable;

/**
 * @implements Iterator<int, LLMMessageContent>
 * @implements ArrayAccess<int, LLMMessageContent>
 */
class LLMMessageContents implements JsonSerializable, Iterator, ArrayAccess, Countable {
    public function __construct(private array $messages, private bool $isError = false) {
        foreach ($this->messages as $message) {
            if (!$message instanceof LLMMessageContent) {
                throw new InvalidArgumentException('All messages must implement LLMMessageContent interface - ' . get_class($message) . ' does not.');
            }
        }
    }

    /**
     * @return LLMMessageContent[]
     */
    public function getMessages(): array {
        return $this->messages;
    }

    public static function fromJson(array $data): self {
        // Current format wraps items to preserve the isError flag; legacy format was a plain list
        $items = $data['items'] ?? $data;
        $isError = $data['isError'] ?? false;

        /** @var LLMMessageContent[] $content */
        $content = [];
        foreach ($items as $item) {
            $class = $item['class'];
            if (!is_subclass_of($class, LLMMessageContent::class)) {
                throw new InvalidArgumentException("Class $class does not implement LLMMessageContent");
            }
            $result = $class::fromJson($item['data']);

            // Ensure the result implements LLMMessageContent
            if (!($result instanceof LLMMessageContent)) {
                throw new InvalidArgumentException("Class $class::fromJson() does not return LLMMessageContent");
            }

            $content[] = $result;
        }

        return new self($content, $isError);
    }

    public function jsonSerialize(): array {
        return [
            'isError' => $this->isError,
            'items' => array_map(static fn(LLMMessageContent $content) => ['class' => $content::class, 'data' => $content], $this->messages),
        ];
    }

    public static function fromString(string $content): self {
        return new self([new LLMMessageText($content)]);
    }

    public static function fromErrorString(string $content): self {
        return new self([new LLMMessageText($content)], true);
    }

    public static function fromArrayData(array $content): self {
        return new self([new LLMMessageArrayData($content)]);
    }

    public function current(): LLMMessageContent {
        return current($this->messages);
    }

    public function next(): void {
        next($this->messages);
    }

    public function key(): int {
        return key($this->messages);
    }

    public function valid(): bool {
        return key($this->messages) !== null;
    }

    public function rewind(): void {
        reset($this->messages);
    }

    public function offsetExists(mixed $offset): bool {
        return array_key_exists($offset, $this->messages);
    }

    public function offsetGet(mixed $offset): LLMMessageContent {
        return $this->messages[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        throw new InvalidArgumentException('Messages are readonly.');
    }

    public function offsetUnset(mixed $offset): void {
        throw new InvalidArgumentException('Messages are readonly.');
    }

    public function count(): int {
        return count($this->messages);
    }

    public function isError(): bool {
        return $this->isError;
    }
}
