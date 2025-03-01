<?php

namespace Soukicz\Llm\Client;

use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;

interface ModelEncoder {
    public function encodeRequest(LLMRequest $request): array;

    public function decodeResponse(LLMRequest $request, ModelResponse $modelResponse): LLMRequest|LLMResponse;
}
