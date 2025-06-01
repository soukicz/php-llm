<?php

namespace Soukicz\Llm\Client\OpenAI;

use InvalidArgumentException;
use Soukicz\Llm\Client\ModelEncoder;
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageArrayData;
use Soukicz\Llm\Message\LLMMessageContent;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageImage;
use Soukicz\Llm\Message\LLMMessagePdf;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;

class OpenAIEncoder implements ModelEncoder {
    private function encodeMessageContent(LLMMessageContent $content): array {
        if ($content instanceof LLMMessageText) {
            return [
                'type' => 'text',
                'text' => $content->getText(),
            ];
        }
        if ($content instanceof LLMMessageArrayData) {
            return [
                'type' => 'text',
                'text' => json_encode($content->getData(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
        if ($content instanceof LLMMessageImage) {
            return [
                'type' => 'image',
                'source' => [
                    'type' => $content->getEncoding(),
                    'media_type' => $content->getMediaType(),
                    'data' => $content->getData(),
                ],
            ];
        }
        if ($content instanceof LLMMessagePdf) {
            return [
                'type' => 'file',
                'file' => [
                    'file_data' => 'data:application/pdf;base64,' . $content->getData(),
                    'filename' => 'file.pdf',
                ],
            ];
        }

        throw new InvalidArgumentException('Unsupported message content type: ' . get_class($content));
    }

    public function encodeRequest(LLMRequest $request): array {
        $encodedMessages = [];
        foreach ($request->getConversation()->getMessages() as $message) {
            if ($message->isUser()) {
                $role = 'user';
            } elseif ($message->isAssistant()) {
                $role = 'assistant';
            } elseif ($message->isSystem()) {
                $role = 'system';
            } else {
                throw new InvalidArgumentException('Unsupported message role');
            }
            $contents = [];
            foreach ($message->getContents() as $messageContent) {
                if ($messageContent instanceof LLMMessageToolUse) {
                    $encodedMessages[] = [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => $messageContent->getId(),
                                'type' => 'function',
                                'function' => [
                                    'name' => $messageContent->getName(),
                                    'arguments' => json_encode($messageContent->getInput(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                ],
                            ],
                        ],
                    ];
                    continue 2;
                }
                if ($messageContent instanceof LLMMessageToolResult) {
                    // For OpenAI, tool results should be serialized as JSON strings
                    $contentParts = [];
                    foreach ($messageContent->getContent() as $toolMessage) {
                        if ($toolMessage instanceof LLMMessageText) {
                            $contentParts[] = $toolMessage->getText();
                        } elseif ($toolMessage instanceof LLMMessageArrayData) {
                            $contentParts[] = json_encode($toolMessage->getData(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        } else {
                            // For other content types, encode them and extract text representation
                            $encoded = $this->encodeMessageContent($toolMessage);
                            if (isset($encoded['text'])) {
                                $contentParts[] = $encoded['text'];
                            } else {
                                $contentParts[] = json_encode($encoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            }
                        }
                    }

                    $encodedMessages[] = [
                        'role' => 'tool',
                        'content' => implode('', $contentParts),
                        'tool_call_id' => $messageContent->getId(),
                    ];
                    continue 2;
                } else {
                    $contents[] = $this->encodeMessageContent($messageContent);
                }
            }
            $encodedMessages[] = [
                'role' => $role,
                'content' => $contents,
            ];
        }

        $requestData = [
            'model' => $request->getModel()->getCode(),
            'messages' => $encodedMessages,
            'max_completion_tokens' => $request->getMaxTokens(),
            'temperature' => $request->getTemperature(),
        ];

        $reasoningConfig = $request->getReasoningConfig();
        if ($reasoningConfig) {
            if ($reasoningConfig instanceof ReasoningEffort) {
                $requestData['reasoning_effort'] = $reasoningConfig->value;
            } else {
                throw new InvalidArgumentException('Unsupported reasoning config type');
            }
        }

        if (!empty($request->getStopSequences())) {
            $requestData['stop'] = $request->getStopSequences();
        }

        if (!empty($request->getTools())) {
            $requestData['tools'] = [];
            foreach ($request->getTools() as $tool) {
                $requestData['tools'][] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool->getName(),
                        'description' => $tool->getDescription(),
                        'parameters' => $tool->getInputSchema(),
                    ],
                ];
            }
        }

        return $requestData;
    }

    public function decodeResponse(LLMRequest $request, ModelResponse $modelResponse): LLMRequest|LLMResponse {
        $response = $modelResponse->getData();
        $model = $request->getModel();

        if (isset($response['usage'])) {
            $promptTokens = $response['usage']['prompt_tokens'];
            $completionTokens = $response['usage']['completion_tokens'];

            $inputPrice = $promptTokens * ($model->getInputPricePerMillionTokens() / 1_000_000);
            $outputPrice = $completionTokens * ($model->getOutputPricePerMillionTokens() / 1_000_000);

            $request = $request->withCost($promptTokens, $completionTokens, $inputPrice, $outputPrice);
        }

        $request = $request->withTime((int) $modelResponse->getResponseTimeMs());

        $assistantMessage = $response['choices'][0]['message'];
        $responseContents = [];

        if (isset($assistantMessage['content'])) {
            $responseContents[] = new LLMMessageText($assistantMessage['content']);
        }

        if (!empty($assistantMessage['tool_calls'])) {
            foreach ($assistantMessage['tool_calls'] as $toolCall) {
                if ($toolCall['type'] === 'function') {
                    $responseContents[] = new LLMMessageToolUse(
                        $toolCall['id'],
                        $toolCall['function']['name'],
                        json_decode($toolCall['function']['arguments'], true, 512, JSON_THROW_ON_ERROR)
                    );
                }
            }
        }

        $request = $request->withMessage(LLMMessage::createFromAssistant(new LLMMessageContents($responseContents)));

        $stopReason = match ($response['choices'][0]['finish_reason']) {
            'stop' => StopReason::FINISHED,
            'length' => StopReason::LENGTH,
            'tool_calls' => StopReason::TOOL_USE,
            default => throw new InvalidArgumentException('Unsupported finish reason "' . $response['choices'][0]['finish_reason'] . '"'),
        };

        return new LLMResponse(
            $request,
            $stopReason,
            $request->getPreviousInputTokens(),
            $request->getPreviousOutputTokens(),
            $request->getPreviousMaximumOutputTokens(),
            $request->getPreviousInputCostUSD(),
            $request->getPreviousOutputCostUSD(),
            $request->getPreviousTimeMs()
        );
    }
}
