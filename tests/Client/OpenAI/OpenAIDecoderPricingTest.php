<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\OpenAI;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\ModelResponse;
use Soukicz\Llm\Client\OpenAI\Model\GPT54;
use Soukicz\Llm\Client\OpenAI\OpenAIEncoder;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;

class OpenAIDecoderPricingTest extends TestCase {
    private OpenAIEncoder $encoder;

    protected function setUp(): void {
        $this->encoder = new OpenAIEncoder();
    }

    private function createRequest(): LLMRequest {
        return new LLMRequest(
            // GPT-5.4: input $2.50/M, output $15/M, cached input $0.25/M
            model: new GPT54(GPT54::VERSION_2026_03_05),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hello')]),
        );
    }

    private function decode(array $usage): LLMResponse {
        $response = $this->encoder->decodeResponse($this->createRequest(), new ModelResponse([
            'choices' => [
                [
                    'message' => ['content' => 'Hi there'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => $usage,
        ], 500));

        $this->assertInstanceOf(LLMResponse::class, $response);

        return $response;
    }

    public function testPricingWithoutCachedTokens(): void {
        $response = $this->decode([
            'prompt_tokens' => 1_000_000,
            'completion_tokens' => 100_000,
        ]);

        $this->assertEqualsWithDelta(2.5, $response->getInputPriceUsd(), 1e-9);
        $this->assertEqualsWithDelta(1.5, $response->getOutputPriceUsd(), 1e-9);
        $this->assertSame(StopReason::FINISHED, $response->getStopReason());
    }

    /**
     * OpenAI reports cached prompt tokens as a subset of prompt_tokens; they are billed
     * at the cached input rate. The discount used to be ignored entirely.
     */
    public function testCachedPromptTokensGetDiscountedRate(): void {
        $response = $this->decode([
            'prompt_tokens' => 1_000_000,
            'completion_tokens' => 100_000,
            'prompt_tokens_details' => ['cached_tokens' => 600_000],
        ]);

        // 400k uncached * $2.50/M + 600k cached * $0.25/M = 1.00 + 0.15
        $this->assertEqualsWithDelta(1.15, $response->getInputPriceUsd(), 1e-9);
        $this->assertEqualsWithDelta(1.5, $response->getOutputPriceUsd(), 1e-9);
        // Token counts still report the full prompt size
        $this->assertSame(1_000_000, $response->getInputTokens());
    }
}
