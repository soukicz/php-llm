<?php

namespace Soukicz\Llm\Client;

use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;

interface LLMClient {
    /**
     * @return PromiseInterface<LLMResponse>
     */
    public function sendRequestAsync(LLMRequest $request): PromiseInterface;
}
