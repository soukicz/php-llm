<?php

namespace Soukicz\Llm\Client\Anthropic\Tool;

use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\Llm\Tool\ToolDefinition;
use Soukicz\Llm\Tool\ToolResponse;

abstract class AbstractAnthropicTextEditorTool implements AnthropicNativeTool, ToolDefinition {
    public function __construct(protected readonly string $name) {
    }

    public function getName(): string {
        return $this->name;
    }

    public function getType(): string {
        return 'text_editor_20250429';
    }

    public function handle(array $input): ToolResponse|PromiseInterface {
        if ($input['command'] === 'view') {
            if (isset($input['view_range'])) {
                $fromLine = $input['view_range'][0] - 1;
            } else {
                $fromLine = 0;
            }
            if (isset($input['view_range'])) {
                $toLine = $input['view_range'][1];
                if ($toLine === -1) {
                    $toLine = null;
                } else {
                    $toLine = $toLine - 1;
                }
            } else {
                $toLine = null;
            }

            return $this->viewFile($input['path'], $fromLine, $toLine);
        }

        if ($input['command'] === 'str_replace') {
            return $this->replaceInFile($input['path'], $input['old_str'], $input['new_str']);
        }

        if ($input['command'] === 'insert') {
            return $this->insertToFile($input['path'], $input['new_str'], $input['insert_line']);
        }

        if ($input['command'] === 'create') {
            return $this->createFile($input['path'], $input['file_text']);
        }

        return new ToolResponse('ERROR: Unknown command: ' . $input['command']);
    }

    abstract protected function viewFile(string $path, ?int $fromLine, ?int $toLine): ToolResponse|PromiseInterface;

    abstract protected function replaceInFile(string $path, string $oldString, string $newString): ToolResponse|PromiseInterface;

    abstract protected function insertToFile(string $path, string $newString, int $afterLine): ToolResponse|PromiseInterface;

    abstract protected function createFile(string $path, string $content): ToolResponse|PromiseInterface;

    public function getDescription(): string {
        return "LLM can use this text editor tool to view and modify text files. It supports commands like view (examine file contents), str_replace (replace text), create (create new files), and insert (insert text at specific lines).";
    }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'enum' => ['view', 'str_replace', 'create', 'insert'],
                    'description' => 'The command to execute',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'The file or directory path',
                ],
                'view_range' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'minItems' => 2,
                    'maxItems' => 2,
                    'description' => 'Optional line range for view command [start_line, end_line]. Uses 1-based line numbering. End line of -1 means read to end of file.',
                ],
                'old_str' => [
                    'type' => 'string',
                    'description' => 'The text to replace (for str_replace command)',
                ],
                'new_str' => [
                    'type' => 'string',
                    'description' => 'The new text to insert (for str_replace and insert commands)',
                ],
                'file_text' => [
                    'type' => 'string',
                    'description' => 'The content for new file (for create command)',
                ],
                'insert_line' => [
                    'type' => 'integer',
                    'description' => 'The line number after which to insert text (for insert command). Uses 0-based indexing where 0 means insert at beginning of file.',
                ],
            ],
            'required' => ['command', 'path'],
        ];
    }
}
