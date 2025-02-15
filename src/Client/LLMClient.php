<?php

namespace Soukicz\PhpLlm\Client;

use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\PhpLlm\LLMRequest;
use Soukicz\PhpLlm\LLMResponse;

interface LLMClient {
    public function sendPrompt(LLMRequest $request): LLMResponse;

    public function sendPromptAsync(LLMRequest $request): PromiseInterface;
}
