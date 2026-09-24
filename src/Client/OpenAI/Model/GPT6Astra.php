<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

/**
 * @see https://developers.openai.com/api/docs/pricing
 */
class GPT6Astra extends OpenAIModel {
    public function getCode(): string {
        return 'gpt-6-astra';
    }

    public function getInputPricePerMillionTokens(): float {
        return 10.0;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 50.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 1.0;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
