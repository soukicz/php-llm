<?php

namespace Soukicz\Llm\Tool;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;

interface ToolDefinition {
    public function getName(): string;

    public function getDescription(): string;

    public function getInputSchema(): array;

    public function handle(array $input): PromiseInterface|ToolResponse;
}
