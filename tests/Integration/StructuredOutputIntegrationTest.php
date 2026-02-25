<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Integration;

use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Haiku;
use Soukicz\Llm\Client\Gemini\GeminiClient;
use Soukicz\Llm\Client\Gemini\Model\Gemini20Flash;
use Soukicz\Llm\Client\LLMAgentClient;
use Soukicz\Llm\Client\LLMClient;
use Soukicz\Llm\Client\ModelInterface;
use Soukicz\Llm\Client\OpenAI\Model\GPT4oMini;
use Soukicz\Llm\Client\OpenAI\OpenAIClient;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\Config\StructuredOutputConfig;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageStructuredData;

/**
 * @group integration
 */
class StructuredOutputIntegrationTest extends IntegrationTestBase {
    private LLMAgentClient $agentClient;

    protected function setUp(): void {
        parent::setUp();
        $this->agentClient = new LLMAgentClient();
    }

    /**
     * @dataProvider clientProvider
     */
    public function testStructuredOutputExtraction($client, $model, $name): void {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
                'city' => ['type' => 'string'],
            ],
            'required' => ['name', 'age', 'city'],

        ];

        $conversation = new LLMConversation([
            LLMMessage::createFromUserString(
                'Extract the person info: John Smith is 42 years old and lives in Prague.'
            ),
        ]);

        $request = new LLMRequest(
            model: $model,
            conversation: $conversation,
            temperature: 0.0,
            maxTokens: 200,
            structuredOutputConfig: new StructuredOutputConfig($schema),
        );

        $response = $this->agentClient->run($client, $request);

        $cost = ($response->getInputPriceUsd() ?? 0) + ($response->getOutputPriceUsd() ?? 0);
        $this->trackCost($cost);

        $this->assertEquals(StopReason::FINISHED, $response->getStopReason());

        // Verify the response contains LLMMessageStructuredData
        $lastMessage = $response->getConversation()->getLastMessage();
        $contents = iterator_to_array($lastMessage->getContents());
        $this->assertInstanceOf(LLMMessageStructuredData::class, $contents[0], "[$name] Expected structured data response");

        // Verify getLastStructuredData() convenience method
        $data = $response->getLastStructuredData();
        $this->assertIsArray($data);

        // Verify extracted values
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('age', $data);
        $this->assertArrayHasKey('city', $data);
        $this->assertStringContainsStringIgnoringCase('John', $data['name']);
        $this->assertEquals(42, $data['age']);
        $this->assertStringContainsStringIgnoringCase('Prague', $data['city']);

        if ($this->verbose) {
            echo "\n[$name] Structured output: " . json_encode($data, JSON_PRETTY_PRINT);
            echo sprintf("\n[$name] Cost: $%.6f", $cost);
        }
    }

    /**
     * @dataProvider clientProvider
     */
    public function testStructuredOutputWithEnum($client, $model, $name): void {
        $schema = [
            'type' => 'object',
            'properties' => [
                'sentiment' => [
                    'type' => 'string',
                    'enum' => ['positive', 'negative', 'neutral'],
                ],
                'confidence' => [
                    'type' => 'number',
                ],
            ],
            'required' => ['sentiment', 'confidence'],

        ];

        $conversation = new LLMConversation([
            LLMMessage::createFromUserString(
                'Analyze the sentiment: "I absolutely love this product, it changed my life!"'
            ),
        ]);

        $request = new LLMRequest(
            model: $model,
            conversation: $conversation,
            temperature: 0.0,
            maxTokens: 100,
            structuredOutputConfig: new StructuredOutputConfig($schema),
        );

        $response = $this->agentClient->run($client, $request);

        $cost = ($response->getInputPriceUsd() ?? 0) + ($response->getOutputPriceUsd() ?? 0);
        $this->trackCost($cost);

        $data = $response->getLastStructuredData();

        $this->assertContains($data['sentiment'], ['positive', 'negative', 'neutral']);
        $this->assertEquals('positive', $data['sentiment']);
        $this->assertIsNumeric($data['confidence']);

        if ($this->verbose) {
            echo "\n[$name] Sentiment analysis: " . json_encode($data);
            echo sprintf("\n[$name] Cost: $%.6f", $cost);
        }
    }

    /**
     * Get clients with models that support structured outputs.
     *
     * @return array<array{client: LLMClient, model: ModelInterface, name: string}>
     */
    protected function getStructuredOutputClients(): array {
        self::loadEnvironmentStatic();

        if ($this->cache === null) {
            $cacheDir = sys_get_temp_dir() . '/llm-integration-tests';
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0777, true);
            }
            $this->cache = new \Soukicz\Llm\Cache\FileCache($cacheDir);
        }

        $clients = [];

        if (!empty($_ENV['ANTHROPIC_API_KEY'])) {
            $clients[] = [
                'client' => new AnthropicClient($_ENV['ANTHROPIC_API_KEY'], $this->cache),
                'model' => new AnthropicClaude45Haiku(AnthropicClaude45Haiku::VERSION_20251001),
                'name' => 'Anthropic Claude 4.5 Haiku',
            ];
        }

        if (!empty($_ENV['OPENAI_API_KEY'])) {
            $clients[] = [
                'client' => new OpenAIClient($_ENV['OPENAI_API_KEY'], '', $this->cache),
                'model' => new GPT4oMini(GPT4oMini::VERSION_2024_07_18),
                'name' => 'OpenAI GPT-4o Mini',
            ];
        }

        if (!empty($_ENV['GEMINI_API_KEY'])) {
            $clients[] = [
                'client' => new GeminiClient($_ENV['GEMINI_API_KEY'], $this->cache),
                'model' => new Gemini20Flash(),
                'name' => 'Google Gemini 2.0 Flash',
            ];
        }

        return $clients;
    }

    /**
     * Provide clients that support structured outputs
     */
    public static function clientProvider(): array {
        $instance = new self('dummy');
        $clients = $instance->getStructuredOutputClients();

        if (empty($clients)) {
            return [];
        }

        return array_map(fn($c) => [$c['client'], $c['model'], $c['name']], $clients);
    }
}
