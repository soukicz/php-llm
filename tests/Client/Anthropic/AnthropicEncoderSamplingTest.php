<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Client\Anthropic;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude35Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude47Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude48Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude51Fable;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude55Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Fable;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude5Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicModel;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;

class AnthropicEncoderSamplingTest extends TestCase {
    private function encode(AnthropicModel $model, ?float $temperature): array {
        return (new AnthropicEncoder())->encodeRequest(new LLMRequest(
            model: $model,
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hi')]),
            temperature: $temperature,
        ));
    }

    /**
     * @return array<string, array{AnthropicModel}>
     */
    public static function modelsRejectingSampling(): array {
        return [
            'claude-opus-4-7' => [new AnthropicClaude47Opus()],
            'claude-opus-4-8' => [new AnthropicClaude48Opus()],
            'claude-opus-5' => [new AnthropicClaude5Opus()],
            'claude-opus-5-5' => [new AnthropicClaude55Opus()],
            'claude-sonnet-5' => [new AnthropicClaude5Sonnet()],
            'claude-fable-5' => [new AnthropicClaude5Fable()],
            'claude-fable-5-1' => [new AnthropicClaude51Fable()],
        ];
    }

    /**
     * @dataProvider modelsRejectingSampling
     */
    public function testTemperatureIsNeverSentToModelsThatRejectIt(AnthropicModel $model): void {
        $this->assertFalse($model->supportsSamplingParameters());
        $this->assertArrayNotHasKey('temperature', $this->encode($model, 0.7));
        $this->assertArrayNotHasKey('temperature', $this->encode($model, 0.0));
    }

    public function testNullTemperatureIsOmitted(): void {
        $this->assertArrayNotHasKey('temperature', $this->encode(new AnthropicClaude35Sonnet(AnthropicClaude35Sonnet::VERSION_20241022), null));
    }

    public function testOlderModelsStillGetTheDefaultTemperature(): void {
        $model = new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929);

        $this->assertTrue($model->supportsSamplingParameters());
        $encoded = (new AnthropicEncoder())->encodeRequest(new LLMRequest(
            model: $model,
            conversation: new LLMConversation([LLMMessage::createFromUserString('Hi')]),
        ));
        $this->assertSame(0.0, $encoded['temperature']);
    }
}
