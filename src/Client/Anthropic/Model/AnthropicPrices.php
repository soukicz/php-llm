<?php

namespace Soukicz\Llm\Client\Anthropic\Model;

/**
 * One rate card of an Anthropic model, USD per million tokens
 */
final readonly class AnthropicPrices {
    public function __construct(
        public float $input,
        public float $output,
        public float $cacheWrite5m,
        public float $cacheWrite1h,
        public float $cacheRead,
    ) {
    }
}
