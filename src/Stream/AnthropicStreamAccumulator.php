<?php

declare(strict_types=1);

namespace Soukicz\Llm\Stream;

use Psr\Http\Message\StreamInterface;

class AnthropicStreamAccumulator {
    /**
     * Parse an Anthropic SSE stream, call the listener for each delta,
     * and return the fully reconstructed response array matching the
     * non-streaming format expected by AnthropicEncoder::decodeResponse().
     *
     * @return array Reconstructed response data array
     */
    public static function consume(StreamInterface $stream, StreamListenerInterface $listener): array {
        /** @var array<int, array<string, mixed>> $content */
        $content = [];
        $usage = [];
        $stopReason = null;

        // Per-block accumulators
        $blockTexts = [];
        $blockThinking = [];
        $blockSignatures = [];
        $blockToolJsons = [];
        $blockMeta = [];

        foreach (SseParser::parse($stream) as $sse) {
            $data = json_decode($sse['data'], true, 512, JSON_THROW_ON_ERROR);

            switch ($sse['event']) {
                case 'message_start':
                    $usage = $data['message']['usage'] ?? [];
                    $listener->onStreamEvent(new StreamEvent(
                        type: StreamEventType::MESSAGE_START,
                        blockIndex: -1,
                    ));
                    break;

                case 'content_block_start':
                    $index = $data['index'];
                    $block = $data['content_block'];

                    $blockMeta[$index] = $block;
                    $blockTexts[$index] = '';
                    $blockThinking[$index] = '';
                    $blockSignatures[$index] = '';
                    $blockToolJsons[$index] = '';

                    if ($block['type'] === 'tool_use') {
                        $listener->onStreamEvent(new StreamEvent(
                            type: StreamEventType::TOOL_USE_START,
                            blockIndex: $index,
                            toolName: $block['name'],
                            toolId: $block['id'],
                        ));
                    }
                    break;

                case 'content_block_delta':
                    $index = $data['index'];
                    if (!isset($blockMeta[$index])) {
                        break;
                    }
                    $delta = $data['delta'];

                    switch ($delta['type']) {
                        case 'text_delta':
                            $blockTexts[$index] .= $delta['text'];
                            $listener->onStreamEvent(new StreamEvent(
                                type: StreamEventType::TEXT_DELTA,
                                blockIndex: $index,
                                delta: $delta['text'],
                            ));
                            break;

                        case 'thinking_delta':
                            $blockThinking[$index] .= $delta['thinking'];
                            $listener->onStreamEvent(new StreamEvent(
                                type: StreamEventType::THINKING_DELTA,
                                blockIndex: $index,
                                delta: $delta['thinking'],
                            ));
                            break;

                        case 'signature_delta':
                            $blockSignatures[$index] .= $delta['signature'];
                            break;

                        case 'input_json_delta':
                            $blockToolJsons[$index] .= $delta['partial_json'];
                            $listener->onStreamEvent(new StreamEvent(
                                type: StreamEventType::TOOL_INPUT_DELTA,
                                blockIndex: $index,
                                delta: $delta['partial_json'],
                                toolName: $blockMeta[$index]['name'] ?? null,
                                toolId: $blockMeta[$index]['id'] ?? null,
                            ));
                            break;
                    }
                    break;

                case 'content_block_stop':
                    $index = $data['index'];
                    if (!isset($blockMeta[$index])) {
                        break;
                    }
                    $meta = $blockMeta[$index];

                    switch ($meta['type']) {
                        case 'text':
                            $content[$index] = [
                                'type' => 'text',
                                'text' => $blockTexts[$index],
                            ];
                            break;

                        case 'thinking':
                            $content[$index] = [
                                'type' => 'thinking',
                                'thinking' => $blockThinking[$index],
                                'signature' => $blockSignatures[$index],
                            ];
                            break;

                        case 'tool_use':
                            $inputJson = $blockToolJsons[$index];
                            $content[$index] = [
                                'type' => 'tool_use',
                                'id' => $meta['id'],
                                'name' => $meta['name'],
                                'input' => $inputJson !== '' ? json_decode($inputJson, true, 512, JSON_THROW_ON_ERROR) : [],
                            ];
                            break;
                    }

                    $listener->onStreamEvent(new StreamEvent(
                        type: StreamEventType::CONTENT_BLOCK_STOP,
                        blockIndex: $index,
                    ));
                    break;

                case 'message_delta':
                    if (isset($data['delta']['stop_reason'])) {
                        $stopReason = $data['delta']['stop_reason'];
                    }
                    if (isset($data['usage'])) {
                        $usage = array_merge($usage, $data['usage']);
                    }
                    break;

                case 'message_stop':
                    $listener->onStreamEvent(new StreamEvent(
                        type: StreamEventType::MESSAGE_COMPLETE,
                        blockIndex: -1,
                    ));
                    break;

                case 'error':
                    $errorType = $data['error']['type'] ?? 'unknown_error';
                    $errorMessage = $data['error']['message'] ?? 'Unknown streaming error';
                    throw new \RuntimeException("Anthropic stream error ({$errorType}): {$errorMessage}");
            }
        }

        // Sort by index to ensure correct order
        ksort($content);

        return [
            'content' => array_values($content),
            'stop_reason' => $stopReason,
            'usage' => $usage,
        ];
    }
}
