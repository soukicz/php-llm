<?php

namespace Soukicz\Llm\Message;

interface LLMMessageContent {
    public function isCached(): bool;
}
