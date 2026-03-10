<?php

declare(strict_types=1);

namespace Soukicz\Llm\Stream;

use Psr\Http\Message\StreamInterface;

class GeminiStreamAccumulator {
    /**
     * Replay a cached response as stream events.
     * Fires the same event types as consume() but with complete content in single deltas.
     */
    public static function replay(array $responseData, StreamListenerInterface $listener): void {
        $listener->onStreamEvent(new StreamEvent(
            type: StreamEventType::MESSAGE_START,
            blockIndex: -1,
        ));

        $blockIndex = 0;
        foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['text'])) {
                $listener->onStreamEvent(new StreamEvent(
                    type: StreamEventType::TEXT_DELTA,
                    blockIndex: $blockIndex,
                    delta: $part['text'],
                ));
                $blockIndex++;
            } elseif (isset($part['thought'])) {
                $listener->onStreamEvent(new StreamEvent(
                    type: StreamEventType::THINKING_DELTA,
                    blockIndex: $blockIndex,
                    delta: $part['thought'],
                ));
                $blockIndex++;
            } elseif (isset($part['functionCall'])) {
                $listener->onStreamEvent(new StreamEvent(
                    type: StreamEventType::TOOL_USE_START,
                    blockIndex: $blockIndex,
                    toolName: $part['functionCall']['name'],
                ));
                $listener->onStreamEvent(new StreamEvent(
                    type: StreamEventType::TOOL_INPUT_DELTA,
                    blockIndex: $blockIndex,
                    delta: json_encode($part['functionCall']['args'] ?? [], JSON_THROW_ON_ERROR),
                    toolName: $part['functionCall']['name'],
                ));
                $listener->onStreamEvent(new StreamEvent(
                    type: StreamEventType::CONTENT_BLOCK_STOP,
                    blockIndex: $blockIndex,
                ));
                $blockIndex++;
            } elseif (isset($part['inlineData'])) {
                $blockIndex++;
            }
        }

        $listener->onStreamEvent(new StreamEvent(
            type: StreamEventType::MESSAGE_COMPLETE,
            blockIndex: -1,
        ));
    }

    /**
     * Parse a Gemini SSE stream, call the listener for each delta,
     * and return the fully reconstructed response array matching the
     * non-streaming format expected by GeminiEncoder::decodeResponse().
     *
     * Gemini streaming sends complete response objects per chunk.
     * Text parts are incremental (new text per chunk, not cumulative).
     * Tool calls appear as complete functionCall objects.
     *
     * @return array Reconstructed response data array
     */
    public static function consume(StreamInterface $stream, StreamListenerInterface $listener): array {
        $allParts = [];
        $finishReason = null;
        $usageMetadata = [];
        $blockIndex = 0;

        $listener->onStreamEvent(new StreamEvent(
            type: StreamEventType::MESSAGE_START,
            blockIndex: -1,
        ));

        foreach (SseParser::parse($stream) as $sse) {
            $data = json_decode($sse['data'], true, 512, JSON_THROW_ON_ERROR);

            // Extract usage metadata (present in final chunk)
            if (isset($data['usageMetadata'])) {
                $usageMetadata = $data['usageMetadata'];
            }

            if (!isset($data['candidates'][0])) {
                continue;
            }

            $candidate = $data['candidates'][0];

            // Extract finish reason
            if (isset($candidate['finishReason'])) {
                $finishReason = $candidate['finishReason'];
            }

            // Process parts
            if (isset($candidate['content']['parts'])) {
                foreach ($candidate['content']['parts'] as $part) {
                    if (isset($part['text'])) {
                        $allParts[] = $part;
                        $listener->onStreamEvent(new StreamEvent(
                            type: StreamEventType::TEXT_DELTA,
                            blockIndex: $blockIndex,
                            delta: $part['text'],
                        ));
                        $blockIndex++;
                    } elseif (isset($part['thought'])) {
                        $allParts[] = $part;
                        $listener->onStreamEvent(new StreamEvent(
                            type: StreamEventType::THINKING_DELTA,
                            blockIndex: $blockIndex,
                            delta: $part['thought'],
                        ));
                        $blockIndex++;
                    } elseif (isset($part['functionCall'])) {
                        $allParts[] = $part;
                        $listener->onStreamEvent(new StreamEvent(
                            type: StreamEventType::TOOL_USE_START,
                            blockIndex: $blockIndex,
                            toolName: $part['functionCall']['name'],
                        ));
                        // Emit the full input as a single delta since Gemini sends complete tool calls
                        $inputJson = json_encode($part['functionCall']['args'] ?? [], JSON_THROW_ON_ERROR);
                        $listener->onStreamEvent(new StreamEvent(
                            type: StreamEventType::TOOL_INPUT_DELTA,
                            blockIndex: $blockIndex,
                            delta: $inputJson,
                            toolName: $part['functionCall']['name'],
                        ));
                        $listener->onStreamEvent(new StreamEvent(
                            type: StreamEventType::CONTENT_BLOCK_STOP,
                            blockIndex: $blockIndex,
                        ));
                        $blockIndex++;
                    } elseif (isset($part['inlineData'])) {
                        $allParts[] = $part;
                        $blockIndex++;
                    }
                }
            }
        }

        $listener->onStreamEvent(new StreamEvent(
            type: StreamEventType::MESSAGE_COMPLETE,
            blockIndex: -1,
        ));

        // Reconstruct the response in non-streaming format
        $result = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => $allParts,
                    ],
                ],
            ],
        ];

        if ($finishReason !== null) {
            $result['candidates'][0]['finishReason'] = $finishReason;
        }

        if (!empty($usageMetadata)) {
            $result['usageMetadata'] = $usageMetadata;
        }

        return $result;
    }
}
