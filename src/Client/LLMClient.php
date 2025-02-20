<?php

namespace Soukicz\Llm\Client;

use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;

interface LLMClient {
    public function sendPrompt(LLMRequest $request): LLMResponse;

    public function sendPromptAsync(LLMRequest $request): PromiseInterface;
}
