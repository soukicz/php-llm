<?php

namespace Soukicz\Llm\Client\Anthropic;

use Soukicz\Llm\Client\ModelEncoder;
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\Config\ReasoningBudget;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContent;
use Soukicz\Llm\Message\LLMMessageImage;
use Soukicz\Llm\Message\LLMMessagePdf;
use Soukicz\Llm\Message\LLMMessageReasoning;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;

class AnthropicEncoder implements ModelEncoder {

    private function addCacheAttribute(LLMMessageContent $content, array $data): array {
        if ($content->isCached()) {
            $data['cache_control'] = ['type' => 'ephemeral'];
        }

        return $data;
    }

    public function encodeRequest(LLMRequest $request): array {
        $systemPrompt = null;
        $encodedMessages = [];
        foreach ($request->getConversation()->getMessages() as $message) {
            if ($message->isUser()) {
                $role = 'user';
            } elseif ($message->isAssistant()) {
                $role = 'assistant';
            } elseif ($message->isSystem()) {
                if ($systemPrompt !== null) {
                    throw new \InvalidArgumentException('Multiple system messages');
                }
                if (count($message->getContents()) !== 1) {
                    throw new \InvalidArgumentException('System message supports only one content block');
                }
                $content = $message->getContents()[0];
                if ($content instanceof LLMMessageText) {
                    $systemPrompt = $content->getText();
                } else {
                    throw new \InvalidArgumentException('Unsupported system message type');
                }
                continue;
            } else {
                throw new \InvalidArgumentException('Unsupported message role');
            }

            $contents = [];
            foreach ($message->getContents() as $messageContent) {
                if ($messageContent instanceof LLMMessageText) {
                    $contents[] = $this->addCacheAttribute($messageContent, [
                        'type' => 'text',
                        'text' => $messageContent->getText(),
                    ]);
                } elseif ($messageContent instanceof LLMMessageReasoning) {
                    $contents[] = $this->addCacheAttribute($messageContent, [
                        'type' => 'thinking',
                        'thinking' => $messageContent->getText(),
                        'signature' => $messageContent->getSignature(),
                    ]);
                } elseif ($messageContent instanceof LLMMessageImage) {
                    $contents[] = $this->addCacheAttribute($messageContent, [
                        'type' => 'image',
                        'source' => [
                            'type' => $messageContent->getEncoding(),
                            'media_type' => $messageContent->getMediaType(),
                            'data' => $messageContent->getData(),
                        ],
                    ]);
                } elseif ($messageContent instanceof LLMMessagePdf) {
                    $contents[] = $this->addCacheAttribute($messageContent, [
                        'type' => 'document',
                        'source' => [
                            'type' => $messageContent->getEncoding(),
                            'media_type' => 'application/pdf',
                            'data' => $messageContent->getData(),
                        ],
                    ]);
                } elseif ($messageContent instanceof LLMMessageToolUse) {
                    $input = [
                        'type' => 'tool_use',
                        'id' => $messageContent->getId(),
                        'name' => $messageContent->getName(),
                        'input' => $messageContent->getInput(),
                    ];
                    if (empty($input['input'])) {
                        $input['input'] = new \stdClass();
                    }
                    $contents[] = $this->addCacheAttribute($messageContent, $input);
                } elseif ($messageContent instanceof LLMMessageToolResult) {
                    if (is_string($messageContent->getContent())) {
                        $content = $messageContent->getContent();
                    } else {
                        $content = json_encode($messageContent->getContent(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    $contents[] = $this->addCacheAttribute($messageContent, [
                        'type' => 'tool_result',
                        'tool_use_id' => $messageContent->getId(),
                        'content' => $content,
                    ]);
                } else {
                    throw new \InvalidArgumentException('Unsupported message type ' . get_class($messageContent));
                }
            }
            $encodedMessages[] = [
                'role' => $role,
                'content' => $contents,
            ];
        }

        $options = [
            'max_tokens' => $request->getMaxTokens(),
            'temperature' => $request->getTemperature(),
            'messages' => $encodedMessages,
            'model' => $request->getModel(),
        ];

        $reasoningConfig = $request->getReasoningConfig();
        if ($reasoningConfig) {
            if ($reasoningConfig instanceof ReasoningBudget) {
                $options['thinking'] = [
                    'type' => 'enabled',
                    'budget_tokens' => $reasoningConfig->getMaxTokens(),
                ];
            } else {
                throw new \InvalidArgumentException('Unsupported reasoning config type');
            }
        }

        if ($systemPrompt !== null) {
            $options['system'] = $systemPrompt;
        }
        if (!empty($request->getStopSequences())) {
            $options['stop_sequences'] = $request->getStopSequences();
        }
        if (!empty($request->getTools())) {
            $options['tool_choice'] = [
                'type' => 'auto',
            ];
            $options['tools'] = [];

            foreach ($request->getTools() as $tool) {
                $schema = $tool->getInputSchema();
                if (empty($schema['properties'])) {
                    $schema['properties'] = new \stdClass();
                }
                $options['tools'][] = [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'input_schema' => $schema,
                ];
            }
        }

        return $options;
    }

    public function decodeResponse(LLMRequest $request, ModelResponse $modelResponse): LLMRequest|LLMResponse {
        $response = $modelResponse->getData();
        $responseTimeMs = $modelResponse->getResponseTimeMs();

        $responseContents = [];
        foreach ($response['content'] as $content) {
            if ($content['type'] === 'text') {
                $responseContents[] = new LLMMessageText($content['text']);
            } elseif ($content['type'] === 'thinking') {
                $responseContents[] = new LLMMessageReasoning($content['thinking'], $content['signature']);
            } elseif ($content['type'] === 'tool_use') {
                $responseContents[] = new LLMMessageToolUse($content['id'], $content['name'], $content['input']);
            } else {
                throw new \InvalidArgumentException('Unsupported message type');
            }
        }
        $request = $request->withMessage(LLMMessage::createFromAssistant($responseContents));

        $cacheInputTokens = $response['usage']['cache_creation_input_tokens'] ?? 0;
        $cacheReadInputTokens = $response['usage']['cache_read_input_tokens'] ?? 0;
        if (str_contains($request->getModel(), 'haiku')) {
            $inputPrice = $response['usage']['input_tokens'] * (0.8 / 1000 / 1000);
            $outputPrice = $response['usage']['output_tokens'] * (4 / 1000 / 1000);

            $inputPrice += $cacheInputTokens * (1 / 1000 / 1000);
            $outputPrice += $cacheReadInputTokens * (0.08 / 1000 / 1000);
        } else {
            $inputPrice = $response['usage']['input_tokens'] * (3 / 1000 / 1000);
            $outputPrice = $response['usage']['output_tokens'] * (15 / 1000 / 1000);

            $inputPrice += $cacheInputTokens * (3.75 / 1000 / 1000);
            $outputPrice += $cacheReadInputTokens * (0.3 / 1000 / 1000);
        }

        $request = $request
            ->withCost(
                $response['usage']['input_tokens'],
                $response['usage']['output_tokens'],
                $inputPrice,
                $outputPrice
            )
            ->withTime($responseTimeMs);

        $stopReason = match ($response['stop_reason']) {
            'end_turn' => StopReason::FINISHED,
            'max_tokens' => StopReason::LENGTH,
            'tool_use' => StopReason::TOOL_USE,
            default => throw new \InvalidArgumentException('Unsupported stop reason "' . $response['stop_reason'] . '"'),
        };

        return new LLMResponse(
            $request,
            $stopReason,
            $request->getPreviousInputTokens(),
            $request->getPreviousOutputTokens(),
            $request->getPreviousMaximumOutputTokens(),
            $request->getPreviousInputCostUSD(),
            $request->getPreviousOutputCostUSD(),
            $request->getPreviousTimeMs(),
        );
    }
}
