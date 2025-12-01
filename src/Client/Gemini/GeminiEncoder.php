<?php

namespace Soukicz\Llm\Client\Gemini;

use Soukicz\Llm\Client\Gemini\Model\GeminiImageModel;
use Soukicz\Llm\Client\ModelEncoder;
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageImage;
use Soukicz\Llm\Message\LLMMessagePdf;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;

class GeminiEncoder implements ModelEncoder {

    protected array $safetySettings = [];

    public function encodeRequest(LLMRequest $request): array {
        $contents = [];
        $systemInstruction = null;

        foreach ($request->getConversation()->getMessages() as $message) {
            if ($message->isSystem()) {
                // Gemini uses separate system_instruction field
                $systemTexts = [];
                foreach ($message->getContents() as $messageContent) {
                    if ($messageContent instanceof LLMMessageText) {
                        $systemTexts[] = $messageContent->getText();
                    }
                }
                if (!empty($systemTexts)) {
                    $systemInstruction = ['parts' => [['text' => implode("\n", $systemTexts)]]];
                }
                continue;
            }

            $parts = [];
            foreach ($message->getContents() as $messageContent) {
                if ($messageContent instanceof LLMMessageText) {
                    $parts[] = ['text' => $messageContent->getText()];
                } elseif ($messageContent instanceof LLMMessageImage) {
                    $parts[] = [
                        'inline_data' => [
                            'mime_type' => $messageContent->getMediaType(),
                            'data' => $messageContent->getData(),
                        ],
                    ];
                } elseif ($messageContent instanceof LLMMessageToolUse) {
                    // Function call in Gemini format
                    $contents[] = [
                        'role' => 'model',
                        'parts' => [
                            [
                                'function_call' => [
                                    'name' => $messageContent->getName(),
                                    'args' => $messageContent->getInput(),
                                ],
                            ],
                        ],
                    ];
                    continue 2;
                } elseif ($messageContent instanceof LLMMessageToolResult) {
                    // Function response in Gemini format
                    $contents[] = [
                        'role' => 'function',
                        'parts' => [
                            [
                                'function_response' => [
                                    'name' => 'function_' . $messageContent->getId(), // Create a name from ID
                                    'response' => [
                                        'content' => $messageContent->getContent(),
                                    ],
                                ],
                            ],
                        ],
                    ];
                    continue 2;
                } elseif ($messageContent instanceof LLMMessagePdf) {
                    // PDF handling - Gemini doesn't have native PDF support like OpenAI
                    // We could potentially convert this to a different format or implement
                    // a custom solution for handling PDFs with Gemini
                    throw new \InvalidArgumentException('PDF content type not supported for Gemini');
                } else {
                    throw new \InvalidArgumentException('Unsupported message content type for Gemini');
                }
            }

            if (!empty($parts)) {
                $contents[] = [
                    'role' => $message->isUser() ? 'user' : 'model',
                    'parts' => $parts,
                ];
            }
        }

        $requestData = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $request->getTemperature(),
                'maxOutputTokens' => $request->getMaxTokens(),
            ],
        ];
        if (!empty($this->safetySettings)) {
            $requestData['safetySettings'] = $this->safetySettings;
        }

        $model = $request->getModel();
        if ($model instanceof GeminiImageModel && ($model->getAspectRatio() !== null || $model->getImageSize() !== null)) {
            $requestData['generationConfig']['imageConfig'] = [];
            if ($model->getAspectRatio() !== null) {
                $requestData['generationConfig']['imageConfig']['aspectRatio'] = $model->getAspectRatio();
            }
            if ($model->getImageSize() !== null) {
                $requestData['generationConfig']['imageConfig']['imageSize'] = $model->getImageSize();
            }
        }

        if (!empty($request->getStopSequences())) {
            $requestData['generationConfig']['stopSequences'] = $request->getStopSequences();
        }

        // Add system instruction if present
        if ($systemInstruction !== null) {
            $requestData['systemInstruction'] = $systemInstruction;
        }

        $reasoningConfig = $request->getReasoningConfig();
        if ($reasoningConfig) {
            if ($reasoningConfig instanceof ReasoningEffort) {
                switch ($reasoningConfig->value) {
                    case 'default':
                        // Default, no adjustments needed
                        break;
                    case 'high':
                        // For high reasoning, increase temperature slightly and adjust topP/topK
                        $requestData['generationConfig']['temperature'] = min(1.0, $request->getTemperature() * 1.2);
                        $requestData['generationConfig']['topP'] = 0.9;
                        $requestData['generationConfig']['topK'] = 40;
                        break;
                    case 'auto':
                        // Auto reasoning, use more balanced settings
                        $requestData['generationConfig']['topP'] = 0.8;
                        $requestData['generationConfig']['topK'] = 20;
                        break;
                    default:
                        throw new \InvalidArgumentException('Unsupported reasoning effort: ' . $reasoningConfig->value);
                }
            } else {
                throw new \InvalidArgumentException('Unsupported reasoning config type');
            }
        }

        if (!empty($request->getTools())) {
            $requestData['tools'] = [];
            foreach ($request->getTools() as $tool) {
                $requestData['tools'][] = [
                    'functionDeclarations' => [
                        [
                            'name' => $tool->getName(),
                            'description' => $tool->getDescription(),
                            'parameters' => $tool->getInputSchema(),
                        ],
                    ],
                ];
            }
        }

        return $requestData;
    }

    public function decodeResponse(LLMRequest $request, ModelResponse $modelResponse): LLMRequest|LLMResponse {
        $response = $modelResponse->getData();
        $model = $request->getModel();

        $request = $request->withTime((int) $modelResponse->getResponseTimeMs());

        $candidate = $response['candidates'][0];
        $responseContents = [];

        $toolCall = false;

        // Extract text content
        if (isset($candidate['content']['parts'])) {
            foreach ($candidate['content']['parts'] as $part) {
                if (isset($part['text'])) {
                    $responseContents[] = new LLMMessageText($part['text']);
                } elseif (isset($part['functionCall'])) {
                    $toolCall = true;
                    $responseContents[] = new LLMMessageToolUse(
                        uniqid('gemini_tool_', true),  // Gemini doesn't provide tool IDs so we generate one
                        $part['functionCall']['name'],
                        $part['functionCall']['args']
                    );
                } elseif (isset($part['inlineData']) && in_array($part['inlineData']['mimeType'], ['image/jpeg', 'image/png'], true)) {
                    $responseContents[] = new LLMMessageImage('base64', $part['inlineData']['mimeType'], $part['inlineData']['data']);

                }
            }
        }

        $request = $request->withMessage(LLMMessage::createFromAssistant(new LLMMessageContents($responseContents)));

        // Map Gemini finish reasons to StopReason
        $stopReason = match ($candidate['finishReason'] ?? 'STOP') {
            'STOP', 'FINISH_REASON_STOP' => StopReason::FINISHED,
            'MAX_TOKENS', 'FINISH_REASON_MAX_TOKENS' => StopReason::LENGTH,
            'RECITATION', 'SAFETY', 'PROHIBITED_CONTENT', 'FINISH_REASON_SAFETY' => StopReason::SAFETY,
            'FUNCTION_CALL', 'FINISH_REASON_TOOL' => StopReason::TOOL_USE,
            default => StopReason::FINISHED,
        };

        if ($toolCall) {
            $stopReason = StopReason::TOOL_USE;
        }

        if (isset($response['usageMetadata'])) {
            $promptTokenCount = $response['usageMetadata']['promptTokenCount'];
            if ($stopReason === StopReason::SAFETY && !isset($response['usageMetadata']['candidatesTokenCount'])) {
                $outputTokenCount = 0;
            } else {
                $outputTokenCount = $response['usageMetadata']['candidatesTokenCount'];
            }

            $inputPrice = $promptTokenCount * ($model->getInputPricePerMillionTokens() / 1_000_000);
            $outputPrice = $outputTokenCount * ($model->getOutputPricePerMillionTokens() / 1_000_000);

            $request = $request->withCost(
                $promptTokenCount,
                $outputTokenCount,
                $inputPrice,
                $outputPrice
            );
        }

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
