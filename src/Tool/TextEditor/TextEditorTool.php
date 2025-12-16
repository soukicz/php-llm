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
    private const MAX_RESPONSE_LEN = 16000;
    private const TRUNCATED_MESSAGE = "\n... [Response truncated due to length. Use view_range to see specific sections.]";

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
            return $this->viewFile($input['path'], $input['view_range'] ?? null);
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
                    'description' => 'The text to replace (for str_replace command) - must appear exactly once in the file',
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

    /**
     * Truncate content if it exceeds MAX_RESPONSE_LEN.
     */
    private function maybeTruncate(string $content, ?int $truncateAfter = null): string {
        $truncateAfter = $truncateAfter ?? self::MAX_RESPONSE_LEN;

        if ($truncateAfter === 0 || strlen($content) <= $truncateAfter) {
            return $content;
        }

        return substr($content, 0, $truncateAfter) . self::TRUNCATED_MESSAGE;
    }

    /**
     * Generate output for the CLI based on the content of a file.
     * Matches Anthropic reference implementation format.
     */
    private function makeOutput(string $fileContent, string $fileDescriptor, int $initLine = 1): string {
        $fileContent = $this->maybeTruncate($fileContent);

        $lines = explode("\n", $fileContent);
        $numberedLines = [];
        foreach ($lines as $i => $line) {
            $lineNumber = $i + $initLine;
            // Format: 6-char right-aligned number + tab + content (cat -n style)
            $numberedLines[] = sprintf("%6d\t%s", $lineNumber, $line);
        }

        return "Here's the result of running `cat -n` on " . $fileDescriptor . ":\n"
            . implode("\n", $numberedLines) . "\n";
    }

    /**
     * @param int[]|null $viewRange Optional [start_line, end_line] range (1-indexed, -1 for end)
     */
    protected function viewFile(string $path, ?array $viewRange): LLMMessageContents {
        try {
            // Check if it's a directory
            if ($this->storage->isDirectory($path)) {
                if ($viewRange !== null) {
                    return LLMMessageContents::fromErrorString(
                        "The `view_range` parameter is not allowed when `path` points to a directory."
                    );
                }

                $contents = $this->storage->getDirectoryContent($path);

                $output = "Here's the files and directories in $path, excluding hidden items:\n";
                foreach ($contents as $item) {
                    // Skip hidden files/directories (starting with .)
                    if (str_starts_with($item, '.')) {
                        continue;
                    }
                    $output .= "$path/$item\n";
                }

                return LLMMessageContents::fromString($output);
            }

            // Handle file viewing
            $content = $this->storage->getFileContent($path);

            // Split content into lines
            $lines = explode("\n", $content);
            $totalLines = count($lines);
            $initLine = 1;

            // Handle view_range validation
            if ($viewRange !== null) {
                if (count($viewRange) !== 2) {
                    return LLMMessageContents::fromErrorString(
                        "Invalid `view_range`. It should be a list of two integers."
                    );
                }

                $initLine = $viewRange[0];
                $finalLine = $viewRange[1];

                if ($initLine < 1 || $initLine > $totalLines) {
                    return LLMMessageContents::fromErrorString(
                        "Invalid `view_range`: [" . implode(', ', $viewRange) . "]. Its first element `$initLine` should be within the range of lines of the file: [1, $totalLines]"
                    );
                }

                if ($finalLine !== -1 && $finalLine > $totalLines) {
                    return LLMMessageContents::fromErrorString(
                        "Invalid `view_range`: [" . implode(', ', $viewRange) . "]. Its second element `$finalLine` should be smaller than the number of lines in the file: `$totalLines`"
                    );
                }

                if ($finalLine !== -1 && $finalLine < $initLine) {
                    return LLMMessageContents::fromErrorString(
                        "Invalid `view_range`: [" . implode(', ', $viewRange) . "]. Its second element `$finalLine` should be larger or equal than its first `$initLine`"
                    );
                }

                // Extract the requested range
                if ($finalLine === -1) {
                    $content = implode("\n", array_slice($lines, $initLine - 1));
                } else {
                    $content = implode("\n", array_slice($lines, $initLine - 1, $finalLine - $initLine + 1));
                }
            }

            return LLMMessageContents::fromString($this->makeOutput($content, $path, $initLine));
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
                // Find line numbers where old_str appears (matches reference implementation)
                $contentLines = explode("\n", $content);
                $lineNumbers = [];
                foreach ($contentLines as $idx => $line) {
                    if (str_contains($line, $oldString)) {
                        $lineNumbers[] = $idx + 1;
                    }
                }

                return LLMMessageContents::fromErrorString(
                    "No replacement was performed. Multiple occurrences of old_str `$oldString` in lines [" . implode(', ', $lineNumbers) . "]. Please ensure it is unique"
                );
            }

            // Perform replacement
            $newContent = str_replace($oldString, $newString, $content);

            $this->storage->setFileContent($path, $newContent);

            // Calculate snippet range (matches reference implementation)
            $parts = explode($oldString, $content);
            $replacementLine = substr_count($parts[0], "\n");
            $startLine = max(0, $replacementLine - self::SNIPPET_LINES);
            $endLine = $replacementLine + self::SNIPPET_LINES + substr_count($newString, "\n");

            $newContentLines = explode("\n", $newContent);
            $snippet = implode("\n", array_slice($newContentLines, $startLine, $endLine - $startLine + 1));

            $successMsg = "The file $path has been edited. ";
            $successMsg .= $this->makeOutput($snippet, "a snippet of $path", $startLine + 1);
            $successMsg .= "Review the changes and make sure they are as expected. Edit the file again if necessary.";

            return LLMMessageContents::fromString($successMsg);
        } catch (RuntimeException|InvalidArgumentException $e) {
            $message = $e->getMessage();
            if ($message === 'File not found') {
                return LLMMessageContents::fromErrorString("The path $path does not exist. Please provide a valid path.");
            }

            return LLMMessageContents::fromErrorString('Error: ' . $message);
        }
    }

    protected function insertToFile(string $path, string $newString, int $insertLine): LLMMessageContents {
        try {
            $content = $this->storage->getFileContent($path);

            // Split content into lines
            $lines = explode("\n", $content);
            $totalLines = count($lines);

            // Validate line number (0-indexed: 0 means insert at beginning, totalLines means at end)
            if ($insertLine < 0 || $insertLine > $totalLines) {
                return LLMMessageContents::fromErrorString(
                    "Invalid `insert_line` parameter: $insertLine. It should be within the range of lines of the file: [0, $totalLines]"
                );
            }

            // Calculate snippet (matches reference implementation)
            $newStrLines = explode("\n", $newString);
            $snippetLines = array_merge(
                array_slice($lines, max(0, $insertLine - self::SNIPPET_LINES), min($insertLine, self::SNIPPET_LINES)),
                $newStrLines,
                array_slice($lines, $insertLine, self::SNIPPET_LINES)
            );

            // Insert the new string
            $newFileLines = array_merge(
                array_slice($lines, 0, $insertLine),
                $newStrLines,
                array_slice($lines, $insertLine)
            );

            $newContent = implode("\n", $newFileLines);
            $snippet = implode("\n", $snippetLines);

            $this->storage->setFileContent($path, $newContent);

            $successMsg = "The file $path has been edited. ";
            $successMsg .= $this->makeOutput(
                $snippet,
                "a snippet of the edited file",
                max(1, $insertLine - self::SNIPPET_LINES + 1)
            );
            $successMsg .= "Review the changes and make sure they are as expected (correct indentation, no duplicate lines, etc). Edit the file again if necessary.";

            return LLMMessageContents::fromString($successMsg);
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
}
