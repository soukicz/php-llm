<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

class GPT56Sol extends OpenAIModel {
    public function getCode(): string {
        return 'gpt-5.6-sol';
    }

    public function getInputPricePerMillionTokens(): float {
        return 5.0;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 30.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.5;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
