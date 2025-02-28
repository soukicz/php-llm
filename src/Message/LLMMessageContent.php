<?php

namespace Soukicz\Llm\Message;

use Soukicz\Llm\JsonDeserializable;

interface LLMMessageContent extends JsonDeserializable {
    public function isCached(): bool;
}
