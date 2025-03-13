<?php

namespace Soukicz\Llm\Log;

use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;

interface LLMLogger {
    public function requestStarted(LLMRequest $request, string $requestUuid): void;

    public function requestFinished(LLMResponse $response, string $requestUuid, \DateTimeImmutable $uncachedEndTime): void;
}
