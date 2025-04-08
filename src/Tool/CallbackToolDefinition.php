<?php

namespace Soukicz\Llm\Tool;

use GuzzleHttp\Promise\PromiseInterface;

class CallbackToolDefinition implements ToolDefinition {
    private string $name;

    private string $description;

    private array $inputSchema;

    /** @var callable */
    private $handler;

    public function __construct(string $name, string $description, array $inputSchema, callable $handler) {
        $this->name = $name;
        $this->description = $description;
        $this->inputSchema = $inputSchema;
        $this->handler = $handler;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getDescription(): string {
        return $this->description;
    }

    public function getInputSchema(): array {
        return $this->inputSchema;
    }

    public function handle(array $input): PromiseInterface|ToolResponse {
        $result = ($this->handler)($input);

        if ($result instanceof PromiseInterface) {
            return $result->then(static function (mixed $response) {
                return new ToolResponse($response);
            });
        }

        return new ToolResponse($result);
    }
}
