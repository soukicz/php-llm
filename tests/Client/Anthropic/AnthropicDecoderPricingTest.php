<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Haiku;
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Usage\TokenUsage;

class AnthropicDecoderPricingTest extends TestCase {
    private AnthropicEncoder $encoder;

    protected function setUp(): void {
        $this->encoder = new AnthropicEncoder();
    }

    private function createRequest(): LLMRequest {
        return new LLMRequest(
            // Sonnet 4.5: input $3/M, output $15/M, cache write $3.75/M, cache read $0.30/M
            model: new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hello')]),
        );
    }

    private function decode(array $usage): LLMResponse {
        $response = $this->encoder->decodeResponse($this->createRequest(), new ModelResponse([
            'content' => [['type' => 'text', 'text' => 'Hi there']],
            'usage' => $usage,
            'stop_reason' => 'end_turn',
        ], 500));

        $this->assertInstanceOf(LLMResponse::class, $response);

        return $response;
    }

    public function testPricingWithoutCache(): void {
        $response = $this->decode([
            'input_tokens' => 1_000_000,
            'output_tokens' => 100_000,
        ]);

        $this->assertEqualsWithDelta(3.0, $response->getInputPriceUsd(), 1e-9);
        $this->assertEqualsWithDelta(1.5, $response->getOutputPriceUsd(), 1e-9);
        $this->assertSame(1_000_000, $response->getInputTokens());
        $this->assertSame(100_000, $response->getOutputTokens());
        $this->assertSame(StopReason::FINISHED, $response->getStopReason());
    }

    /**
     * Cache writes and cache reads are both input-side costs. Cache reads used to be
     * misattributed to the output price bucket - this pins the corrected behavior.
     */
    public function testCacheTokensAreChargedToInputBucket(): void {
        $response = $this->decode([
            'input_tokens' => 1_000_000,
            'output_tokens' => 100_000,
            'cache_creation_input_tokens' => 1_000_000,
            'cache_read_input_tokens' => 1_000_000,
        ]);

        // 1M uncached input ($3.00) + 1M cache write ($3.75) + 1M cache read ($0.30)
        $this->assertEqualsWithDelta(7.05, $response->getInputPriceUsd(), 1e-9);
        // Output stays pure output: 100k * $15/M
        $this->assertEqualsWithDelta(1.5, $response->getOutputPriceUsd(), 1e-9);
    }

    public function testOneHourCacheWritesUseTheOneHourPrice(): void {
        $response = $this->decode([
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_creation_input_tokens' => 2_000_000,
            'cache_creation' => ['ephemeral_5m_input_tokens' => 1_000_000, 'ephemeral_1h_input_tokens' => 1_000_000],
        ]);

        // 1M 5-minute writes ($3.75) + 1M 1-hour writes (2x base = $6.00)
        $this->assertEqualsWithDelta(9.75, $response->getInputPriceUsd(), 1e-9);
        $this->assertEquals(new TokenUsage(cacheWrite5m: 1_000_000, cacheWrite1h: 1_000_000), $response->getStepUsage());
    }

    public function testWritesWithoutTtlBreakdownCountAsFiveMinute(): void {
        $response = $this->decode([
            'input_tokens' => 10,
            'output_tokens' => 5,
            'cache_creation_input_tokens' => 500,
            'cache_read_input_tokens' => 200,
        ]);

        $this->assertEquals(new TokenUsage(input: 10, output: 5, cacheWrite5m: 500, cacheRead: 200), $response->getStepUsage());
    }

    /**
     * Review focus 3: a step served from cache still costs money, so a loop of cheap steps reaches maxCostUsd
     */
    public function testCacheReadOnlyStepStillCosts(): void {
        $response = $this->decode([
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_read_input_tokens' => 1_000_000,
        ]);

        $this->assertEqualsWithDelta(0.30, $response->getTotalCostUsd(), 1e-9);
    }

    public function testUsageAccumulatesAcrossSteps(): void {
        $first = $this->decode(['input_tokens' => 100, 'output_tokens' => 10, 'cache_read_input_tokens' => 1000]);
        $second = $this->encoder->decodeResponse($first->getRequest(), new ModelResponse([
            'content' => [['type' => 'text', 'text' => 'Again']],
            'usage' => ['input_tokens' => 50, 'output_tokens' => 5, 'cache_read_input_tokens' => 2000],
            'stop_reason' => 'end_turn',
        ], 300));

        $this->assertInstanceOf(LLMResponse::class, $second);
        $this->assertEquals(new TokenUsage(input: 50, output: 5, cacheRead: 2000), $second->getStepUsage());
        $this->assertEquals(new TokenUsage(input: 150, output: 15, cacheRead: 3000), $second->getUsage());
        // Sonnet 4.5: $3 input, $0.30 cache read, $15 output
        $this->assertEqualsWithDelta((150 * 3.0 + 3000 * 0.30 + 15 * 15.0) / 1_000_000, $second->getTotalCostUsd(), 1e-12);
    }

    public function testHaiku55UsesTheLongPromptRateCardAbove100kPromptTokens(): void {
        $request = new LLMRequest(
            model: new AnthropicClaude55Haiku(),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hello')]),
        );
        $decode = fn(int $input): LLMResponse => $this->encoder->decodeResponse($request, new ModelResponse([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => $input, 'output_tokens' => 1_000, 'cache_read_input_tokens' => 50_000],
            'stop_reason' => 'end_turn',
        ], 10));

        // exactly 100,000 prompt tokens is not "over 100,000": short-prompt prices
        $this->assertEqualsWithDelta((50_000 * 0.10 + 50_000 * 0.01 + 1_000 * 0.50) / 1_000_000, $decode(50_000)->getTotalCostUsd(), 1e-12);
        // one token more and the whole request pays the long-prompt prices, cache reads included
        $this->assertEqualsWithDelta((50_001 * 0.50 + 50_000 * 0.05 + 1_000 * 2.50) / 1_000_000, $decode(50_001)->getTotalCostUsd(), 1e-12);
    }
}
