<?php

namespace Soukicz\Llm\Client\Anthropic;

use InvalidArgumentException;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicModel;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicPrices;
use Soukicz\Llm\Client\Anthropic\Tool\AnthropicNativeTool;
use Soukicz\Llm\Client\Anthropic\Tool\AnthropicNativeToolWithOptions;
use Soukicz\Llm\Client\ModelEncoder;
use Soukicz\Llm\Client\ModelInterface;
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\Config\ConversationCacheConfig;
use Soukicz\Llm\Config\ReasoningBudget;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\Config\ThinkingDisplay;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageArrayData;
use Soukicz\Llm\Message\LLMMessageContent;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageImage;
use Soukicz\Llm\Message\LLMMessageOpaque;
use Soukicz\Llm\Message\LLMMessagePdf;
use Soukicz\Llm\Message\LLMMessageReasoning;
use Soukicz\Llm\Message\LLMMessageRedactedReasoning;
use Soukicz\Llm\Message\LLMMessageStructuredData;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Message\LLMMessageToolResult;
use Soukicz\Llm\Message\LLMMessageToolUse;
use Soukicz\Llm\Usage\TokenUsage;

class AnthropicEncoder implements ModelEncoder {
    /**
     * Anthropic rejects requests with more than 4 cache_control breakpoints
     */
    private const MAX_CACHE_BREAKPOINTS = 4;

    /**
     * Normalize a JSON Schema for strict mode by adding "additionalProperties": false
     * to all object types and removing unsupported constraints (moving them to descriptions).
     * Anthropic strict mode does not support: minItems, maxItems, minimum, maximum,
     * minLength, maxLength, pattern.
     */
    private static function normalizeSchemaForStrictMode(array $schema): array {
        if (isset($schema['type']) && $schema['type'] === 'object' && !array_key_exists('additionalProperties', $schema)) {
            $schema['additionalProperties'] = false;
        }

        // Remove constraints not supported by Anthropic strict mode, preserve in description
        $unsupportedConstraints = [];
        foreach (['minItems', 'maxItems', 'minimum', 'maximum', 'minLength', 'maxLength', 'pattern'] as $constraint) {
            if (array_key_exists($constraint, $schema)) {
                $unsupportedConstraints[] = "$constraint: " . $schema[$constraint];
                unset($schema[$constraint]);
            }
        }
        if (!empty($unsupportedConstraints)) {
            $constraintNote = 'Constraints: ' . implode(', ', $unsupportedConstraints) . '.';
            $schema['description'] = isset($schema['description'])
                ? $schema['description'] . ' ' . $constraintNote
                : $constraintNote;
        }

        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $key => $property) {
                if (is_array($property)) {
                    $schema['properties'][$key] = self::normalizeSchemaForStrictMode($property);
                }
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::normalizeSchemaForStrictMode($schema['items']);
        }

        return $schema;
    }

    /**
     * Place moving cache breakpoints on the last two user messages so that in a multi-turn
     * tool loop each request reads the previous turns from cache instead of re-billing the
     * whole conversation at full input price. The breakpoint on the last message caches the
     * current state; the one on the previous user message anchors the cache lookup when a
     * single turn adds more content blocks than the provider's lookback window covers.
     *
     * Breakpoints are added at encode time only and never persisted into the conversation,
     * so they move with the conversation instead of accumulating. Explicit breakpoints set
     * by the caller (cached content flags) are always kept and counted against the limit.
     *
     * A configured TTL is applied to the caller's explicit breakpoints as well: Anthropic
     * requires longer-TTL cache entries to appear before shorter-TTL ones, so a 5-minute
     * explicit breakpoint followed by 1-hour moving breakpoints would be rejected.
     */
    private function addConversationCacheBreakpoints(array $encodedMessages, ConversationCacheConfig $config, int $breakpointsInUse = 0): array {
        $cacheControl = ['type' => 'ephemeral'];
        if ($config->getTtl() !== null) {
            $cacheControl['ttl'] = $config->getTtl()->value;
        }

        $breakpointCount = $breakpointsInUse;
        foreach ($encodedMessages as $messageIndex => $encodedMessage) {
            if (!is_array($encodedMessage['content'])) {
                // mid-conversation system message sent as a plain string
                continue;
            }
            foreach ($encodedMessage['content'] as $contentIndex => $encodedContent) {
                if (isset($encodedContent['cache_control'])) {
                    $breakpointCount++;
                    $encodedMessages[$messageIndex]['content'][$contentIndex]['cache_control'] = $cacheControl;
                }
            }
        }

        $markedMessages = 0;
        for ($messageIndex = count($encodedMessages) - 1; $messageIndex >= 0 && $markedMessages < 2; $messageIndex--) {
            if ($encodedMessages[$messageIndex]['role'] !== 'user') {
                continue;
            }
            for ($contentIndex = count($encodedMessages[$messageIndex]['content']) - 1; $contentIndex >= 0; $contentIndex--) {
                $type = $encodedMessages[$messageIndex]['content'][$contentIndex]['type'];
                if ($type === 'thinking' || $type === 'redacted_thinking') {
                    // thinking blocks cannot carry cache_control
                    continue;
                }
                if (!isset($encodedMessages[$messageIndex]['content'][$contentIndex]['cache_control'])) {
                    if ($breakpointCount >= self::MAX_CACHE_BREAKPOINTS) {
                        return $encodedMessages;
                    }
                    $encodedMessages[$messageIndex]['content'][$contentIndex]['cache_control'] = $cacheControl;
                    $breakpointCount++;
                }
                $markedMessages++;
                break;
            }
        }

        return $encodedMessages;
    }

    private function encodeSystemContent(LLMMessage $message): array {
        if (count($message->getContents()) !== 1) {
            throw new \InvalidArgumentException('System message supports only one content block');
        }
        $content = $message->getContents()[0];
        if (!$content instanceof LLMMessageText) {
            throw new \InvalidArgumentException('Unsupported system message type');
        }

        return $this->addCacheAttribute($content, ['type' => 'text', 'text' => $content->getText()]);
    }

    /**
     * Sends a later system message as `role: system` where the model and its position allow it (after a user
     * message, last or before an assistant message); otherwise appends its text to the previous user message,
     * or to the next one - appending keeps tool_result blocks first.
     */
    private function placeMidConversationSystemMessages(array $encodedMessages, ModelInterface $model): array {
        $native = $model instanceof AnthropicModel && $model->supportsMidConversationSystemMessages();
        $result = [];
        $pending = [];
        $count = count($encodedMessages);
        for ($i = 0; $i < $count; $i++) {
            $message = $encodedMessages[$i];
            if ($message['role'] !== 'system') {
                if ($pending !== [] && $message['role'] === 'user') {
                    $message['content'] = [...$message['content'], ...$pending];
                    $pending = [];
                }
                $result[] = $message;
                continue;
            }

            $previousIndex = array_key_last($result);
            $previousRole = $previousIndex === null ? null : $result[$previousIndex]['role'];
            $nextRole = $encodedMessages[$i + 1]['role'] ?? null;
            if ($native && $previousRole === 'user' && ($nextRole === null || $nextRole === 'assistant')) {
                $content = $message['content'];
                $result[] = [
                    'role' => 'system',
                    'content' => count($content) === 1 && !isset($content[0]['cache_control']) ? $content[0]['text'] : $content,
                ];
                continue;
            }
            if ($previousRole === 'user') {
                $result[$previousIndex]['content'] = [...$result[$previousIndex]['content'], ...$message['content']];
                continue;
            }
            $pending = [...$pending, ...$message['content']];
        }
        if ($pending !== []) {
            throw new \InvalidArgumentException('A system message after the conversation start needs a user message next to it');
        }

        return $result;
    }

    private function addCacheAttribute(LLMMessageContent $content, array $data): array {
        if ($content->isCached()) {
            $data['cache_control'] = ['type' => 'ephemeral'];
        }

        return $data;
    }

    public function encodeMessageContent(LLMMessageContent $messageContent): array {
        if ($messageContent instanceof LLMMessageText) {
            return $this->addCacheAttribute($messageContent, [
                'type' => 'text',
                'text' => $messageContent->getText(),
            ]);
        }
        if ($messageContent instanceof LLMMessageArrayData) {
            return $this->addCacheAttribute($messageContent, [
                'type' => 'text',
                'text' => json_encode($messageContent->getData(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
        if ($messageContent instanceof LLMMessageReasoning) {
            return $this->addCacheAttribute($messageContent, [
                'type' => 'thinking',
                'thinking' => $messageContent->getText(),
                'signature' => $messageContent->getSignature(),
            ]);
        }
        if ($messageContent instanceof LLMMessageImage) {
            return $this->addCacheAttribute($messageContent, [
                'type' => 'image',
                'source' => [
                    'type' => $messageContent->getEncoding(),
                    'media_type' => $messageContent->getMediaType(),
                    'data' => $messageContent->getData(),
                ],
            ]);
        }
        if ($messageContent instanceof LLMMessagePdf) {
            return $this->addCacheAttribute($messageContent, [
                'type' => 'document',
                'source' => [
                    'type' => $messageContent->getEncoding(),
                    'media_type' => 'application/pdf',
                    'data' => $messageContent->getData(),
                ],
            ]);
        }
        if ($messageContent instanceof LLMMessageStructuredData) {
            return $this->addCacheAttribute($messageContent, [
                'type' => 'text',
                'text' => $messageContent->getRawJson(),
            ]);
        }

        if ($messageContent instanceof LLMMessageRedactedReasoning) {
            return [
                'type' => 'redacted_thinking',
                'data' => $messageContent->getData(),
            ];
        }
        if ($messageContent instanceof LLMMessageOpaque) {
            if ($messageContent->getProvider() !== 'anthropic') {
                throw new InvalidArgumentException('Opaque content from provider "' . $messageContent->getProvider() . '" cannot be sent to Anthropic');
            }
            $block = $messageContent->getBlock();
            // json_decode(..., true) turns an empty JSON object into []; the API expects an object back
            if (array_key_exists('input', $block) && $block['input'] === []) {
                $block['input'] = new \stdClass();
            }

            return $block;
        }
        throw new InvalidArgumentException('Unsupported message content type: ' . get_class($messageContent));
    }

    public function encodeRequest(LLMRequest $request): array {
        $systemBlocks = null;
        $encodedMessages = [];
        $messages = $request->getConversation()->getMessages();
        $systemMessageCount = count(array_filter($messages, static fn(LLMMessage $message): bool => $message->isSystem()));
        $seenNonSystem = false;
        foreach ($messages as $message) {
            if ($message->isSystem()) {
                $systemBlock = $this->encodeSystemContent($message);
                // the opening system message (or the only one anywhere, as before) is the top-level system prompt
                if ($systemBlocks === null && (!$seenNonSystem || $systemMessageCount === 1)) {
                    $systemBlocks = [$systemBlock];
                    continue;
                }
                if (!$seenNonSystem) {
                    throw new \InvalidArgumentException('Multiple system messages');
                }
                $encodedMessages[] = ['role' => 'system', 'content' => [$systemBlock]];
                continue;
            }
            $seenNonSystem = true;

            if ($message->isUser()) {
                $role = 'user';
            } elseif ($message->isAssistant()) {
                $role = 'assistant';
            } else {
                throw new \InvalidArgumentException('Unsupported message role');
            }

            $contents = [];
            foreach ($message->getContents() as $messageContent) {
                if ($messageContent instanceof LLMMessageToolUse) {
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
                    $toolResultContent = $messageContent->getContent();

                    // For Anthropic, we need to serialize the tool result content
                    $contentParts = [];
                    foreach ($toolResultContent->getMessages() as $toolMessage) {
                        $contentParts[] = $this->encodeMessageContent($toolMessage);
                    }

                    $toolContent = [
                        'type' => 'tool_result',
                        'tool_use_id' => $messageContent->getId(),
                        'content' => $contentParts,
                    ];
                    if ($toolResultContent->isError()) {
                        $toolContent['is_error'] = true;
                    }
                    $contents[] = $this->addCacheAttribute($messageContent, $toolContent);
                } else {
                    $contents[] = $this->encodeMessageContent($messageContent);
                }
            }
            $encodedMessages[] = [
                'role' => $role,
                'content' => $contents,
            ];
        }

        $encodedMessages = $this->placeMidConversationSystemMessages($encodedMessages, $request->getModel());

        $conversationCacheConfig = $request->getConversationCacheConfig();
        $systemBreakpoints = $systemBlocks !== null && isset($systemBlocks[0]['cache_control']) ? 1 : 0;
        if ($conversationCacheConfig !== null) {
            if ($systemBreakpoints === 1 && $conversationCacheConfig->getTtl() !== null) {
                // longer TTLs must come first; the system prompt precedes every message
                $systemBlocks[0]['cache_control']['ttl'] = $conversationCacheConfig->getTtl()->value;
            }
            $encodedMessages = $this->addConversationCacheBreakpoints($encodedMessages, $conversationCacheConfig, $systemBreakpoints);
        }

        $options = [
            'max_tokens' => $request->getMaxTokens(),
            'messages' => $encodedMessages,
            'model' => $request->getModel()->getCode(),
        ];
        $model = $request->getModel();
        if ($request->getTemperature() !== null && (!$model instanceof AnthropicModel || $model->supportsSamplingParameters())) {
            $options['temperature'] = $request->getTemperature();
        }

        $outputConfig = [];

        $reasoningConfig = $request->getReasoningConfig();
        if ($reasoningConfig) {
            if ($reasoningConfig instanceof ReasoningBudget) {
                $options['thinking'] = [
                    'type' => 'enabled',
                    'budget_tokens' => $reasoningConfig->getMaxTokens(),
                ];
            } elseif ($reasoningConfig instanceof ReasoningEffort) {
                if ($reasoningConfig === ReasoningEffort::NONE) {
                    $model = $request->getModel();
                    if ($model instanceof AnthropicModel && $model->hasAdaptiveThinkingByDefault()) {
                        // thinking runs anyway on these models; without an explicit effort they use their
                        // default (medium on Opus 5.5), so the lowest effort is the closest to "none"
                        $outputConfig['effort'] = 'low';
                    }
                } else {
                    $options['thinking'] = [
                        'type' => 'adaptive',
                    ];
                    $outputConfig['effort'] = self::anthropicEffort($reasoningConfig);
                }
            } else {
                throw new \InvalidArgumentException('Unsupported reasoning config type');
            }
        }

        $thinkingDisplay = self::effectiveThinkingDisplay($request);
        if ($thinkingDisplay !== null) {
            if (isset($options['thinking'])) {
                $options['thinking']['display'] = $thinkingDisplay->value;
            } elseif ($model instanceof AnthropicModel && $model->hasAdaptiveThinkingByDefault()) {
                $options['thinking'] = ['type' => 'adaptive', 'display' => $thinkingDisplay->value];
            }
        }

        $structuredOutputConfig = $request->getStructuredOutputConfig();
        if ($structuredOutputConfig !== null) {
            $outputConfig['format'] = [
                'type' => 'json_schema',
                'schema' => self::normalizeSchemaForStrictMode($structuredOutputConfig->getSchema()),
            ];
        }

        if (!empty($outputConfig)) {
            $options['output_config'] = $outputConfig;
        }

        $contextEdits = $request->getContextManagement()?->toAnthropicEdits() ?? [];
        if ($contextEdits !== []) {
            $options['context_management'] = ['edits' => $contextEdits];
        }

        if ($systemBlocks !== null) {
            $options['system'] = isset($systemBlocks[0]['cache_control']) ? $systemBlocks : $systemBlocks[0]['text'];
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
                if ($tool instanceof AnthropicNativeTool) {
                    $definition = [
                        'type' => $tool->getAnthropicType($request->getModel()),
                        'name' => $tool->getAnthropicName($request->getModel()),
                    ];
                    if ($tool instanceof AnthropicNativeToolWithOptions) {
                        $definition += $tool->getAnthropicOptions($request->getModel());
                    }
                    $options['tools'][] = $definition;
                    continue;
                }
                $schema = self::normalizeSchemaForStrictMode($tool->getInputSchema());
                if (empty($schema['properties'])) {
                    $schema['properties'] = new \stdClass();
                }
                $options['tools'][] = [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'strict' => true,
                    'input_schema' => $schema,
                ];
            }
        }

        return $options;
    }

    /**
     * Beta features (anthropic-beta header values) this request needs on top of the client's own
     *
     * @return string[]
     */
    public function getRequestBetas(LLMRequest $request): array {
        $betas = [];
        if (self::effectiveThinkingDisplay($request) === ThinkingDisplay::UPDATES) {
            $betas[] = 'thinking-display-updates-2026-08-18';
        }
        if (($request->getContextManagement()?->toAnthropicEdits() ?? []) !== []) {
            $betas[] = 'context-management-2025-06-27';
        }

        return $betas;
    }

    /**
     * The requested display, minus `updates` on a model that does not accept it (the API rejects it with a 400)
     */
    private static function effectiveThinkingDisplay(LLMRequest $request): ?ThinkingDisplay {
        $display = $request->getThinkingDisplay();
        $model = $request->getModel();
        if ($display === ThinkingDisplay::UPDATES && $model instanceof AnthropicModel && !$model->supportsThinkingUpdates()) {
            return null;
        }

        return $display;
    }

    private static function anthropicEffort(ReasoningEffort $effort): string {
        return match ($effort) {
            ReasoningEffort::NONE, ReasoningEffort::MINIMAL, ReasoningEffort::LOW => 'low',
            ReasoningEffort::MEDIUM => 'medium',
            ReasoningEffort::HIGH => 'high',
            ReasoningEffort::XHIGH => 'xhigh',
            ReasoningEffort::EXTRA_HIGH, ReasoningEffort::MAX => 'max',
        };
    }

    public function decodeResponse(LLMRequest $request, ModelResponse $modelResponse): LLMRequest|LLMResponse {
        $response = $modelResponse->getData();
        $responseTimeMs = $modelResponse->getResponseTimeMs();

        $responseContents = [];
        foreach ($response['content'] as $content) {
            if ($content['type'] === 'text') {
                if ($request->getStructuredOutputConfig() !== null) {
                    $parsed = json_decode($content['text'], true, 512, JSON_THROW_ON_ERROR);
                    $responseContents[] = new LLMMessageStructuredData($parsed, $content['text']);
                } else {
                    $responseContents[] = new LLMMessageText($content['text']);
                }
            } elseif ($content['type'] === 'thinking') {
                $responseContents[] = new LLMMessageReasoning($content['thinking'], $content['signature']);
            } elseif ($content['type'] === 'tool_use') {
                $responseContents[] = new LLMMessageToolUse($content['id'], $content['name'], $content['input']);
            } elseif ($content['type'] === 'redacted_thinking') {
                $responseContents[] = new LLMMessageRedactedReasoning($content['data']);
            } else {
                $responseContents[] = new LLMMessageOpaque('anthropic', $content);
            }
        }
        $request = $request->withMessage(LLMMessage::createFromAssistant(new LLMMessageContents($responseContents)));

        $usage = $response['usage'] ?? [];
        $cacheWriteTotal = $usage['cache_creation_input_tokens'] ?? 0;
        $cacheWrite1h = $usage['cache_creation']['ephemeral_1h_input_tokens'] ?? 0;
        $cacheWrite5m = $usage['cache_creation']['ephemeral_5m_input_tokens'] ?? ($cacheWriteTotal - $cacheWrite1h);
        $stepUsage = new TokenUsage(
            input: $usage['input_tokens'] ?? 0,
            output: $usage['output_tokens'] ?? 0,
            cacheWrite5m: $cacheWrite5m,
            cacheWrite1h: $cacheWrite1h,
            cacheRead: $usage['cache_read_input_tokens'] ?? 0,
        );

        $model = $request->getModel();
        $prices = $model instanceof AnthropicModel
            ? $model->getPrices($stepUsage->promptTokens())
            : new AnthropicPrices(
                $model->getInputPricePerMillionTokens(),
                $model->getOutputPricePerMillionTokens(),
                $model->getCachedInputPricePerMillionTokens(),
                $model->getCachedInputPricePerMillionTokens(),
                $model->getCachedOutputPricePerMillionTokens(),
            );

        // cache writes and reads are input-side costs
        $inputPrice = (
            $stepUsage->input * $prices->input
            + $stepUsage->cacheWrite5m * $prices->cacheWrite5m
            + $stepUsage->cacheWrite1h * $prices->cacheWrite1h
            + $stepUsage->cacheRead * $prices->cacheRead
        ) / 1_000_000;
        $outputPrice = $stepUsage->output * $prices->output / 1_000_000;

        $request = $request
            ->withCost($stepUsage->input, $stepUsage->output, $inputPrice, $outputPrice)
            ->withUsage($stepUsage)
            ->withTime($responseTimeMs);

        $stopReason = match ($response['stop_reason']) {
            'end_turn', 'stop_sequence' => StopReason::FINISHED,
            'max_tokens', 'model_context_window_exceeded' => StopReason::LENGTH,
            'tool_use' => StopReason::TOOL_USE,
            'refusal' => StopReason::REFUSAL,
            'pause_turn' => StopReason::PAUSE_TURN,
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
            $request->getPreviousUsage(),
            $stepUsage,
            $response['stop_details'] ?? null,
        );
    }
}
