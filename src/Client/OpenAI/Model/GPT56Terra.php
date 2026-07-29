<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

class GPT56Terra extends OpenAIModel {
    public function getCode(): string {
        return 'gpt-5.6-terra';
    }

    public function getInputPricePerMillionTokens(): float {
        return 2.5;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 15.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.25;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
