<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Integration;

use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Haiku;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Sonnet;
use Soukicz\Llm\Client\Gemini\GeminiClient;
use Soukicz\Llm\Client\Gemini\Model\Gemini25FlashLite;
use Soukicz\Llm\Client\LLMAgentClient;
use Soukicz\Llm\Client\LLMClient;
use Soukicz\Llm\Client\OpenAI\Model\GPT54Nano;
use Soukicz\Llm\Client\OpenAI\OpenAIClient;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\Config\ReasoningBudget;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageReasoning;

/**
 * Verifies the reasoning configuration against the live APIs: ReasoningEffort on all
 * three providers and the Anthropic-only ReasoningBudget (extended thinking).
 *
 * Provider constraints exercised here:
 *  - Anthropic requires temperature=1 when thinking is enabled
 *  - Anthropic requires maxTokens greater than the thinking budget
 *
 * @group integration
 */
class ReasoningIntegrationTest extends IntegrationTestBase {
    protected function getRequiredEnvironmentVariables(): array {
        // Per-test skipping is handled in requireKey()
        return [];
    }

    private function requireKey(string $envVar): string {
        self::loadEnvironmentStatic();
        if (empty($_ENV[$envVar])) {
            $this->markTestSkipped("$envVar is not configured");
        }

        return $_ENV[$envVar];
    }

    private function runReasoningRequest(LLMClient $client, LLMRequest $request): LLMResponse {
        $response = (new LLMAgentClient())->run($client, $request);
        $this->trackCost(($response->getInputPriceUsd() ?? 0) + ($response->getOutputPriceUsd() ?? 0));

        $this->assertEquals(StopReason::FINISHED, $response->getStopReason());
        $this->assertStringContainsString('39', $response->getLastText(), 'Expected the correct arithmetic result');

        return $response;
    }

    private function createConversation(): LLMConversation {
        // Deliberately simple: this test verifies the reasoning configuration is accepted
        // by the API, not the model's problem-solving ability
        return new LLMConversation([
            LLMMessage::createFromUserString('What is 17 + 24 - 2? Reply with just the number.'),
        ]);
    }

    public function testAnthropicReasoningBudgetReturnsThinkingBlocks(): void {
        $client = new AnthropicClient($this->requireKey('ANTHROPIC_API_KEY'), $this->cache);

        $response = $this->runReasoningRequest($client, new LLMRequest(
            model: new AnthropicClaude45Haiku(AnthropicClaude45Haiku::VERSION_20251001),
            conversation: $this->createConversation(),
            temperature: 1.0,
            maxTokens: 6000,
            reasoningConfig: new ReasoningBudget(2048),
        ));

        // Extended thinking must surface as reasoning content in the conversation
        $reasoningFound = false;
        foreach ($response->getConversation()->getMessages() as $message) {
            foreach ($message->getContents() as $content) {
                if ($content instanceof LLMMessageReasoning) {
                    $reasoningFound = true;
                    $this->assertNotSame('', $content->getText());
                }
            }
        }
        $this->assertTrue($reasoningFound, 'Expected at least one reasoning block in the conversation');
    }

    public function testAnthropicReasoningEffort(): void {
        $client = new AnthropicClient($this->requireKey('ANTHROPIC_API_KEY'), $this->cache);

        $this->runReasoningRequest($client, new LLMRequest(
            model: new AnthropicClaude46Sonnet(),
            conversation: $this->createConversation(),
            temperature: 1.0,
            maxTokens: 6000,
            reasoningConfig: ReasoningEffort::LOW,
        ));
    }

    public function testOpenAIReasoningEffort(): void {
        $client = new OpenAIClient($this->requireKey('OPENAI_API_KEY'), null, $this->cache);

        $this->runReasoningRequest($client, new LLMRequest(
            model: new GPT54Nano(GPT54Nano::VERSION_2026_03_17),
            conversation: $this->createConversation(),
            temperature: 1.0,
            maxTokens: 6000,
            reasoningConfig: ReasoningEffort::LOW,
        ));
    }

    public function testGeminiReasoningEffort(): void {
        $client = new GeminiClient($this->requireKey('GEMINI_API_KEY'), $this->cache);

        $this->runReasoningRequest($client, new LLMRequest(
            model: new Gemini25FlashLite(),
            conversation: $this->createConversation(),
            maxTokens: 6000,
            reasoningConfig: ReasoningEffort::LOW,
        ));
    }
}
