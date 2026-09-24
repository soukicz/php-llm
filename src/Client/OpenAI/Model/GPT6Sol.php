<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

/**
 * @see https://developers.openai.com/api/docs/pricing
 */
class GPT6Sol extends OpenAIModel {
    public function getCode(): string {
        return 'gpt-6-sol';
    }

    public function getInputPricePerMillionTokens(): float {
        return 2.0;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 10.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.2;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
