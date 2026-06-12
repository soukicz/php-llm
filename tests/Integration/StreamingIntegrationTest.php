<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Integration;

use Soukicz\Llm\Client\LLMAgentClient;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Stream\CallableStreamListener;
use Soukicz\Llm\Stream\StreamEvent;
use Soukicz\Llm\Stream\StreamEventType;

/**
 * Verifies that live provider SSE streams are parsed correctly: text deltas must
 * accumulate to exactly the final response text and the stream must terminate with
 * MESSAGE_COMPLETE. This is the only place where real (current) provider stream
 * formats are exercised - the unit tests only cover recorded formats.
 *
 * @group integration
 */
class StreamingIntegrationTest extends IntegrationTestBase {
    public static function clientProvider(): array {
        $instance = new self('clientProvider');
        $instance::loadEnvironmentStatic();

        $clients = [];
        foreach ($instance->getAllClients() as $clientData) {
            $clients[$clientData['name']] = [$clientData['client'], $clientData['model'], $clientData['name']];
        }

        return $clients;
    }

    /**
     * @dataProvider clientProvider
     */
    public function testTextStreamingMatchesFinalText($client, $model, $name): void {
        $streamedText = '';
        $eventTypes = [];
        $listener = new CallableStreamListener(function (StreamEvent $event) use (&$streamedText, &$eventTypes): void {
            $eventTypes[] = $event->type;
            if ($event->type === StreamEventType::TEXT_DELTA) {
                $streamedText .= $event->delta;
            }
        });

        $request = new LLMRequest(
            model: $model,
            conversation: new LLMConversation([
                LLMMessage::createFromUserString('Reply with one short sentence about the sun.'),
            ]),
            maxTokens: 1000,
            streamListener: $listener,
        );

        $response = (new LLMAgentClient())->run($client, $request);

        $this->trackCost(($response->getInputPriceUsd() ?? 0) + ($response->getOutputPriceUsd() ?? 0));

        $this->assertEquals(StopReason::FINISHED, $response->getStopReason(), "$name did not finish cleanly");
        $this->assertNotSame('', $streamedText, "$name emitted no TEXT_DELTA events");
        $this->assertSame(
            $response->getLastText(),
            $streamedText,
            "$name: accumulated stream deltas differ from the final response text"
        );
        $this->assertContains(StreamEventType::MESSAGE_COMPLETE, $eventTypes, "$name never emitted MESSAGE_COMPLETE");

        if ($this->verbose) {
            echo "\n[$name] Streamed: $streamedText";
        }
    }
}
