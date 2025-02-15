<?php

namespace Soukicz\PhpLlm\Message;

interface LLMMessageContent {
    public function isCached(): bool;
}
