<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\OpenAI\Model\GPT41;
use Soukicz\Llm\Client\StopReason;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Usage\TokenUsage;

class LLMResponseTest extends TestCase {
    private function request(): LLMRequest {
        return new LLMRequest(
            model: new GPT41(GPT41::VERSION_2025_04_14),
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hi')]),
        );
    }

    public function testResponsesBuiltWithoutUsageFallBackToTheTokenCounters(): void {
        $response = new LLMResponse($this->request(), StopReason::FINISHED, 100, 50, 50, 0.25, 0.5, 10);

        $this->assertEquals(new TokenUsage(input: 100, output: 50), $response->getUsage());
        $this->assertEquals($response->getUsage(), $response->getStepUsage());
        $this->assertEqualsWithDelta(0.75, $response->getTotalCostUsd(), 1e-12);
    }

    public function testMissingPricesCostNothing(): void {
        $response = new LLMResponse($this->request(), StopReason::FINISHED, 1, 1, 1, null, null, 10);

        $this->assertSame(0.0, $response->getTotalCostUsd());
    }

    public function testExplicitUsageWins(): void {
        $usage = new TokenUsage(input: 10, output: 5, cacheRead: 1000);
        $step = new TokenUsage(input: 4, output: 2, cacheRead: 500);
        $response = new LLMResponse($this->request(), StopReason::FINISHED, 10, 5, 5, 0.0, 0.0, 10, $usage, $step);

        $this->assertSame($usage, $response->getUsage());
        $this->assertSame($step, $response->getStepUsage());
    }
}
