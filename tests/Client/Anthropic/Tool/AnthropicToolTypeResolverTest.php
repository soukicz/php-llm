<?php

namespace Soukicz\Llm\Tests\Client\Anthropic\Tool;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude35Haiku;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude35Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude37Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude4Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude4Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude41Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Haiku;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Sonnet;
use Soukicz\Llm\Client\Anthropic\Tool\AnthropicToolTypeResolver;

class AnthropicToolTypeResolverTest extends TestCase {
    public function testGetTextEditorTypeForClaude4Models(): void {
        $models = [
            new AnthropicClaude4Sonnet(AnthropicClaude4Sonnet::VERSION_20250514),
            new AnthropicClaude4Opus(AnthropicClaude4Opus::VERSION_20250514),
            new AnthropicClaude41Opus(AnthropicClaude41Opus::VERSION_20250805),
            new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929),
            new AnthropicClaude45Haiku(AnthropicClaude45Haiku::VERSION_20251001),
            new AnthropicClaude46Opus(),
            new AnthropicClaude46Sonnet(),
        ];

        foreach ($models as $model) {
            $this->assertEquals(
                'text_editor_20250728',
                AnthropicToolTypeResolver::getTextEditorType($model),
                'Failed for model: ' . $model->getCode()
            );
        }
    }

    public function testGetTextEditorTypeForClaude37Sonnet(): void {
        $model = new AnthropicClaude37Sonnet(AnthropicClaude37Sonnet::VERSION_20250219);
        $this->assertEquals('text_editor_20250124', AnthropicToolTypeResolver::getTextEditorType($model));
    }

    public function testGetTextEditorTypeForOtherModels(): void {
        $models = [
            new AnthropicClaude35Sonnet(AnthropicClaude35Sonnet::VERSION_20241022),
            new AnthropicClaude35Haiku(AnthropicClaude35Haiku::VERSION_20241022),
        ];

        foreach ($models as $model) {
            $this->assertEquals(
                'text_editor_20250429',
                AnthropicToolTypeResolver::getTextEditorType($model),
                'Failed for model: ' . $model->getCode()
            );
        }
    }
}
