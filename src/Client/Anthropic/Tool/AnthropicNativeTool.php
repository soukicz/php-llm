<?php

namespace Soukicz\Llm\Client\Anthropic\Tool;

use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\Llm\Tool\ToolResponse;

interface AnthropicNativeTool {
    public function getType(): string;

    public function getName(): string;

    public function handle(array $input): ToolResponse|PromiseInterface;
}
