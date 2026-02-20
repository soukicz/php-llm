<?php

namespace Soukicz\Llm\Client\Anthropic\Tool;

use Soukicz\Llm\Client\ModelInterface;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude37Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude4Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude4Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude41Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Haiku;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Opus;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude46Sonnet;

/**
 * Resolves Anthropic native tool types based on the model being used.
 */
class AnthropicToolTypeResolver {
    /**
     * Determines the correct text_editor tool type based on the model.
     *
     * @see https://platform.claude.com/docs/en/agents-and-tools/tool-use/text-editor-tool
     */
    public static function getTextEditorType(ModelInterface $model): string {
        // Claude 4.x models use the latest text_editor version (July 2025 release)
        if (
            $model instanceof AnthropicClaude4Sonnet ||
            $model instanceof AnthropicClaude4Opus ||
            $model instanceof AnthropicClaude41Opus ||
            $model instanceof AnthropicClaude45Sonnet ||
            $model instanceof AnthropicClaude45Opus ||
            $model instanceof AnthropicClaude45Haiku ||
            $model instanceof AnthropicClaude46Opus ||
            $model instanceof AnthropicClaude46Sonnet
        ) {
            return 'text_editor_20250728';
        }

        // Claude 3.7 Sonnet uses dedicated version with undo_edit support
        if ($model instanceof AnthropicClaude37Sonnet) {
            return 'text_editor_20250124';
        }

        // Default for other models (Claude 3.5, etc.)
        // Note: text_editor_20241022 (original 3.5 version) is retired
        // Using text_editor_20250429 as stable fallback
        return 'text_editor_20250429';
    }
}
