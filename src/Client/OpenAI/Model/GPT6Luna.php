<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

/**
 * @see https://developers.openai.com/api/docs/pricing
 */
class GPT6Luna extends OpenAIModel {
    public function getCode(): string {
        return 'gpt-6-luna';
    }

    public function getInputPricePerMillionTokens(): float {
        return 0.1;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 0.5;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.01;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
