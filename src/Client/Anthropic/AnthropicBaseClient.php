<?php

namespace Soukicz\PhpLlm\Client\Anthropic;

use Soukicz\PhpLlm\Client\LLMBaseClient;
use Soukicz\PhpLlm\Message\LLMMessage;
use Soukicz\PhpLlm\Message\LLMMessageImage;
use Soukicz\PhpLlm\Message\LLMMessageText;
use Soukicz\PhpLlm\Message\LLMMessageToolResult;
use Soukicz\PhpLlm\Message\LLMMessageToolUse;
use Soukicz\PhpLlm\LLMRequest;
use Soukicz\PhpLlm\LLMResponse;
use Soukicz\PhpLlm\ToolResponse;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;

abstract class AnthropicBaseClient extends LLMBaseClient {

    public function sendPrompt(LLMRequest $request): LLMResponse {
        return $this->sendPromptAsync($request)->wait();
    }

    protected function encodeRequest(LLMRequest $request): array {
        $encodedMessages = [];
        foreach ($request->getMessages() as $message) {
            $role = $message->isUser() ? 'user' : 'assistant';
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
                        'image_url' => [
                            'url' => $messageContent->getData(),
                        ],
                    ];
                } elseif ($messageContent instanceof LLMMessageToolUse) {
                    $contents[] = [
                        'type' => 'tool_use',
                        'id' => $messageContent->getId(),
                        'name' => $messageContent->getName(),
                        'input' => $messageContent->getInput(),
                    ];
                } elseif ($messageContent instanceof LLMMessageToolResult) {
                    if (is_string($messageContent->getContent())) {
                        $content = $messageContent->getContent();
                    } else {
                        $content = json_encode($messageContent->getContent(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    $contents[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $messageContent->getId(),
                        'content' => $content,
                    ];
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
        if (!empty($request->getStopSequences())) {
            $options['stop_sequences'] = $request->getStopSequences();
        }
        if (!empty($request->getTools())) {
            $options['tool_choice'] = [
                'type' => 'auto',
            ];
            $options['tools'] = [];

            foreach ($request->getTools() as $tool) {
                $options['tools'][] = [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'input_schema' => $tool->getInputSchema()
                ];
            }
        }

        return $options;
    }

    abstract protected function invokeModel(array $data): PromiseInterface;

    public function sendPromptAsync(LLMRequest $request): PromiseInterface {
        return $this->invokeModel($this->encodeRequest($request))->then(function (array $response) use ($request) {
            $responseContents = [];
            foreach ($response['content'] as $content) {
                if ($content['type'] === 'text') {
                    $responseContents[] = new LLMMessageText($content['text']);
                } elseif ($content['type'] === 'tool_use') {
                    $responseContents[] = new LLMMessageToolUse($content['id'], $content['name'], $content['input']);
                } else {
                    throw new \InvalidArgumentException('Unsupported message type');
                }
            }
            $request = $request->withMessage(LLMMessage::createFromAssistant($responseContents));

            if (strpos($request->getModel(), 'haiku') !== false) {
                $inputPrice = $response['usage']['input_tokens'] * (1 / 1000 / 1000);
                $outputPrice = $response['usage']['output_tokens'] * (5 / 1000 / 1000);
            } else {
                $inputPrice = $response['usage']['input_tokens'] * (3 / 1000 / 1000);
                $outputPrice = $response['usage']['output_tokens'] * (15 / 1000 / 1000);
            }

            $request = $request->withCost($inputPrice, $outputPrice);

            if ($response['stop_reason'] === 'tool_use') {
                $toolResponseContents = [];

                foreach ($response['content'] as $content) {
                    if ($content['type'] === 'tool_use') {
                        foreach ($request->getTools() as $tool) {
                            if ($tool->getName() === $content['name']) {
                                $toolResponseContents[] = $tool->handle($content['id'], $content['input'])->then(static function (ToolResponse $response) {
                                    return new LLMMessageToolResult($response->getId(), $response->getData());
                                });
                            }
                        }
                    }
                }
                $request = $request->withMessage(LLMMessage::createFromUser(Utils::unwrap($toolResponseContents)));

                return $this->sendPromptAsync($request);
            }

            $llmResponse = new LLMResponse(
                $request->getMessages(),
                $response['stop_reason'],
                $request->getPreviousInputCostUSD(),
                $request->getPreviousOutputCostUSD()
            );

            return $this->postProcessResponse($request, $llmResponse);
        });
    }
}
