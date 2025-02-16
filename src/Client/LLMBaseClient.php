<?php

namespace Soukicz\PhpLlm\Client;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\PhpLlm\Message\LLMMessage;
use Soukicz\PhpLlm\Message\LLMMessageText;
use Soukicz\PhpLlm\LLMRequest;
use Soukicz\PhpLlm\LLMResponse;

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

    public static function continueFileResponse(LLMRequest $request, array $outputTags): LLMRequest {
        $request = $request->withMergedMessages();
        $request->withPostProcessLastMessageText(function (string $text) use ($outputTags) {
            return self::removeIncompleteOutputTags($outputTags, $text);
        });

        return $request->withMessage(LLMMessage::createFromUserContinue(new LLMMessageText('Continue')));
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
