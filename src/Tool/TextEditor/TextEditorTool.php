<?php

namespace Soukicz\Llm\Tool\TextEditor;

use InvalidArgumentException;
use RuntimeException;
use Soukicz\Llm\Client\Anthropic\Tool\AnthropicNativeTool;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Tool\ToolDefinition;

class TextEditorTool implements AnthropicNativeTool, ToolDefinition {
    private readonly TextEditorStorage $storage;

    public function __construct(TextEditorStorage $storage) {
        $this->storage = $storage;
    }

    public function getName(): string {
        return $this->getAnthropicName();
    }

    public function getAnthropicName(): string {
        return 'str_replace_based_edit_tool';
    }

    public function getAnthropicType(): string {
        return 'text_editor_20250429';
    }

    public function handle(array $input): LLMMessageContents {
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
                    $toLine--;
                }
            } else {
                $toLine = null;
            }

            return $this->viewFile($input['path'], $fromLine, $toLine);
        }

        if ($input['command'] === 'str_replace') {
            return $this->replaceInFile($input['path'], $input['old_str'] ?? '', $input['new_str'] ?? '');
        }

        if ($input['command'] === 'insert') {
            return $this->insertToFile($input['path'], $input['new_str'] ?? '', $input['insert_line'] ?? 0);
        }

        if ($input['command'] === 'create') {
            return $this->createFile($input['path'], $input['file_text'] ?? '');
        }

        return LLMMessageContents::fromString('ERROR: Unknown command: ' . $input['command']);
    }

    public function getDescription(): string {
        return "LLM can use this text editor tool to view and modify text files. It supports commands like view (examine file contents), str_replace (replace text), create (create new files), and insert (insert text at specific lines).";
    }

    /**
     * @return array<string, mixed>
     */
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
                    'description' => 'The text to replace (for str_replace command) - only first occurrence is replaced',
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

    protected function viewFile(string $path, ?int $fromLine, ?int $toLine): LLMMessageContents {
        try {
            // Check if it's a directory
            if ($this->storage->isDirectory($path)) {
                $contents = $this->storage->getDirectoryContent($path);

                $output = "Directory contents of $path:\n";
                foreach ($contents as $item) {
                    $fullPath = rtrim($path, '/') . '/' . $item;
                    if ($this->storage->isDirectory($fullPath)) {
                        $output .= "DIR: $item\n";
                    } elseif ($this->storage->isFile($fullPath)) {
                        $output .= "FILE: $item\n";
                    } else {
                        $output .= "UNKNOWN: $item\n";
                    }
                }

                return LLMMessageContents::fromString($output);
            }

            // Handle file viewing
            $content = $this->storage->getFileContent($path);

            // If no line range specified, return entire file
            if ($fromLine === null && $toLine === null) {
                return LLMMessageContents::fromString($content);
            }

            // Split content into lines for range viewing
            $lines = explode("\n", $content);
            $totalLines = count($lines);

            $startIndex = $fromLine ?? 0;
            $endIndex = $toLine ?? $totalLines - 1;

            // Ensure indices are within bounds
            $startIndex = max(0, min($startIndex, $totalLines - 1));
            $endIndex = max($startIndex, min($endIndex, $totalLines - 1));

            $selectedLines = array_slice($lines, $startIndex, $endIndex - $startIndex + 1);

            return LLMMessageContents::fromString(implode("\n", $selectedLines));
        } catch (RuntimeException|InvalidArgumentException $e) {
            return LLMMessageContents::fromErrorString('Error: ' . $e->getMessage());
        }
    }

    protected function replaceInFile(string $path, string $oldString, string $newString): LLMMessageContents {
        try {
            $content = $this->storage->getFileContent($path);

            // Check for exact matches
            $matchCount = substr_count($content, $oldString);

            if ($matchCount === 0) {
                return LLMMessageContents::fromErrorString('Error: No match found for replacement. Please check your text and try again.');
            }

            $position = strpos($content, $oldString);
            $newContent = substr_replace($content, $newString, $position, strlen($oldString));

            $this->storage->setFileContent($path, $newContent);

            return LLMMessageContents::fromString('Successfully replaced 1 occurrence');
        } catch (RuntimeException|InvalidArgumentException $e) {
            return LLMMessageContents::fromErrorString('Error: ' . $e->getMessage());
        }
    }

    protected function insertToFile(string $path, string $newString, int $afterLine): LLMMessageContents {
        try {
            $content = $this->storage->getFileContent($path);

            // Split content into lines
            $lines = explode("\n", $content);
            $totalLines = count($lines);

            // Validate line number (1-indexed in the API, but we need 0-indexed for array operations)
            if ($afterLine < 0 || $afterLine > $totalLines) {
                return LLMMessageContents::fromErrorString("Error: Line number $afterLine is out of range. File has $totalLines lines.");
            }

            // Insert the new string after the specified line
            // If afterLine is 0, insert at the beginning
            // If afterLine equals totalLines, insert at the end
            array_splice($lines, $afterLine, 0, $newString);

            $newContent = implode("\n", $lines);

            $this->storage->setFileContent($path, $newContent);

            return LLMMessageContents::fromString("Successfully inserted text after line $afterLine");
        } catch (RuntimeException|InvalidArgumentException $e) {
            return LLMMessageContents::fromErrorString('Error: ' . $e->getMessage());
        }
    }

    protected function createFile(string $path, string $content): LLMMessageContents {
        try {
            $this->storage->createFile($path, $content);

            return LLMMessageContents::fromString("Successfully created file: $path");
        } catch (RuntimeException|InvalidArgumentException $e) {
            return LLMMessageContents::fromErrorString('Error: ' . $e->getMessage());
        }
    }
}
