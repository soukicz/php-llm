<?php

namespace Soukicz\Llm\Tool\TextEditor;

use InvalidArgumentException;
use RuntimeException;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude37Sonnet;
use Soukicz\Llm\Client\Anthropic\Tool\AnthropicNativeTool;
use Soukicz\Llm\Client\Anthropic\Tool\AnthropicToolTypeResolver;
use Soukicz\Llm\Client\ModelInterface;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Tool\ToolDefinition;

class TextEditorTool implements AnthropicNativeTool, ToolDefinition {
    private const SNIPPET_LINES = 4;

    private readonly TextEditorStorage $storage;

    public function __construct(TextEditorStorage $storage) {
        $this->storage = $storage;
    }

    public function getName(): string {
        return 'str_replace_based_edit_tool';
    }

    public function getAnthropicName(ModelInterface $model): string {
        // Claude 3.7 Sonnet uses str_replace_editor, Claude 4+ uses str_replace_based_edit_tool
        if ($model instanceof AnthropicClaude37Sonnet) {
            return 'str_replace_editor';
        }

        return 'str_replace_based_edit_tool';
    }

    public function getAnthropicType(ModelInterface $model): string {
        return AnthropicToolTypeResolver::getTextEditorType($model);
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

        return LLMMessageContents::fromErrorString('ERROR: Unknown command: ' . $input['command']);
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

            // Split content into lines
            $lines = explode("\n", $content);
            $totalLines = count($lines);

            // Determine range
            $startIndex = $fromLine ?? 0;
            $endIndex = $toLine ?? $totalLines - 1;

            // Ensure indices are within bounds
            $startIndex = max(0, min($startIndex, $totalLines - 1));
            $endIndex = max($startIndex, min($endIndex, $totalLines - 1));

            // Build output with line numbers in cat -n format (6-char right-aligned + tab)
            $numberedLines = [];
            for ($i = $startIndex; $i <= $endIndex; $i++) {
                $lineNumber = $i + 1; // 1-indexed for display
                $numberedLines[] = sprintf("%6d\t%s", $lineNumber, $lines[$i]);
            }

            // Include prefix to indicate line numbers are present (matches Anthropic reference implementation)
            $output = "Here's the result of running `cat -n` on $path:\n" . implode("\n", $numberedLines) . "\n";

            return LLMMessageContents::fromString($output);
        } catch (RuntimeException|InvalidArgumentException $e) {
            $message = $e->getMessage();
            if ($message === 'File not found' || $message === 'Directory not found') {
                return LLMMessageContents::fromErrorString("The path $path does not exist. Please provide a valid path.");
            }

            return LLMMessageContents::fromErrorString('Error: ' . $message);
        }
    }

    protected function replaceInFile(string $path, string $oldString, string $newString): LLMMessageContents {
        try {
            $content = $this->storage->getFileContent($path);

            // Check for exact matches
            $matchCount = substr_count($content, $oldString);

            if ($matchCount === 0) {
                return LLMMessageContents::fromErrorString(
                    "No replacement was performed, old_str `$oldString` did not appear verbatim in $path."
                );
            }

            if ($matchCount > 1) {
                $lineNumbers = $this->findMatchLineNumbers($content, $oldString);
                $linesStr = implode(', ', $lineNumbers);

                return LLMMessageContents::fromErrorString(
                    "No replacement was performed. Multiple occurrences of old_str `$oldString` in lines $linesStr. Please ensure it is unique."
                );
            }

            $position = strpos($content, $oldString);
            $newContent = substr_replace($content, $newString, $position, strlen($oldString));

            $this->storage->setFileContent($path, $newContent);

            // Find the line number where replacement occurred (1-indexed)
            $replacementLine = substr_count(substr($content, 0, $position), "\n") + 1;
            $snippet = $this->makeSnippet($newContent, $replacementLine);

            return LLMMessageContents::fromString(
                "The file $path has been edited. " . $snippet
                . "Review the changes and make sure they are as expected. Edit the file again if necessary."
            );
        } catch (RuntimeException|InvalidArgumentException $e) {
            $message = $e->getMessage();
            if ($message === 'File not found') {
                return LLMMessageContents::fromErrorString("The path $path does not exist. Please provide a valid path.");
            }

            return LLMMessageContents::fromErrorString('Error: ' . $message);
        }
    }

    protected function insertToFile(string $path, string $newString, int $afterLine): LLMMessageContents {
        try {
            $content = $this->storage->getFileContent($path);

            // Split content into lines
            $lines = explode("\n", $content);
            $totalLines = count($lines);

            // Validate line number (0-indexed: 0 means insert at beginning, totalLines means at end)
            if ($afterLine < 0 || $afterLine > $totalLines) {
                return LLMMessageContents::fromErrorString("Error: Line number $afterLine is out of range. File has $totalLines lines.");
            }

            // Insert the new string after the specified line
            // If afterLine is 0, insert at the beginning
            // If afterLine equals totalLines, insert at the end
            array_splice($lines, $afterLine, 0, $newString);

            $newContent = implode("\n", $lines);

            $this->storage->setFileContent($path, $newContent);

            // The inserted line is at position afterLine + 1 (1-indexed)
            $insertedLine = $afterLine + 1;
            $snippet = $this->makeSnippet($newContent, $insertedLine);

            return LLMMessageContents::fromString(
                "The file $path has been edited. " . $snippet
                . "Review the changes and make sure they are as expected (correct indentation, no duplicate lines, etc). Edit the file again if necessary."
            );
        } catch (RuntimeException|InvalidArgumentException $e) {
            $message = $e->getMessage();
            if ($message === 'File not found') {
                return LLMMessageContents::fromErrorString("The path $path does not exist. Please provide a valid path.");
            }

            return LLMMessageContents::fromErrorString('Error: ' . $message);
        }
    }

    protected function createFile(string $path, string $content): LLMMessageContents {
        try {
            $this->storage->createFile($path, $content);

            return LLMMessageContents::fromString("File created successfully at: $path");
        } catch (RuntimeException|InvalidArgumentException $e) {
            $message = $e->getMessage();
            if ($message === 'File already exists') {
                return LLMMessageContents::fromErrorString("File already exists at: $path. Cannot overwrite files using command `create`.");
            }

            return LLMMessageContents::fromErrorString('Error: ' . $message);
        }
    }

    /**
     * Generate a context snippet around an edited area.
     * Shows SNIPPET_LINES lines before and after the edit location.
     */
    private function makeSnippet(string $content, int $editLine): string {
        $lines = explode("\n", $content);
        $totalLines = count($lines);

        // Calculate range (0-indexed internally, but editLine is 1-indexed)
        $editIndex = $editLine - 1;
        $startIndex = max(0, $editIndex - self::SNIPPET_LINES);
        $endIndex = min($totalLines - 1, $editIndex + self::SNIPPET_LINES);

        $snippet = [];
        for ($i = $startIndex; $i <= $endIndex; $i++) {
            $lineNumber = $i + 1; // 1-indexed for display
            $snippet[] = sprintf("%6d\t%s", $lineNumber, $lines[$i]);
        }

        return "Here's the result of running `cat -n` on a snippet of the edited file:\n"
             . implode("\n", $snippet) . "\n";
    }

    /**
     * Find all line numbers where a string occurs in content.
     *
     * @return int[]
     */
    private function findMatchLineNumbers(string $content, string $needle): array {
        $lineNumbers = [];
        $offset = 0;

        while (($pos = strpos($content, $needle, $offset)) !== false) {
            // Count newlines before this position to get line number (1-indexed)
            $lineNumber = substr_count(substr($content, 0, $pos), "\n") + 1;
            $lineNumbers[] = $lineNumber;
            $offset = $pos + 1;
        }

        return $lineNumbers;
    }
}
