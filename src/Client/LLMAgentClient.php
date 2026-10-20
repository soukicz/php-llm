<?php

namespace Soukicz\Llm\Client;

use Exception;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use InvalidArgumentException;
use Soukicz\Llm\Agent\AgentRunGuard;
use Soukicz\Llm\Agent\AgentRunInterruptedException;
use Soukicz\Llm\Agent\AgentRunOptions;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Log\LLMLogger;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;
use Soukicz\Llm\Tool\ToolDefinition;
use Swaggest\JsonSchema\Schema;

class LLMAgentClient {
    public function __construct(private readonly ?LLMLogger $logger = null) {
    }

    /**
     * @throws AgentRunInterruptedException when a limit of $options is hit, the run is cancelled or the model refuses
     */
    public function run(LLMClient $client, LLMRequest $request, ?callable $feedbackCallback = null, ?AgentRunOptions $options = null): LLMResponse {
        return $this->runAsync($client, $request, $feedbackCallback, $options)->wait();
    }

    /**
     * @return PromiseInterface<LLMResponse> rejected with AgentRunInterruptedException on a limit, cancel or refusal
     */
    public function runAsync(LLMClient $client, LLMRequest $request, ?callable $feedbackCallback = null, ?AgentRunOptions $options = null): PromiseInterface {
        $guard = new AgentRunGuard($options ?? new AgentRunOptions());
        $this->logger?->requestStarted($request);

        return $this->sendAndProcessRequest($client, $request, $feedbackCallback, $guard);
    }

    /**
     * @return PromiseInterface<LLMResponse>
     */
    private function sendAndProcessRequest(LLMClient $client, LLMRequest $request, ?callable $feedbackCallback, AgentRunGuard $guard): PromiseInterface {
        try {
            $guard->beforeModelCall($request);
        } catch (AgentRunInterruptedException $e) {
            return Create::rejectionFor($e);
        }

        return $client->sendRequestAsync($request)->then(function (LLMResponse $response) use ($client, $feedbackCallback, $guard) {
            $this->logger?->requestFinished($response);
            $guard->afterModelResponse($response);

            if ($response->getStopReason() === StopReason::PAUSE_TURN) {
                // a paused server-side tool loop resumes when the same conversation is sent again
                $this->logger?->requestStarted($response->getRequest());

                return $this->sendAndProcessRequest($client, $response->getRequest(), $feedbackCallback, $guard);
            }

            if ($response->getStopReason() === StopReason::TOOL_USE) {
                return $this->processToolUseResponse($response, $client, $feedbackCallback, $guard);
            }

            return $this->postProcessResponse($response, $client, $feedbackCallback, $guard);
        });
    }

    /**
     * @return PromiseInterface<LLMResponse>
     */
    private function processToolUseResponse(LLMResponse $response, LLMClient $client, ?callable $feedbackCallback, AgentRunGuard $guard): PromiseInterface {
        $toolResults = [];
        foreach ($response->getConversation()->getLastMessage()->getContents() as $content) {
            if (!$content instanceof LLMMessageToolUse) {
                continue;
            }
            $guard->beforeToolCall($response, $toolResults);
            $toolResults[] = $this->callTool($response->getRequest()->getTools(), $content, $guard);
        }

        $newRequest = $response->getRequest()->withMessage(LLMMessage::createFromUser(new LLMMessageContents(Utils::unwrap($toolResults))));
        $this->logger?->requestStarted($newRequest);

        return $this->sendAndProcessRequest($client, $newRequest, $feedbackCallback, $guard);
    }

    /**
     * Runs one tool call; never rejects - every failure becomes an error result for the model
     *
     * @param ToolDefinition[] $tools
     * @return PromiseInterface<LLMMessageToolResult>
     */
    private function callTool(array $tools, LLMMessageToolUse $toolUse, AgentRunGuard $guard): PromiseInterface {
        $name = $toolUse->getName();
        $input = $toolUse->getInput();
        $guard->toolCallStarted($name, $input);
        $startedAt = hrtime(true);
        $finish = static function (LLMMessageContents $result) use ($guard, $toolUse, $name, $input, $startedAt): LLMMessageToolResult {
            $guard->toolCallFinished($name, $input, $result, intdiv(hrtime(true) - $startedAt, 1_000_000));

            return new LLMMessageToolResult($toolUse->getId(), $result);
        };

        $tool = null;
        foreach ($tools as $candidate) {
            if ($candidate->getName() === $name) {
                $tool = $candidate;
                break;
            }
        }
        if ($tool === null) {
            return Create::promiseFor($finish(LLMMessageContents::fromErrorString('ERROR: Tool "' . $name . '" is not available')));
        }

        $noContent = empty($input) && empty($tool->getInputSchema()['required']);
        if (!$noContent) {
            try {
                Schema::import(json_decode(json_encode($tool->getInputSchema())))->in(json_decode(json_encode($input)));
            } catch (Exception $e) {
                return Create::promiseFor($finish(LLMMessageContents::fromErrorString('ERROR: Input is not matching expected schema: ' . $e->getMessage())));
            }
        }

        try {
            $handled = $tool->handle($input);
        } catch (\Throwable $e) {
            $handled = self::toolErrorContents($e);
        }
        if ($handled instanceof LLMMessageContents) {
            // finish synchronous tools immediately so listener events and durations stay per tool
            return Create::promiseFor($finish($handled));
        }

        return $handled
            ->then(static fn(mixed $value): LLMMessageContents => self::toolResultContents($value))
            ->otherwise(static fn(mixed $reason): LLMMessageContents => self::toolErrorContents($reason))
            ->then($finish);
    }

    /**
     * @return PromiseInterface<LLMResponse>
     */
    private function postProcessResponse(LLMResponse $llmResponse, LLMClient $LLMClient, ?callable $feedbackCallback, AgentRunGuard $guard): PromiseInterface {
        $request = $llmResponse->getRequest();

        if ($feedbackCallback) {
            $feedback = $feedbackCallback($llmResponse);
            if ($feedback !== null) {
                if (!$feedback instanceof LLMMessage) {
                    throw new InvalidArgumentException('Feedback callback must return an instance of LLMMessage');
                }
                $request = $request->withMessage($feedback);

                return $this->sendAndProcessRequest($LLMClient, $request, $feedbackCallback, $guard);
            }
        }

        return Create::promiseFor($llmResponse);
    }

    private static function toolResultContents(mixed $value): LLMMessageContents {
        if ($value instanceof LLMMessageContents) {
            return $value;
        }

        return LLMMessageContents::fromErrorString('ERROR: tool returned ' . get_debug_type($value) . ', expected LLMMessageContents');
    }

    private static function toolErrorContents(mixed $reason): LLMMessageContents {
        if ($reason instanceof \Throwable) {
            return LLMMessageContents::fromErrorString('ERROR: ' . $reason::class . ': ' . $reason->getMessage());
        }

        return LLMMessageContents::fromErrorString('ERROR: tool failed: ' . (is_scalar($reason) ? (string) $reason : get_debug_type($reason)));
    }
}
