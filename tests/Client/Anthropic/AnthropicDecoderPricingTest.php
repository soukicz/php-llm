<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;

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
}
