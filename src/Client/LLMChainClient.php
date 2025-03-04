<?php

namespace Soukicz\Llm\Client;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;
use Soukicz\Llm\Tool\ToolResponse;

class LLMChainClient {

    public function run(LLMClient $client, LLMRequest $request, ?callable $continuationCallback = null, ?callable $feedbackCallback = null): LLMResponse {
        return $this->runAsync($client, $request, $continuationCallback, $feedbackCallback)->wait();
    }

    /**
     * @return PromiseInterface<LLMResponse>
     */
    public function runAsync(LLMClient $client, LLMRequest $request, ?callable $continuationCallback = null, ?callable $feedbackCallback = null): PromiseInterface {
        return $client->sendRequestAsync($request)->then(function (LLMResponse $response) use ($client, $continuationCallback, $feedbackCallback) {
            return $this->postProcessResponse($response, $client, $continuationCallback, $feedbackCallback);
        })->then(function (LLMResponse $response) use ($client, $request, $continuationCallback, $feedbackCallback): LLMResponse|PromiseInterface {
            if ($response->getStopReason() === StopReason::TOOL_USE) {
                $toolResponseContents = [];

                foreach ($response->getConversation()->getLastMessage()->getContents() as $content) {
                    if ($content instanceof LLMMessageToolUse) {
                        foreach ($request->getTools() as $tool) {
                            if ($tool->getName() === $content->getName()) {
                                $toolResponseContents[] = $tool->handle($content->getId(), $content->getInput())->then(static function (ToolResponse $response) {
                                    return new LLMMessageToolResult($response->getId(), $response->getData());
                                });
                            }
                        }
                    }
                }

                $newRequest = $response->getRequest()->withMessage(LLMMessage::createFromUser(Utils::unwrap($toolResponseContents)));

                return $this->runAsync($client, $newRequest, $continuationCallback, $feedbackCallback);
            }

            return $response;
        });
    }

    private function postProcessResponse(LLMResponse $llmResponse, LLMClient $LLMClient, ?callable $continuationCallback, ?callable $feedbackCallback): PromiseInterface {
        $request = $llmResponse->getRequest();
        if ($continuationCallback && $llmResponse->getStopReason() === StopReason::LENGTH) {
            $request = $continuationCallback($llmResponse);

            if (!$request->getLastMessage()->isAssistant()) {
                return $LLMClient->sendRequestAsync($request)->then(function (LLMResponse $continuedResponse) use ($LLMClient, $continuationCallback, $feedbackCallback) {
                    return $this->postProcessResponse($continuedResponse, $LLMClient, $continuationCallback, $feedbackCallback);
                });
            }
            $llmResponse = new LLMResponse(
                $request,
                $llmResponse->getStopReason(),
                $llmResponse->getInputTokens(),
                $llmResponse->getOutputTokens(),
                $llmResponse->getMaximumOutputTokens(),
                $llmResponse->getInputPriceUsd(),
                $llmResponse->getOutputPriceUsd(),
                $llmResponse->getTotalTimeMs()
            );
        }

        if ($feedbackCallback) {
            $feedback = $feedbackCallback($llmResponse);
            if ($feedback !== null) {
                if (!$feedback instanceof LLMMessage) {
                    throw new \InvalidArgumentException('Feedback callback must return an instance of LLMMessage');
                }
                $request = $request->withMessage($feedback);

                return $LLMClient->sendRequestAsync($request)->then(function (LLMResponse $continuedResponse) use ($LLMClient, $continuationCallback, $feedbackCallback) {
                    return $this->postProcessResponse($continuedResponse, $LLMClient, $continuationCallback, $feedbackCallback);
                });
            }
        }

        return Create::promiseFor($llmResponse);
    }

    public static function continueTagResponse(LLMRequest $request, array $outputTags, string $continueMessageText = 'Continue'): LLMRequest {
        $request = $request->withMergedMessages();
        $request = $request->withPostProcessLastMessageText(function (string $text) use ($outputTags) {
            return self::removeIncompleteOutputTags($outputTags, $text);
        });

        return $request->withMessage(LLMMessage::createFromUserContinue(new LLMMessageText($continueMessageText)));
    }

    private static function removeIncompleteOutputTags(array $outputTags, string $response): string {
        foreach ($outputTags as $tag) {
            $lastOpeningTagPos = strrpos($response, '<' . $tag);

            // Find the position of the last closing tag
            $lastClosingTagPos = strrpos($response, '</' . $tag . '>');

            // If there's an opening tag after the last closing tag, or if there's an opening tag but no closing tag
            if (($lastOpeningTagPos !== false && $lastClosingTagPos === false) ||
                ($lastOpeningTagPos !== false && $lastClosingTagPos !== false && $lastOpeningTagPos > $lastClosingTagPos)) {
                // Remove everything from the last opening tag to the end of the string
                $response = substr($response, 0, $lastOpeningTagPos);
            }
        }

        return rtrim($response);
    }
}
