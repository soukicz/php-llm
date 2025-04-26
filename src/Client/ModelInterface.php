<?php

namespace Soukicz\Llm\Client;

interface ModelInterface {
    /**
     * Get the model identifier used in API calls
     */
    public function getCode(): string;

    /**
     * Get the price per million input tokens in USD
     */
    public function getInputPricePerMillionTokens(): float;

    /**
     * Get the price per million output tokens in USD
     */
    public function getOutputPricePerMillionTokens(): float;

    /**
     * Get the cached input price per million tokens in USD
     */
    public function getCachedInputPricePerMillionTokens(): float;

    /**
     * Get the cached output price per million tokens in USD
     */
    public function getCachedOutputPricePerMillionTokens(): float;
}
