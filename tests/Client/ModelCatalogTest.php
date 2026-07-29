<?php

namespace Soukicz\Llm\Tests\Client;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude47Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude48Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Fable;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Sonnet;
use Soukicz\Llm\Client\ModelInterface;
use Soukicz\Llm\Client\OpenAI\Model\GPT55;
use Soukicz\Llm\Client\OpenAI\Model\GPT56Luna;
use Soukicz\Llm\Client\OpenAI\Model\GPT56Sol;
use Soukicz\Llm\Client\OpenAI\Model\GPT56Terra;

class ModelCatalogTest extends TestCase {
    /**
     * @return array<string, array{ModelInterface, string, float, float, float, float}>
     */
    public static function modelProvider(): array {
        return [
            // model, code, input, output, cache write, cache read
            'claude-opus-4-7' => [new AnthropicClaude47Opus(), 'claude-opus-4-7', 5.0, 25.0, 6.25, 0.5],
            'claude-opus-4-8' => [new AnthropicClaude48Opus(), 'claude-opus-4-8', 5.0, 25.0, 6.25, 0.5],
            'claude-opus-5' => [new AnthropicClaude5Opus(), 'claude-opus-5', 5.0, 25.0, 6.25, 0.5],
            'claude-sonnet-5' => [new AnthropicClaude5Sonnet(), 'claude-sonnet-5', 3.0, 15.0, 3.75, 0.3],
            'claude-fable-5' => [new AnthropicClaude5Fable(), 'claude-fable-5', 10.0, 50.0, 12.5, 1.0],
            'gpt-5.5' => [new GPT55(GPT55::VERSION_2026_04_23), 'gpt-5.5-2026-04-23', 5.0, 30.0, 0.5, 0.0],
            'gpt-5.6-sol' => [new GPT56Sol(), 'gpt-5.6-sol', 5.0, 30.0, 0.5, 0.0],
            'gpt-5.6-terra' => [new GPT56Terra(), 'gpt-5.6-terra', 2.5, 15.0, 0.25, 0.0],
            'gpt-5.6-luna' => [new GPT56Luna(), 'gpt-5.6-luna', 1.0, 6.0, 0.1, 0.0],
        ];
    }

    /**
     * @dataProvider modelProvider
     */
    public function testModelCodeAndPricing(
        ModelInterface $model,
        string $expectedCode,
        float $inputPrice,
        float $outputPrice,
        float $cachedInputPrice,
        float $cachedOutputPrice
    ): void {
        $this->assertSame($expectedCode, $model->getCode());
        $this->assertSame($inputPrice, $model->getInputPricePerMillionTokens());
        $this->assertSame($outputPrice, $model->getOutputPricePerMillionTokens());
        $this->assertSame($cachedInputPrice, $model->getCachedInputPricePerMillionTokens());
        $this->assertSame($cachedOutputPrice, $model->getCachedOutputPricePerMillionTokens());
    }
}
