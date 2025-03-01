<?php

namespace Soukicz\Llm\Client;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;

class LLMChainClient {

    public function run(LLMClient $LLMClient, LLMRequest $LLMRequest, ?callable $continuationCallback = null, ?callable $feedbackCallback = null): LLMResponse {
        return $this->runAsync($LLMClient, $LLMRequest, $continuationCallback, $feedbackCallback)->wait();
    }

    public function runAsync(LLMClient $LLMClient, LLMRequest $LLMRequest, ?callable $continuationCallback = null, ?callable $feedbackCallback = null): PromiseInterface {
        return $LLMClient->sendPromptAsync($LLMRequest)->then(function (LLMResponse $response) use ($LLMClient, $continuationCallback, $feedbackCallback) {
            return $this->postProcessResponse($response, $LLMClient, $continuationCallback, $feedbackCallback);
        });
    }

    private function postProcessResponse(LLMResponse $llmResponse, LLMClient $LLMClient, ?callable $continuationCallback, ?callable $feedbackCallback): PromiseInterface {
        $request = $llmResponse->getRequest();
        if ($continuationCallback && $llmResponse->getStopReason() === 'max_tokens') {
            $request = $continuationCallback($request);

            if (!$request->getLastMessage()->isAssistant()) {
                return $LLMClient->sendPromptAsync($request);
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
            $feedback = $feedbackCallback($llmResponse, $request);
            if ($feedback !== null) {
                if (!$feedback instanceof LLMMessage) {
                    throw new \InvalidArgumentException('Feedback callback must return an instance of LLMMessage');
                }
                $request = $request->withMessage($feedback);

                return $LLMClient->sendPromptAsync($request);
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
