<?php

namespace Soukicz\Llm\Client\Anthropic\Tool;

use Soukicz\Llm\Tool\ToolResponse;

class AnthropicTextEditorTool extends AbstractAnthropicTextEditorTool {

    private readonly string $baseDir;

    public function __construct(string $name, string $baseDir) {
        if (!str_starts_with($baseDir, '/')) {
            throw new \InvalidArgumentException('Base directory must be an absolute path');
        }

        // Resolve real path to prevent symlink attacks on baseDir itself
        $realBaseDir = realpath($baseDir);
        if ($realBaseDir === false) {
            throw new \InvalidArgumentException('Base directory does not exist or is not accessible');
        }

        $this->baseDir = $realBaseDir;

        parent::__construct($name);
    }

    private function getPath(string $path): string {
        // Basic validation
        if ($path === '') {
            throw new \InvalidArgumentException('Path is not in base directory or contains invalid characters');
        }

        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Path contains null bytes');
        }

        // Block dangerous sequences
        if (str_starts_with($path, './') || $path === '.') {
            throw new \InvalidArgumentException('Path starts with dangerous sequence');
        }

        // Convert relative paths to absolute
        if (!str_starts_with($path, '/')) {
            $path = $this->baseDir . '/' . $path;
        }

        // Resolve the real path to handle symlinks and .. traversal
        $realPath = realpath($path);

        // For non-existent files, check parent directory
        if ($realPath === false) {
            $parentDir = realpath(dirname($path));
            if ($parentDir === false || !str_starts_with($parentDir . '/', $this->baseDir . '/')) {
                throw new \InvalidArgumentException('Path is not in base directory or contains invalid characters');
            }

            return $path; // Return original path for file creation
        }

        // Check if resolved path is within base directory
        if (!str_starts_with($realPath . '/', $this->baseDir . '/')) {
            throw new \InvalidArgumentException('Path is not in base directory or contains invalid characters');
        }

        return $realPath;
    }

    protected function viewFile(string $path, ?int $fromLine, ?int $toLine): ToolResponse {
        $path = $this->getPath($path);

        // Check if it's a directory
        if (is_dir($path)) {
            $contents = scandir($path);
            if ($contents === false) {
                return new ToolResponse('Error: Unable to read directory');
            }

            // Remove . and .. entries
            $contents = array_filter($contents, static fn($item) => $item !== '.' && $item !== '..');

            $output = "Directory contents of $path:\n";
            foreach ($contents as $item) {
                $fullPath = $path . DIRECTORY_SEPARATOR . $item;
                $type = is_dir($fullPath) ? 'DIR' : 'FILE';
                $output .= "$type: $item\n";
            }

            return new ToolResponse($output);
        }

        // Handle file viewing
        if (!file_exists($path)) {
            return new ToolResponse('Error: File not found');
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return new ToolResponse('Error: Unable to read file');
        }

        // If no line range specified, return entire file
        if ($fromLine === null && $toLine === null) {
            return new ToolResponse($content);
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

        return new ToolResponse(implode("\n", $selectedLines));
    }

    protected function replaceInFile(string $path, string $oldString, string $newString): ToolResponse {
        $path = $this->getPath($path);

        if (!file_exists($path)) {
            return new ToolResponse('Error: File not found');
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return new ToolResponse('Error: Unable to read file');
        }

        // Check for exact matches
        $matchCount = substr_count($content, $oldString);

        if ($matchCount === 0) {
            return new ToolResponse('Error: No match found for replacement. Please check your text and try again.');
        }

        if ($matchCount > 1) {
            return new ToolResponse("Error: Found $matchCount matches for replacement text. Please provide more context to make a unique match.");
        }

        // Perform the replacement
        $newContent = str_replace($oldString, $newString, $content);

        if (file_put_contents($path, $newContent) === false) {
            return new ToolResponse('Error: Unable to write to file');
        }

        return new ToolResponse('Successfully replaced text in file');
    }

    protected function insertToFile(string $path, string $newString, int $afterLine): ToolResponse {
        $path = $this->getPath($path);

        if (!file_exists($path)) {
            return new ToolResponse('Error: File not found');
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return new ToolResponse('Error: Unable to read file');
        }

        // Split content into lines
        $lines = explode("\n", $content);
        $totalLines = count($lines);

        // Validate line number (1-indexed in the API, but we need 0-indexed for array operations)
        if ($afterLine < 0 || $afterLine > $totalLines) {
            return new ToolResponse("Error: Line number $afterLine is out of range. File has $totalLines lines.");
        }

        // Insert the new string after the specified line
        // If afterLine is 0, insert at the beginning
        // If afterLine equals totalLines, insert at the end
        array_splice($lines, $afterLine, 0, $newString);

        $newContent = implode("\n", $lines);

        if (file_put_contents($path, $newContent) === false) {
            return new ToolResponse('Error: Unable to write to file');
        }

        return new ToolResponse("Successfully inserted text after line $afterLine");
    }

    protected function createFile(string $path, string $content): ToolResponse {
        $path = $this->getPath($path);

        // Check if file already exists
        if (file_exists($path)) {
            return new ToolResponse('Error: File already exists');
        }

        // Ensure the directory exists
        $directory = dirname($path);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                return new ToolResponse('Error: Unable to create directory');
            }
        }

        // Create the file
        if (file_put_contents($path, $content) === false) {
            return new ToolResponse('Error: Unable to create file');
        }

        return new ToolResponse("Successfully created file: $path");
    }

}
