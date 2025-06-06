<?php

namespace Soukicz\Llm\Client\Anthropic\Tool;

use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\Llm\Message\LLMMessageContents;

interface AnthropicNativeTool {
    public function getAnthropicType(): string;

    public function getAnthropicName(): string;

    public function handle(array $input): LLMMessageContents|PromiseInterface;
}
