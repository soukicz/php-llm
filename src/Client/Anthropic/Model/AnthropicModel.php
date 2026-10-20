<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

use Soukicz\Llm\Client\ModelInterface;

abstract class AnthropicModel implements ModelInterface {
    /**
     * Whether the model runs adaptive thinking when the request has no `thinking` parameter
     * (thinking cannot be turned off by omission; effort is the only control)
     */
    public function hasAdaptiveThinkingByDefault(): bool {
        return false;
    }

    /**
     * Whether the model accepts temperature / top_p / top_k. Models that reject them (400) never get them sent.
     */
    public function supportsSamplingParameters(): bool {
        return true;
    }

    /**
     * 5-minute cache write price; the same value as the legacy getCachedInputPricePerMillionTokens()
     */
    public function getCacheWrite5mPricePerMillionTokens(): float {
        return $this->getCachedInputPricePerMillionTokens();
    }

    /**
     * 1-hour cache write price: 2x the base input price on every Claude model
     *
     * @see https://platform.claude.com/docs/en/about-claude/pricing
     */
    public function getCacheWrite1hPricePerMillionTokens(): float {
        return $this->getInputPricePerMillionTokens() * 2;
    }

    /**
     * Cache read (hit and refresh) price; the same value as the legacy getCachedOutputPricePerMillionTokens(),
     * whose name is historical
     */
    public function getCacheReadPricePerMillionTokens(): float {
        return $this->getCachedOutputPricePerMillionTokens();
    }

    /**
     * Rate card for a request whose prompt (uncached input + cache writes + cache reads) has the given size.
     * Models priced by prompt length override this.
     */
    public function getPrices(int $promptTokens): AnthropicPrices {
        return new AnthropicPrices(
            $this->getInputPricePerMillionTokens(),
            $this->getOutputPricePerMillionTokens(),
            $this->getCacheWrite5mPricePerMillionTokens(),
            $this->getCacheWrite1hPricePerMillionTokens(),
            $this->getCacheReadPricePerMillionTokens(),
        );
    }
}
