<?php

declare(strict_types=1);

namespace Soukicz\Llm\Stream;

interface StreamListenerInterface {
    public function onStreamEvent(StreamEvent $event): void;
}
