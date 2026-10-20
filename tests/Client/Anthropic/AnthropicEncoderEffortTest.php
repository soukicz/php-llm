<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicModel;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

class AnthropicEncoderEffortTest extends TestCase {
    private function encode(AnthropicModel $model, ReasoningEffort $effort): array {
        return (new AnthropicEncoder())->encodeRequest(new LLMRequest(
            model: $model,
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hi')]),
            reasoningConfig: $effort,
        ));
    }

    /**
     * @return array<string, array{ReasoningEffort, string}>
     */
    public static function efforts(): array {
        return [
            'minimal' => [ReasoningEffort::MINIMAL, 'low'],
            'low' => [ReasoningEffort::LOW, 'low'],
            'medium' => [ReasoningEffort::MEDIUM, 'medium'],
            'high' => [ReasoningEffort::HIGH, 'high'],
            'xhigh' => [ReasoningEffort::XHIGH, 'xhigh'],
            'extra_high keeps max' => [ReasoningEffort::EXTRA_HIGH, 'max'],
            'max' => [ReasoningEffort::MAX, 'max'],
        ];
    }

    /**
     * @dataProvider efforts
     */
    public function testEffortIsAlwaysSent(ReasoningEffort $effort, string $expected): void {
        $encoded = $this->encode(new AnthropicClaude55Opus(), $effort);

        $this->assertSame(['type' => 'adaptive'], $encoded['thinking']);
        $this->assertSame($expected, $encoded['output_config']['effort']);
    }

    public function testNoneSendsTheLowestEffortWhereThinkingRunsByDefault(): void {
        $model = new AnthropicClaude55Opus();
        $encoded = $this->encode($model, ReasoningEffort::NONE);

        $this->assertTrue($model->hasAdaptiveThinkingByDefault());
        $this->assertArrayNotHasKey('thinking', $encoded);
        $this->assertSame(['effort' => 'low'], $encoded['output_config']);
    }

    public function testNoneSendsNothingWhereThinkingIsOffByDefault(): void {
        $model = new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929);
        $encoded = $this->encode($model, ReasoningEffort::NONE);

        $this->assertFalse($model->hasAdaptiveThinkingByDefault());
        $this->assertArrayNotHasKey('thinking', $encoded);
        $this->assertArrayNotHasKey('output_config', $encoded);
    }
}
