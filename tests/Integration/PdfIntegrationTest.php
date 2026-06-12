<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Integration;

use Soukicz\Llm\Client\LLMAgentClient;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessagePdf;
use Soukicz\Llm\Message\LLMMessageText;

/**
 * Verifies PDF input end to end against the live APIs. The fixture contains the
 * distinctive word "PINEAPPLE" which the model must extract.
 *
 * @group integration
 */
class PdfIntegrationTest extends IntegrationTestBase {
    public static function clientProvider(): array {
        $instance = new self('clientProvider');
        $instance::loadEnvironmentStatic();

        $clients = [];
        foreach ($instance->getAllClients() as $clientData) {
            // PDF input is only supported by the three native providers; the
            // OpenAI-compatible endpoints (OpenRouter, Scaleway) vary by backing model
            if (!in_array($clientData['name'], ['OpenRouter', 'Scaleway Mistral Small'], true)) {
                $clients[$clientData['name']] = [$clientData['client'], $clientData['model'], $clientData['name']];
            }
        }

        return $clients;
    }

    /**
     * @dataProvider clientProvider
     */
    public function testPdfDocumentUnderstanding($client, $model, $name): void {
        $pdfData = base64_encode(file_get_contents(__DIR__ . '/fixtures/test-document.pdf'));

        $request = new LLMRequest(
            model: $model,
            conversation: new LLMConversation([
                LLMMessage::createFromUser(new LLMMessageContents([
                    new LLMMessageText('What is the secret word in this document? Reply with just the word.'),
                    new LLMMessagePdf('base64', $pdfData),
                ])),
            ]),
            maxTokens: 1000,
        );

        $response = (new LLMAgentClient())->run($client, $request);

        $this->trackCost(($response->getInputPriceUsd() ?? 0) + ($response->getOutputPriceUsd() ?? 0));

        $this->assertEquals(StopReason::FINISHED, $response->getStopReason(), "$name did not finish cleanly");
        $this->assertContainsIgnoreCase('PINEAPPLE', $response->getLastText(), "$name failed to read the PDF content");

        if ($this->verbose) {
            echo "\n[$name] PDF response: " . $response->getLastText();
        }
    }
}
