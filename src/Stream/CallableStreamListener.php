<?php

declare(strict_types=1);

namespace Soukicz\Llm\Stream;

class CallableStreamListener implements StreamListenerInterface {
    private readonly \Closure $callback;

    public function __construct(callable $callback) {
        $this->callback = $callback(...);
    }

    public function onStreamEvent(StreamEvent $event): void {
        ($this->callback)($event);
    }
}
