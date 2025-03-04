<?php

namespace Soukicz\Llm\Tool;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;

class ToolDefinition {
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

    public function handle(string $id, array $input): PromiseInterface {
        $result = ($this->handler)($input);
        if ($result instanceof PromiseInterface) {
            return $result->then(static function ($data) use ($id) {
                return new ToolResponse($id, $data);
            });
        }

        return Create::promiseFor(new ToolResponse($id, $result));
    }
}
