<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

class GPT56Luna extends OpenAIModel {
    public function getCode(): string {
        return 'gpt-5.6-luna';
    }

    public function getInputPricePerMillionTokens(): float {
        return 1.0;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 6.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.1;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
