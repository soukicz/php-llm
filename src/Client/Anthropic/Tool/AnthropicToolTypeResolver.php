<?php

namespace Soukicz\Llm\Client\Anthropic\Tool;

use Soukicz\Llm\Client\ModelInterface;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude37Sonnet;

/**
 * Resolves Anthropic native tool types based on the model being used.
 */
class AnthropicToolTypeResolver {
    /**
     * Determines the correct text_editor tool type based on the model.
     *
     * Claude 3.7 Sonnet uses text_editor_20250124; every other model, including
     * unknown and future ones, gets text_editor_20250728 so newly added models
     * work without touching this resolver. Retired Claude 3.5 models have no
     * supported version anymore (text_editor_20241022 is retired) and fall into
     * the default.
     *
     * The tool type and name must always move together (text_editor_20250124
     * pairs only with str_replace_editor) — resolve both here.
     *
     * @see https://platform.claude.com/docs/en/agents-and-tools/tool-use/text-editor-tool
     */
    public static function getTextEditorType(ModelInterface $model): string {
        // Claude 3.7 Sonnet uses the dedicated version with undo_edit support
        if ($model instanceof AnthropicClaude37Sonnet) {
            return 'text_editor_20250124';
        }

        return 'text_editor_20250728';
    }

    /**
     * Determines the tool name paired with the text_editor type for the model.
     */
    public static function getTextEditorName(ModelInterface $model): string {
        if ($model instanceof AnthropicClaude37Sonnet) {
            return 'str_replace_editor';
        }

        return 'str_replace_based_edit_tool';
    }
}
