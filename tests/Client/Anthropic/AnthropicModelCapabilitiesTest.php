<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude47Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude48Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude51Fable;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Haiku;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Fable;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicModel;

/**
 * One matrix of every capability flag, so a new model class cannot silently inherit wrong defaults
 */
class AnthropicModelCapabilitiesTest extends TestCase {
    /**
     * @return array<string, array{AnthropicModel, bool, bool, bool, float}>
     */
    public static function models(): array {
        return [
            // model, sampling, adaptive by default, mid-conversation system, 1h cache write $/MTok
            'claude-sonnet-4-5' => [new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929), true, false, false, 6.0],
            'claude-sonnet-4-6' => [new AnthropicClaude46Sonnet(), true, false, false, 6.0],
            'claude-opus-4-7' => [new AnthropicClaude47Opus(), false, false, false, 10.0],
            'claude-opus-4-8' => [new AnthropicClaude48Opus(), false, false, true, 10.0],
            'claude-opus-5' => [new AnthropicClaude5Opus(), false, true, true, 10.0],
            'claude-opus-5-5' => [new AnthropicClaude55Opus(), false, true, true, 8.0],
            'claude-sonnet-5' => [new AnthropicClaude5Sonnet(), false, true, false, 4.0],
            'claude-sonnet-5-5' => [new AnthropicClaude55Sonnet(), false, true, true, 4.0],
            'claude-haiku-5-5' => [new AnthropicClaude55Haiku(), false, true, true, 0.2],
            'claude-fable-5' => [new AnthropicClaude5Fable(), false, true, true, 20.0],
            'claude-fable-5-1' => [new AnthropicClaude51Fable(), false, true, true, 20.0],
        ];
    }

    /**
     * @dataProvider models
     */
    public function testCapabilities(AnthropicModel $model, bool $sampling, bool $adaptiveByDefault, bool $midConversationSystem, float $cacheWrite1h): void {
        $this->assertSame($sampling, $model->supportsSamplingParameters());
        $this->assertSame($adaptiveByDefault, $model->hasAdaptiveThinkingByDefault());
        $this->assertSame($midConversationSystem, $model->supportsMidConversationSystemMessages());
        $this->assertEqualsWithDelta($cacheWrite1h, $model->getCacheWrite1hPricePerMillionTokens(), 1e-9);
    }
}
