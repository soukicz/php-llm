<?php

namespace Soukicz\Llm\Client;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;

abstract class LLMBaseClient implements LLMClient {

    protected function postProcessResponse(LLMRequest $request, LLMResponse $llmResponse): PromiseInterface {
        if ($llmResponse->getStopReason() === 'max_tokens' && $request->getContinuationCallback()) {
            $continuationCallback = $request->getContinuationCallback();
            $request = $continuationCallback($request);

            if (!$request->getLastMessage()->isAssistant()) {
                return $this->sendPromptAsync($request);
            }
            $llmResponse = new LLMResponse(
                $request->getMessages(),
                $llmResponse->getStopReason(),
                $llmResponse->getInputTokens(),
                $llmResponse->getOutputTokens(),
                $llmResponse->getMaximumOutputTokens(),
                $llmResponse->getInputPriceUsd(),
                $llmResponse->getOutputPriceUsd(),
                $llmResponse->getTotalTimeMs()
            );
        }

        $feedbackCallback = $request->getFeedbackCallback();
        if ($feedbackCallback) {
            $feedback = $feedbackCallback($llmResponse);
            if ($feedback !== null) {
                if (!$feedback instanceof LLMMessage) {
                    throw new \InvalidArgumentException('Feedback callback must return an instance of LLMMessage');
                }
                $request = $request->withMessage($feedback);

                return $this->sendPromptAsync($request);
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
