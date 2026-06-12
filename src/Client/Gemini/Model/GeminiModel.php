<?php

namespace Soukicz\Llm\Client\Gemini\Model;

use Soukicz\Llm\Client\ModelInterface;

abstract class GeminiModel implements ModelInterface {
    /**
     * Whether the model accepts thinkingConfig.thinkingLevel (Gemini 3.x and newer).
     * Older models (2.x) only support thinkingConfig.thinkingBudget.
     */
    public function supportsThinkingLevel(): bool {
        return false;
    }
}
