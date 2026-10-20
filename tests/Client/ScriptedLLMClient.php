<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\Llm\Client\LLMClient;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageToolUse;
use Soukicz\Llm\Usage\TokenUsage;

/**
 * Fake LLM client answering each request with the next scripted step; records what was sent.
 * Every step costs 100 input / 50 output tokens and the given USD.
 */
final class ScriptedLLMClient implements LLMClient {
    /** @var list<LLMRequest> */
    public array $sent = [];

    /** @var list<\Closure(LLMRequest): LLMResponse> */
    private array $steps;

    public function __construct(\Closure ...$steps) {
        $this->steps = array_values($steps);
    }

    public function sendRequestAsync(LLMRequest $request): PromiseInterface {
        $this->sent[] = $request;
        $step = array_shift($this->steps);
        if ($step === null) {
            throw new \RuntimeException('ScriptedLLMClient: no scripted response left for request #' . count($this->sent));
        }

        return Create::promiseFor($step($request));
    }

    public static function reply(LLMMessageContents $contents, StopReason $stopReason, float $costUsd = 0.0, ?array $stopDetails = null): \Closure {
        return static function (LLMRequest $request) use ($contents, $stopReason, $costUsd, $stopDetails): LLMResponse {
            $step = new TokenUsage(input: 100, output: 50);
            $request = $request
                ->withCost(100, 50, $costUsd, 0.0)
                ->withUsage($step)
                ->withTime(10)
                ->withMessage(LLMMessage::createFromAssistant($contents));

            return new LLMResponse(
                $request,
                $stopReason,
                $request->getPreviousInputTokens(),
                $request->getPreviousOutputTokens(),
                $request->getPreviousMaximumOutputTokens(),
                $request->getPreviousInputCostUSD(),
                $request->getPreviousOutputCostUSD(),
                $request->getPreviousTimeMs(),
                $request->getPreviousUsage(),
                $step,
                $stopDetails,
            );
        };
    }

    public static function toolUse(string $id, string $name, array $input, float $costUsd = 0.0): \Closure {
        return self::reply(new LLMMessageContents([new LLMMessageToolUse($id, $name, $input)]), StopReason::TOOL_USE, $costUsd);
    }

    public static function text(string $text, float $costUsd = 0.0): \Closure {
        return self::reply(LLMMessageContents::fromString($text), StopReason::FINISHED, $costUsd);
    }

    /**
     * @return list<\Closure(LLMRequest): LLMResponse>
     */
    public static function repeat(int $times, \Closure $step): array {
        return array_fill(0, $times, $step);
    }
}
