<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Stream;

use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Stream\SseParser;

class SseParserTest extends TestCase {
    public function testParsesBasicSseEvents(): void {
        $sse = "event: message_start\ndata: {\"type\":\"message_start\"}\n\nevent: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0}\n\n";
        $stream = Utils::streamFor($sse);

        $events = iterator_to_array(SseParser::parse($stream));

        $this->assertCount(2, $events);
        $this->assertEquals('message_start', $events[0]['event']);
        $this->assertEquals('{"type":"message_start"}', $events[0]['data']);
        $this->assertEquals('content_block_start', $events[1]['event']);
        $this->assertEquals('{"type":"content_block_start","index":0}', $events[1]['data']);
    }

    public function testIgnoresCommentLines(): void {
        $sse = ": this is a comment\nevent: ping\ndata: {}\n\n";
        $stream = Utils::streamFor($sse);

        $events = iterator_to_array(SseParser::parse($stream));

        $this->assertCount(1, $events);
        $this->assertEquals('ping', $events[0]['event']);
    }

    public function testHandlesCarriageReturn(): void {
        $sse = "event: test\r\ndata: {\"ok\":true}\r\n\r\n";
        $stream = Utils::streamFor($sse);

        $events = iterator_to_array(SseParser::parse($stream));

        $this->assertCount(1, $events);
        $this->assertEquals('test', $events[0]['event']);
        $this->assertEquals('{"ok":true}', $events[0]['data']);
    }

    public function testFlushesRemainingData(): void {
        // SSE without trailing blank line
        $sse = "event: test\ndata: {\"final\":true}";
        $stream = Utils::streamFor($sse);

        $events = iterator_to_array(SseParser::parse($stream));

        $this->assertCount(1, $events);
        $this->assertEquals('{"final":true}', $events[0]['data']);
    }

    public function testEmptyStreamYieldsNothing(): void {
        $stream = Utils::streamFor('');
        $events = iterator_to_array(SseParser::parse($stream));
        $this->assertCount(0, $events);
    }

    public function testFlushesMultiLineRemainingBuffer(): void {
        // Buffer contains both event and data lines without trailing newline
        $sse = "event: custom\ndata: {\"multi\":true}";
        $stream = Utils::streamFor($sse);

        $events = iterator_to_array(SseParser::parse($stream));

        $this->assertCount(1, $events);
        $this->assertEquals('custom', $events[0]['event']);
        $this->assertEquals('{"multi":true}', $events[0]['data']);
    }

    public function testEventWithoutDataIsSkipped(): void {
        $sse = "event: ping\n\nevent: real\ndata: {}\n\n";
        $stream = Utils::streamFor($sse);

        $events = iterator_to_array(SseParser::parse($stream));

        $this->assertCount(1, $events);
        $this->assertEquals('real', $events[0]['event']);
    }
}
