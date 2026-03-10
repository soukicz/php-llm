<?php

declare(strict_types=1);

namespace Soukicz\Llm\Stream;

use Psr\Http\Message\StreamInterface;

class SseParser {
    /**
     * Parse an SSE stream into individual events.
     *
     * @return \Generator<int, array{event: string, data: string}>
     */
    public static function parse(StreamInterface $stream): \Generator {
        $event = '';
        $data = '';
        $buffer = '';

        while (!$stream->eof()) {
            $buffer .= $stream->read(8192);
            $lines = explode("\n", $buffer);
            // Keep the last (potentially incomplete) line in the buffer
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = rtrim($line, "\r");

                if ($line === '') {
                    // Empty line = event delimiter
                    if ($data !== '') {
                        yield ['event' => $event, 'data' => $data];
                        $event = '';
                        $data = '';
                    }
                    continue;
                }

                // Ignore comment lines
                if (str_starts_with($line, ':')) {
                    continue;
                }

                if (str_starts_with($line, 'event: ')) {
                    $event = substr($line, 7);
                } elseif (str_starts_with($line, 'data: ')) {
                    $data .= ($data !== '' ? "\n" : '') . substr($line, 6);
                }
            }
        }

        // Process any remaining content in the buffer
        if ($buffer !== '') {
            $remainingLines = explode("\n", $buffer);
            foreach ($remainingLines as $line) {
                $line = rtrim($line, "\r");
                if ($line === '') {
                    continue;
                }
                if (str_starts_with($line, ':')) {
                    continue;
                }
                if (str_starts_with($line, 'event: ')) {
                    $event = substr($line, 7);
                } elseif (str_starts_with($line, 'data: ')) {
                    $data .= ($data !== '' ? "\n" : '') . substr($line, 6);
                }
            }
        }

        // Flush remaining data
        if ($data !== '') {
            yield ['event' => $event, 'data' => $data];
        }
    }
}
