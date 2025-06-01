<?php

namespace Soukicz\Llm\Tool;

use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\Llm\Message\LLMMessageContents;

interface ToolDefinition {
    public function getName(): string;

    public function getDescription(): string;

    public function getInputSchema(): array;

    public function handle(array $input): PromiseInterface|LLMMessageContents;
}
