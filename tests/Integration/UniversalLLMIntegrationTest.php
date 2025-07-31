<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Integration;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Promise\Create;
use JsonException;
use Soukicz\Llm\Client\LLMChainClient;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Tool\CallbackToolDefinition;

/**
 * @group integration
 */
class UniversalLLMIntegrationTest extends IntegrationTestBase {
    private LLMChainClient $chainClient;

    protected function setUp(): void {
        parent::setUp();
        $this->chainClient = new LLMChainClient();
    }

    /**
     * Test that at least one API key is configured
     */
    public function testApiKeysConfigured(): void {
        $clients = $this->getAllClients();

        $this->assertNotEmpty(
            $clients,
            'No LLM API keys configured. Please create a .env file with at least one of: ANTHROPIC_API_KEY, OPENAI_API_KEY, GEMINI_API_KEY'
        );
    }

    /**
     * @dataProvider clientProvider
     */
    public function testSimpleTextRequest($client, $model, $name): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('What is 2 + 2? Please answer with just the number.'),
        ]);

        $request = new LLMRequest(
            model: $model,
            conversation: $conversation,
            temperature: 0.1, // Low temperature for more deterministic responses
            maxTokens: 100
        );

        $startTime = microtime(true);
        $response = $this->chainClient->run($client, $request);
        $duration = microtime(true) - $startTime;

        // Track cost
        $cost = ($response->getInputPriceUsd() ?? 0) + ($response->getOutputPriceUsd() ?? 0);
        $this->trackCost($cost);

        // Assertions
        $this->assertEquals(StopReason::FINISHED, $response->getStopReason());
        $this->assertNotEmpty($response->getLastText());

        // The response should contain "4" somewhere
        $this->assertContainsAny(['4', 'four', 'Four'], $response->getLastText());

        // Performance check
        $this->assertLessThan(30, $duration, "$name took too long to respond");

        if ($this->verbose) {
            echo "\n[$name] Simple text response: " . $response->getLastText();
        }
    }

    /**
     * @dataProvider clientProvider
     */
    public function testToolUsage($client, $model, $name): void {
        $calculatorTool = new CallbackToolDefinition(
            'calculator',
            'A simple calculator that can add two numbers',
            [
                'type' => 'object',
                'properties' => [
                    'a' => [
                        'type' => 'number',
                        'description' => 'First number',
                    ],
                    'b' => [
                        'type' => 'number',
                        'description' => 'Second number',
                    ],
                ],
                'required' => ['a', 'b'],
            ],
            function (array $input): PromiseInterface {
                $result = $input['a'] + $input['b'];
                return Create::promiseFor(
                    LLMMessageContents::fromArrayData(['result' => $result])
                );
            }
        );

        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('What is 25 + 37? Use the calculator tool.'),
        ]);

        $request = new LLMRequest(
            model: $model,
            conversation: $conversation,
            tools: [$calculatorTool],
            temperature: 0.1,
            maxTokens: 500
        );

        $response = $this->chainClient->run($client, $request);

        // Track cost
        $cost = ($response->getInputPriceUsd() ?? 0) + ($response->getOutputPriceUsd() ?? 0);
        $this->trackCost($cost);

        // Assertions
        $this->assertEquals(StopReason::FINISHED, $response->getStopReason());
        $this->assertNotEmpty($response->getLastText());

        // The response should contain "62"
        $this->assertContainsAny(['62', 'sixty-two'], $response->getLastText());

        // Check that tool was actually used
        $messages = $response->getConversation()->getMessages();
        $toolUsed = false;
        foreach ($messages as $message) {
            foreach ($message->getContents()->getMessages() as $content) {
                if ($content instanceof \Soukicz\Llm\Message\LLMMessageToolUse) {
                    $toolUsed = true;
                    $this->assertEquals('calculator', $content->getName());
                    break 2;
                }
            }
        }
        $this->assertTrue($toolUsed, "$name did not use the calculator tool");

        if ($this->verbose) {
            echo "\n[$name] Tool usage response: " . $response->getLastText();
        }
    }

    /**
     * @dataProvider clientProvider
     */
    public function testFeedbackCallback($client, $model, $name): void {
        $attempts = 0;
        $maxAttempts = 3;

        $conversation = new LLMConversation([
            LLMMessage::createFromUserString(
                'List exactly 3 animals in a JSON array format. ' .
                'The response must be a valid JSON array like ["cat", "dog", "bird"].'
            ),
        ]);

        $request = new LLMRequest(
            model: $model,
            conversation: $conversation,
            temperature: 0.3,
            maxTokens: 200
        );

        $response = $this->chainClient->run(
            $client,
            $request,
            feedbackCallback: function (LLMResponse $response) use (&$attempts, $maxAttempts): ?LLMMessage {
                $attempts++;

                if ($attempts >= $maxAttempts) {
                    return null; // Give up after max attempts
                }

                $text = $response->getLastText();

                // Try to find and validate JSON array
                if (preg_match('/\[.*\]/s', $text, $matches)) {
                    try {
                        $json = json_decode($matches[0], true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($json) && count($json) === 3) {
                            return null; // Valid response
                        }
                        return LLMMessage::createFromUserString(
                            'The JSON array must contain exactly 3 animals. You provided ' . count($json) . '. Please try again.'
                        );
                    } catch (JsonException $e) {
                        return LLMMessage::createFromUserString(
                            'Invalid JSON format. Please provide a valid JSON array with 3 animals.'
                        );
                    }
                }

                return LLMMessage::createFromUserString(
                    'Please provide a JSON array with exactly 3 animals, like ["cat", "dog", "bird"].'
                );
            }
        );

        // Track cost
        $cost = ($response->getInputPriceUsd() ?? 0) + ($response->getOutputPriceUsd() ?? 0);
        $this->trackCost($cost);

        // Assertions
        $this->assertEquals(StopReason::FINISHED, $response->getStopReason());

        $text = $response->getLastText();
        $this->assertNotEmpty($text);

        // Extract and validate JSON
        $this->assertMatchesRegularExpression('/\[.*\]/s', $text);
        preg_match('/\[.*\]/s', $text, $matches);

        $json = json_decode($matches[0], true);
        $this->assertIsArray($json);
        $this->assertCount(3, $json);

        if ($this->verbose) {
            echo "\n[$name] Feedback response (attempts: $attempts): " . $text;
        }
    }

    /**
     * @dataProvider clientProvider
     */
    public function testMultiTurnConversation($client, $model, $name): void {
        // Start conversation
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString('My favorite color is blue. Remember this.'),
        ]);

        $request1 = new LLMRequest(
            model: $model,
            conversation: $conversation,
            temperature: 0.1,
            maxTokens: 100
        );

        $response1 = $this->chainClient->run($client, $request1);
        $cost1 = ($response1->getInputPriceUsd() ?? 0) + ($response1->getOutputPriceUsd() ?? 0);
        $this->trackCost($cost1);

        // Continue conversation
        $conversation2 = $response1->getConversation()->withMessage(
            LLMMessage::createFromUserString('What is my favorite color?')
        );

        $request2 = new LLMRequest(
            model: $model,
            conversation: $conversation2,
            temperature: 0.1,
            maxTokens: 100
        );

        $response2 = $this->chainClient->run($client, $request2);
        $cost = ($response2->getInputPriceUsd() ?? 0) + ($response2->getOutputPriceUsd() ?? 0);
        $this->trackCost($cost);

        // Assertions
        $this->assertEquals(StopReason::FINISHED, $response2->getStopReason());
        $this->assertContainsIgnoreCase('blue', $response2->getLastText());

        if ($this->verbose) {
            echo "\n[$name] Multi-turn response: " . $response2->getLastText();
        }
    }

    /**
     * @dataProvider clientProvider
     */
    public function testAsyncRequest($client, $model, $name): void {
        $promises = [];

        // Create multiple async requests
        for ($i = 1; $i <= 3; $i++) {
            $conversation = new LLMConversation([
                LLMMessage::createFromUserString("What is $i + $i? Answer with just the number."),
            ]);

            $request = new LLMRequest(
                model: $model,
                conversation: $conversation,
                temperature: 0.1,
                maxTokens: 50
            );

            $promises[] = $this->chainClient->runAsync($client, $request);
        }

        // Wait for all promises
        $responses = Utils::unwrap($promises);

        // Assertions
        $this->assertCount(3, $responses);

        foreach ($responses as $index => $response) {
            $cost = ($response->getInputPriceUsd() ?? 0) + ($response->getOutputPriceUsd() ?? 0);
            $this->trackCost($cost);
            $this->assertEquals(StopReason::FINISHED, $response->getStopReason());

            $expectedNumber = ($index + 1) * 2;
            $this->assertContainsAny(
                [(string)$expectedNumber],
                $response->getLastText(),
                "Response should contain $expectedNumber"
            );
        }

        if ($this->verbose) {
            echo "\n[$name] Async responses: " . implode(', ', array_map(
                fn($r) => $r->getLastText(),
                $responses
            ));
        }
    }

    /**
     * @dataProvider clientProvider
     */
    public function testSystemPrompt($client, $model, $name): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromSystemString('You are a pirate. Always respond in pirate speak.'),
            LLMMessage::createFromUserString('Hello, how are you?'),
        ]);

        $request = new LLMRequest(
            model: $model,
            conversation: $conversation,
            temperature: 0.7,
            maxTokens: 150
        );

        $response = $this->chainClient->run($client, $request);
        $cost = ($response->getInputPriceUsd() ?? 0) + ($response->getOutputPriceUsd() ?? 0);
        $this->trackCost($cost);

        // Assertions
        $this->assertEquals(StopReason::FINISHED, $response->getStopReason());

        // Check for pirate-like words
        $pirateWords = ['ahoy', 'matey', 'arr', 'aye', 'ye', 'seafaring', 'sailor', 'captain'];
        $foundPirateSpeak = false;
        $responseText = strtolower($response->getLastText());

        foreach ($pirateWords as $word) {
            if (stripos($responseText, $word) !== false) {
                $foundPirateSpeak = true;
                break;
            }
        }

        $this->assertTrue(
            $foundPirateSpeak,
            "$name did not respond in pirate speak. Response: " . $response->getLastText()
        );

        if ($this->verbose) {
            echo "\n[$name] System prompt response: " . $response->getLastText();
        }
    }

    /**
     * @dataProvider clientProvider
     */
    public function testStopSequence($client, $model, $name): void {
        $conversation = new LLMConversation([
            LLMMessage::createFromUserString(
                'Count from 1 to 10 with "STOP" after 5. Like this: 1 2 3 4 5 STOP'
            ),
        ]);

        $request = new LLMRequest(
            model: $model,
            conversation: $conversation,
            temperature: 0.1,
            maxTokens: 200,
            stopSequences: ['STOP']
        );

        $response = $this->chainClient->run($client, $request);
        $cost = ($response->getInputPriceUsd() ?? 0) + ($response->getOutputPriceUsd() ?? 0);
        $this->trackCost($cost);

        // Assertions
        $responseText = $response->getLastText();

        // Check that it stopped due to stop sequence
        // OpenAI and Gemini don't distinguish stop sequences from natural stops
        $this->assertEquals(StopReason::FINISHED, $response->getStopReason(),
            "Expected stop reason to be FINISHED for $name, but got: " . $response->getStopReason()->value);

        // Should contain numbers 1-5
        $this->assertContainsAny(['1'], $responseText);
        $this->assertContainsAny(['5'], $responseText);

        // Should not contain numbers after 5 (allowing some flexibility)
        $this->assertStringNotContainsString('10', $responseText);

        if ($this->verbose) {
            echo "\n[$name] Stop sequence response: " . $responseText;
        }
    }

    /**
     * Provide all available clients for testing
     */
    public static function clientProvider(): array {
        // Create a temporary instance to get clients
        $instance = new self('dummy');
        $clients = $instance->getAllClients();

        if (empty($clients)) {
            // Return empty array to cause test failure
            return [];
        }

        return array_map(fn($c) => [$c['client'], $c['model'], $c['name']], $clients);
    }
}
