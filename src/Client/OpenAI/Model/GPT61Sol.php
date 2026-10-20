<?php

namespace Soukicz\Llm\Client\OpenAI\Model;

/**
 * @see https://developers.openai.com/api/docs/pricing (read 2026-10-20)
 */
class GPT61Sol extends OpenAIModel {
    public function getCode(): string {
        return 'gpt-6.1-sol';
    }

    public function getInputPricePerMillionTokens(): float {
        return 2.0;
    }

    public function getOutputPricePerMillionTokens(): float {
        return 10.0;
    }

    public function getCachedInputPricePerMillionTokens(): float {
        return 0.1;
    }

    public function getCachedOutputPricePerMillionTokens(): float {
        return 0.0;
    }
}
