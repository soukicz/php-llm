<?php

namespace Soukicz\Llm\Config;

class ReasoningBudget implements ReasoningConfig {
    public function __construct(private readonly int $maxTokens) {
    }

    public function getMaxTokens(): int {
        return $this->maxTokens;
    }


}
