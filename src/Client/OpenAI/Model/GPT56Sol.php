<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

/**
 * Promotional pricing, available at least through November 21, 2026.
 *
 * @see https://developers.openai.com/api/docs/pricing
 */
class GPT56Sol extends OpenAIModel {
    public function getCode(): string {
        return 'gpt-5.6-sol';
    }

    public function getInputPricePerMillionTokens(): float {
        return 4.0;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 20.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.4;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
