<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

/**
 * @see https://developers.openai.com/api/docs/pricing
 */
class GPT56Luna extends OpenAIModel {
    public function getCode(): string {
        return 'gpt-5.6-luna';
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.2;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 1.2;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.02;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
