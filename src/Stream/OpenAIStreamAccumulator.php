<?php

declare(strict_types=1);

namespace Soukicz\Llm\Stream;

use Psr\Http\Message\StreamInterface;

class OpenAIStreamAccumulator {
    /**
     * Parse an OpenAI SSE stream, call the listener for each delta,
     * and return the fully reconstructed response array matching the
     * non-streaming format expected by OpenAIEncoder::decodeResponse().
     *
     * @return array Reconstructed response data array
     */
    public static function consume(StreamInterface $stream, StreamListenerInterface $listener): array {
        $content = '';
        $refusal = '';
        $finishReason = null;
        $usage = [];

        // Tool call accumulators indexed by tool call index
        /** @var array<int, array{id: string, name: string, arguments: string}> $toolCalls */
        $toolCalls = [];

        $listener->onStreamEvent(new StreamEvent(
            type: StreamEventType::MESSAGE_START,
            blockIndex: -1,
        ));

        foreach (SseParser::parse($stream) as $sse) {
            $rawData = trim($sse['data']);

            if ($rawData === '[DONE]') {
                break;
            }

            $data = json_decode($rawData, true, 512, JSON_THROW_ON_ERROR);

            // Final usage-only chunk has empty choices
            if (isset($data['usage']) && !empty($data['usage'])) {
                $usage = $data['usage'];
            }

            if (empty($data['choices'])) {
                continue;
            }

            $choice = $data['choices'][0];
            $delta = $choice['delta'] ?? [];

            // Text content delta
            if (isset($delta['content']) && $delta['content'] !== '') {
                $content .= $delta['content'];
                $listener->onStreamEvent(new StreamEvent(
                    type: StreamEventType::TEXT_DELTA,
                    blockIndex: 0,
                    delta: $delta['content'],
                ));
            }

            // Refusal delta
            if (isset($delta['refusal']) && $delta['refusal'] !== '') {
                $refusal .= $delta['refusal'];
                $listener->onStreamEvent(new StreamEvent(
                    type: StreamEventType::TEXT_DELTA,
                    blockIndex: 0,
                    delta: $delta['refusal'],
                ));
            }

            // Tool call deltas
            if (isset($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $toolCallDelta) {
                    $index = $toolCallDelta['index'];

                    // First chunk for this tool call - has id and name
                    if (isset($toolCallDelta['id'])) {
                        $toolCalls[$index] = [
                            'id' => $toolCallDelta['id'],
                            'name' => $toolCallDelta['function']['name'] ?? '',
                            'arguments' => $toolCallDelta['function']['arguments'] ?? '',
                        ];
                        $listener->onStreamEvent(new StreamEvent(
                            type: StreamEventType::TOOL_USE_START,
                            blockIndex: $index + 1, // offset by 1 since text is block 0
                            toolName: $toolCalls[$index]['name'],
                            toolId: $toolCalls[$index]['id'],
                        ));
                        if (!empty($toolCallDelta['function']['arguments'])) {
                            $listener->onStreamEvent(new StreamEvent(
                                type: StreamEventType::TOOL_INPUT_DELTA,
                                blockIndex: $index + 1,
                                delta: $toolCallDelta['function']['arguments'],
                                toolName: $toolCalls[$index]['name'],
                                toolId: $toolCalls[$index]['id'],
                            ));
                        }
                    } else {
                        // Subsequent chunks - accumulate arguments
                        if (!isset($toolCalls[$index])) {
                            continue;
                        }
                        $args = $toolCallDelta['function']['arguments'] ?? '';
                        if ($args !== '') {
                            $toolCalls[$index]['arguments'] .= $args;
                            $listener->onStreamEvent(new StreamEvent(
                                type: StreamEventType::TOOL_INPUT_DELTA,
                                blockIndex: $index + 1,
                                delta: $args,
                                toolName: $toolCalls[$index]['name'],
                                toolId: $toolCalls[$index]['id'],
                            ));
                        }
                    }
                }
            }

            // Finish reason
            if (isset($choice['finish_reason'])) {
                $finishReason = $choice['finish_reason'];
            }
        }

        $listener->onStreamEvent(new StreamEvent(
            type: StreamEventType::MESSAGE_COMPLETE,
            blockIndex: -1,
        ));

        // Reconstruct the response in non-streaming format
        $message = [];
        if ($content !== '') {
            $message['content'] = $content;
        }
        if ($refusal !== '') {
            $message['refusal'] = $refusal;
        }
        if (!empty($toolCalls)) {
            $message['tool_calls'] = [];
            ksort($toolCalls);
            foreach ($toolCalls as $tc) {
                $message['tool_calls'][] = [
                    'id' => $tc['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $tc['name'],
                        'arguments' => $tc['arguments'],
                    ],
                ];
            }
        }

        return [
            'choices' => [
                [
                    'message' => $message,
                    'finish_reason' => $finishReason,
                ],
            ],
            'usage' => $usage,
        ];
    }
}
