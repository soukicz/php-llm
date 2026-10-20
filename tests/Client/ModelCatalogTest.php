<?php

namespace Soukicz\Llm\Tests\Client;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude47Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude48Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude51Fable;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Haiku;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Fable;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Sonnet;
use Soukicz\Llm\Client\Gemini\Model\Gemini31FlashImage;
use Soukicz\Llm\Client\Gemini\Model\Gemini31FlashLite;
use Soukicz\Llm\Client\Gemini\Model\Gemini31FlashLiteImage;
use Soukicz\Llm\Client\Gemini\Model\Gemini31ProPreview;
use Soukicz\Llm\Client\Gemini\Model\Gemini35Flash;
use Soukicz\Llm\Client\Gemini\Model\Gemini35FlashLite;
use Soukicz\Llm\Client\Gemini\Model\Gemini36Flash;
use Soukicz\Llm\Client\Gemini\Model\Gemini37Flash;
use Soukicz\Llm\Client\Gemini\Model\Gemini38Flash;
use Soukicz\Llm\Client\Gemini\Model\Gemini3FlashPreview;
use Soukicz\Llm\Client\Gemini\Model\Gemini3ProImage;
use Soukicz\Llm\Client\Gemini\Model\Gemini4Argon;
use Soukicz\Llm\Client\Gemini\Model\GeminiNanoBanana21;
use Soukicz\Llm\Client\ModelInterface;
use Soukicz\Llm\Client\OpenAI\Model\GPT55;
use Soukicz\Llm\Client\OpenAI\Model\GPT56Luna;
use Soukicz\Llm\Client\OpenAI\Model\GPT56Sol;
use Soukicz\Llm\Client\OpenAI\Model\GPT56Terra;
use Soukicz\Llm\Client\OpenAI\Model\GPT61Sol;
use Soukicz\Llm\Client\OpenAI\Model\GPT6Astra;
use Soukicz\Llm\Client\OpenAI\Model\GPT6Luna;
use Soukicz\Llm\Client\OpenAI\Model\GPT6Sol;

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
            'claude-sonnet-5' => [new AnthropicClaude5Sonnet(), 'claude-sonnet-5', 2.0, 10.0, 2.5, 0.2],
            'claude-fable-5' => [new AnthropicClaude5Fable(), 'claude-fable-5', 10.0, 50.0, 12.5, 1.0],
            'claude-opus-5-5' => [new AnthropicClaude55Opus(), 'claude-opus-5-5', 4.0, 20.0, 5.0, 0.2],
            'claude-fable-5-1' => [new AnthropicClaude51Fable(), 'claude-fable-5-1', 10.0, 50.0, 12.5, 0.25],
            'claude-sonnet-5-5' => [new AnthropicClaude55Sonnet(), 'claude-sonnet-5-5', 2.0, 10.0, 2.5, 0.1],
            'claude-haiku-5-5' => [new AnthropicClaude55Haiku(), 'claude-haiku-5-5', 0.1, 0.5, 0.125, 0.01],
            'gpt-5.5' => [new GPT55(GPT55::VERSION_2026_04_23), 'gpt-5.5-2026-04-23', 5.0, 30.0, 0.5, 0.0],
            'gpt-5.6-sol' => [new GPT56Sol(), 'gpt-5.6-sol', 4.0, 20.0, 0.4, 0.0],
            'gpt-5.6-terra' => [new GPT56Terra(), 'gpt-5.6-terra', 2.0, 12.0, 0.2, 0.0],
            'gpt-5.6-luna' => [new GPT56Luna(), 'gpt-5.6-luna', 0.2, 1.2, 0.02, 0.0],
            'gpt-6-astra' => [new GPT6Astra(), 'gpt-6-astra', 10.0, 50.0, 1.0, 0.0],
            'gpt-6-sol' => [new GPT6Sol(), 'gpt-6-sol', 2.0, 10.0, 0.2, 0.0],
            'gpt-6-luna' => [new GPT6Luna(), 'gpt-6-luna', 0.1, 0.5, 0.01, 0.0],
            'gpt-6.1-sol' => [new GPT61Sol(), 'gpt-6.1-sol', 2.0, 10.0, 0.1, 0.0],
            // Gemini reports cached prices equal to the uncached ones
            'gemini-4-argon' => [new Gemini4Argon(), 'gemini-4-argon', 2.0, 10.0, 2.0, 10.0],
            'gemini-3-flash-preview' => [new Gemini3FlashPreview(), 'gemini-3-flash-preview', 0.5, 3.0, 0.5, 3.0],
            'gemini-3.1-pro-preview' => [new Gemini31ProPreview(), 'gemini-3.1-pro-preview', 2.0, 12.0, 2.0, 12.0],
            'gemini-3.1-flash-lite' => [new Gemini31FlashLite(), 'gemini-3.1-flash-lite', 0.25, 1.5, 0.25, 1.5],
            'gemini-3.5-flash' => [new Gemini35Flash(), 'gemini-3.5-flash', 1.5, 9.0, 1.5, 9.0],
            'gemini-3.5-flash-lite' => [new Gemini35FlashLite(), 'gemini-3.5-flash-lite', 0.3, 2.5, 0.3, 2.5],
            'gemini-3.6-flash' => [new Gemini36Flash(), 'gemini-3.6-flash', 0.75, 3.75, 0.75, 3.75],
            'gemini-3.7-flash' => [new Gemini37Flash(), 'gemini-3.7-flash', 0.75, 3.75, 0.75, 3.75],
            'gemini-3.8-flash' => [new Gemini38Flash(), 'gemini-3.8-flash', 0.75, 3.75, 0.75, 3.75],
            'gemini-3.1-flash-image' => [new Gemini31FlashImage(), 'gemini-3.1-flash-image', 0.5, 60.0, 0.5, 60.0],
            'gemini-3.1-flash-lite-image' => [new Gemini31FlashLiteImage(), 'gemini-3.1-flash-lite-image', 0.25, 30.0, 0.25, 30.0],
            'gemini-3-pro-image' => [new Gemini3ProImage(), 'gemini-3-pro-image', 2.0, 120.0, 2.0, 120.0],
            'gemini-nano-banana-2.1' => [new GeminiNanoBanana21(), 'gemini-nano-banana-2.1', 1.5, 30.0, 1.5, 30.0],
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
