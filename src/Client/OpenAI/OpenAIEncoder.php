<?php

namespace Soukicz\Llm\Client\OpenAI;

use GuzzleHttp\Promise\Utils;
use Soukicz\Llm\Client\ModelEncoder;
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageImage;
use Soukicz\Llm\Message\LLMMessagePdf;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Tool\ToolResponse;

class OpenAIEncoder implements ModelEncoder {
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
                throw new \InvalidArgumentException('Unsupported message role');
            }
            $contents = [];
            foreach ($message->getContents() as $messageContent) {
                if ($messageContent instanceof LLMMessageText) {
                    $contents[] = [
                        'type' => 'text',
                        'text' => $messageContent->getText(),
                    ];
                } elseif ($messageContent instanceof LLMMessageImage) {
                    $contents[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => $messageContent->getEncoding(),
                            'media_type' => $messageContent->getMediaType(),
                            'data' => $messageContent->getData(),
                        ],
                    ];
                } elseif ($messageContent instanceof LLMMessagePdf) {
                    $contents[] = [
                        'type' => 'file',
                        'file' => [
                            'file_data' => 'data:application/pdf;base64,' . $messageContent->getData(),
                            'filename' => 'file.pdf',
                        ],
                    ];
                } elseif ($messageContent instanceof LLMMessageToolUse) {
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
                } elseif ($messageContent instanceof LLMMessageToolResult) {
                    $encodedMessages[] = [
                        'role' => 'tool',
                        'content' => is_string($messageContent->getContent())
                            ? $messageContent->getContent()
                            : json_encode($messageContent->getContent(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'tool_call_id' => $messageContent->getId(),
                    ];
                    continue 2;
                } else {
                    throw new \InvalidArgumentException('Unsupported message type');
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
                throw new \InvalidArgumentException('Unsupported reasoning config type');
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

        $request = $request->withMessage(LLMMessage::createFromAssistant($responseContents));

        $stopReason = match ($response['choices'][0]['finish_reason']) {
            'stop' => StopReason::FINISHED,
            'length' => StopReason::LENGTH,
            'tool_calls' => StopReason::TOOL_USE,
            default => throw new \InvalidArgumentException('Unsupported finish reason "' . $response['choices'][0]['finish_reason'] . '"'),
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
