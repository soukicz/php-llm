<?php

namespace Soukicz\Llm\Client;

use Exception;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use InvalidArgumentException;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Log\LLMLogger;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;
use Swaggest\JsonSchema\Schema;

class LLMAgentClient {
    public function __construct(private readonly ?LLMLogger $logger = null) {
    }

    public function run(LLMClient $client, LLMRequest $request, ?callable $feedbackCallback = null): LLMResponse {
        return $this->runAsync($client, $request, $feedbackCallback)->wait();
    }

    /**
     * @return PromiseInterface<LLMResponse>
     */
    public function runAsync(LLMClient $client, LLMRequest $request, ?callable $feedbackCallback = null): PromiseInterface {
        $this->logger?->requestStarted($request);

        return $this->sendAndProcessRequest($client, $request, $feedbackCallback);
    }

    /**
     * Helper method that handles the full request-response flow including tool use
     *
     * @return PromiseInterface<LLMResponse>
     */
    private function sendAndProcessRequest(LLMClient $client, LLMRequest $request, ?callable $feedbackCallback): PromiseInterface {
        return $client->sendRequestAsync($request)->then(function (LLMResponse $response) use ($client, $request, $feedbackCallback) {
            $this->logger?->requestFinished($response);

            // First check for and handle any tool use - this has highest priority
            if ($response->getStopReason() === StopReason::TOOL_USE) {
                return $this->processToolUseResponse($response, $client, $request, $feedbackCallback);
            }

            // If no tool use, then process other response types (feedback)
            return $this->postProcessResponse($response, $client, $feedbackCallback);
        });
    }

    /**
     * Process a response that contains tool use requests
     *
     * @return PromiseInterface<LLMResponse>
     */
    private function processToolUseResponse(LLMResponse $response, LLMClient $client, LLMRequest $request, ?callable $feedbackCallback): PromiseInterface {
        $toolResponseContents = [];

        foreach ($response->getConversation()->getLastMessage()->getContents() as $content) {
            if (!$content instanceof LLMMessageToolUse) {
                continue;
            }

            $tool = null;
            foreach ($request->getTools() as $candidateTool) {
                if ($candidateTool->getName() === $content->getName()) {
                    $tool = $candidateTool;
                    break;
                }
            }

            if ($tool === null) {
                $toolResponseContents[] = Create::promiseFor(new LLMMessageToolResult(
                    $content->getId(),
                    LLMMessageContents::fromErrorString('ERROR: Tool "' . $content->getName() . '" is not available')
                ));
                continue;
            }

            $input = $content->getInput();
            $noContent = empty($input) && empty($tool->getInputSchema()['required']);

            if (!$noContent) {
                try {
                    Schema::import(json_decode(json_encode($tool->getInputSchema())))->in(json_decode(json_encode($input)));
                } catch (Exception $e) {
                    $toolResponseContents[] = Create::promiseFor(new LLMMessageToolResult(
                        $content->getId(),
                        LLMMessageContents::fromErrorString('ERROR: Input is not matching expected schema: ' . $e->getMessage())
                    ));
                    continue;
                }
            }

            try {
                $toolResponse = $tool->handle($input);
            } catch (\Throwable $e) {
                $toolResponse = self::toolErrorContents($e);
            }
            if ($toolResponse instanceof LLMMessageContents) {
                $toolResponse = Create::promiseFor($toolResponse);
            }
            $toolUseId = $content->getId();
            $toolResponseContents[] = $toolResponse
                ->then(static fn(mixed $value): LLMMessageContents => self::toolResultContents($value))
                ->otherwise(static fn(mixed $reason): LLMMessageContents => self::toolErrorContents($reason))
                ->then(static fn(LLMMessageContents $result): LLMMessageToolResult => new LLMMessageToolResult($toolUseId, $result));
        }

        $newRequest = $response->getRequest()->withMessage(LLMMessage::createFromUser(new LLMMessageContents(Utils::unwrap($toolResponseContents))));
        $this->logger?->requestStarted($newRequest);

        // Use sendAndProcessRequest to ensure full processing of the response, including potential nested tool uses
        return $this->sendAndProcessRequest($client, $newRequest, $feedbackCallback);
    }

    private function postProcessResponse(LLMResponse $llmResponse, LLMClient $LLMClient, ?callable $feedbackCallback): PromiseInterface {
        $request = $llmResponse->getRequest();

        if ($feedbackCallback) {
            $feedback = $feedbackCallback($llmResponse);
            if ($feedback !== null) {
                if (!$feedback instanceof LLMMessage) {
                    throw new InvalidArgumentException('Feedback callback must return an instance of LLMMessage');
                }
                $request = $request->withMessage($feedback);

                return $this->sendAndProcessRequest($LLMClient, $request, $feedbackCallback);
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
