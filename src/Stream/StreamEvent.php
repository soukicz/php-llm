<?php

declare(strict_types=1);

namespace Soukicz\Llm\Stream;

class StreamEvent {
    public function __construct(
        public readonly StreamEventType $type,
        public readonly int $blockIndex,
        public readonly string $delta = '',
        public readonly ?string $toolName = null,
        public readonly ?string $toolId = null,
    ) {
    }
}
