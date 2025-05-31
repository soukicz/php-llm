<?php

namespace Soukicz\Llm\Tool\TextEditor;

use InvalidArgumentException;
use RuntimeException;

class TextEditorStorageFilesystem implements TextEditorStorage {
    private readonly string $baseDir;

    public function __construct(string $baseDir) {
        if (!str_starts_with($baseDir, '/')) {
            throw new InvalidArgumentException('Base directory must be an absolute path');
        }

        // Resolve real path to prevent symlink attacks on baseDir itself
        $realBaseDir = realpath($baseDir);
        if ($realBaseDir === false) {
            throw new InvalidArgumentException('Base directory does not exist or is not accessible');
        }

        $this->baseDir = $realBaseDir;
    }

    private function resolvePath(string $path): string {
        $path = ltrim($path, '/');

        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Path contains null bytes');
        }

        // Block dangerous sequences
        if (str_starts_with($path, './') || $path === '.' || str_contains($path, '../')) {
            throw new InvalidArgumentException('Path contains dangerous sequence');
        }

        $fullPath = $this->baseDir . '/' . $path;

        // For existing paths, resolve to real path
        $realPath = realpath($fullPath);

        if ($realPath !== false) {
            // Check if resolved path is within base directory
            if (!str_starts_with($realPath . '/', $this->baseDir . '/')) {
                throw new InvalidArgumentException('Path is not in base directory or contains invalid characters');
            }

            return $realPath;
        }

        // For non-existent files, check parent directory hierarchy
        $currentPath = dirname($fullPath);

        // Walk up the directory tree until we find an existing directory
        while ($currentPath !== '/' && $currentPath !== $this->baseDir) {
            $realCurrentPath = realpath($currentPath);
            if ($realCurrentPath !== false) {
                // Found existing directory, check if it's within base directory
                if (!str_starts_with($realCurrentPath . '/', $this->baseDir . '/')) {
                    throw new InvalidArgumentException('Path is not in base directory or contains invalid characters');
                }
                break;
            }
            $currentPath = dirname($currentPath);
        }

        // If we walked up to root or base dir without finding existing path, it's invalid
        if ($currentPath === '/' || ($currentPath !== $this->baseDir && realpath($currentPath) === false)) {
            throw new InvalidArgumentException('Path is not in base directory or contains invalid characters');
        }

        return $fullPath; // Return original path for file creation
    }

    public function getFileContent(string $path): string {
        $resolvedPath = $this->resolvePath($path);

        if (!file_exists($resolvedPath)) {
            throw new RuntimeException('File not found');
        }

        if ($this->isDirectoryInternal($resolvedPath)) {
            throw new RuntimeException('Path is a directory, not a file');
        }

        $content = file_get_contents($resolvedPath);
        if ($content === false) {
            throw new RuntimeException('Unable to read file');
        }

        return $content;
    }

    public function setFileContent(string $path, string $content): void {
        $resolvedPath = $this->resolvePath($path);

        if (!file_exists($resolvedPath)) {
            throw new RuntimeException('File not found');
        }

        if ($this->isDirectoryInternal($resolvedPath)) {
            throw new RuntimeException('Path is a directory, not a file');
        }

        if (file_put_contents($resolvedPath, $content) === false) {
            throw new RuntimeException('Unable to write to file');
        }
    }

    public function createFile(string $path, string $content): void {
        $resolvedPath = $this->resolvePath($path);

        if (file_exists($resolvedPath)) {
            throw new RuntimeException('File already exists');
        }

        // Ensure the directory exists
        $directory = dirname($resolvedPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory');
        }

        if (file_put_contents($resolvedPath, $content) === false) {
            throw new RuntimeException('Unable to create file');
        }
    }

    public function deleteFile(string $path): void {
        $resolvedPath = $this->resolvePath($path);

        if (!file_exists($resolvedPath)) {
            throw new RuntimeException('File not found');
        }

        if ($this->isDirectoryInternal($resolvedPath)) {
            throw new RuntimeException('Path is a directory, not a file');
        }

        if (!unlink($resolvedPath)) {
            throw new RuntimeException('Unable to delete file');
        }
    }

    public function renameFile(string $oldPath, string $newPath): void {
        $resolvedOldPath = $this->resolvePath($oldPath);
        $resolvedNewPath = $this->resolvePath($newPath);

        if (!file_exists($resolvedOldPath)) {
            throw new RuntimeException('Source file not found');
        }

        if ($this->isDirectoryInternal($resolvedOldPath)) {
            throw new RuntimeException('Source path is a directory, not a file');
        }

        if (file_exists($resolvedNewPath)) {
            throw new RuntimeException('Destination file already exists');
        }

        // Ensure the destination directory exists
        $directory = dirname($resolvedNewPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create destination directory');
        }

        if (!rename($resolvedOldPath, $resolvedNewPath)) {
            throw new RuntimeException('Unable to rename file');
        }
    }

    /**
     * @return string[]
     */
    public function getDirectoryContent(string $path): array {
        $resolvedPath = $this->resolvePath($path);

        if (!file_exists($resolvedPath)) {
            throw new RuntimeException('Directory not found');
        }

        if (!$this->isDirectoryInternal($resolvedPath)) {
            throw new RuntimeException('Path is not a directory');
        }

        $contents = scandir($resolvedPath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read directory');
        }

        // Remove . and .. entries and return just the names
        return array_values(array_filter($contents, static fn($item) => $item !== '.' && $item !== '..'));
    }

    public function createDirectory(string $path): void {
        $resolvedPath = $this->resolvePath($path);

        if (file_exists($resolvedPath)) {
            throw new RuntimeException('Directory already exists');
        }

        if (!mkdir($resolvedPath, 0755, true) && !is_dir($resolvedPath)) {
            throw new RuntimeException('Unable to create directory');
        }
    }

    public function deleteDirectory(string $path): void {
        $resolvedPath = $this->resolvePath($path);

        if (!file_exists($resolvedPath)) {
            throw new RuntimeException('Directory not found');
        }

        if (!$this->isDirectoryInternal($resolvedPath)) {
            throw new RuntimeException('Path is not a directory');
        }

        // Check if directory is empty
        $contents = scandir($resolvedPath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read directory');
        }

        $filteredContents = array_filter($contents, static fn($item) => $item !== '.' && $item !== '..');
        if (count($filteredContents) > 0) {
            throw new RuntimeException('Directory is not empty');
        }

        if (!rmdir($resolvedPath)) {
            throw new RuntimeException('Unable to delete directory');
        }
    }

    public function renameDirectory(string $oldPath, string $newPath): void {
        $resolvedOldPath = $this->resolvePath($oldPath);
        $resolvedNewPath = $this->resolvePath($newPath);

        if (!file_exists($resolvedOldPath)) {
            throw new RuntimeException('Source directory not found');
        }

        if (!$this->isDirectoryInternal($resolvedOldPath)) {
            throw new RuntimeException('Source path is not a directory');
        }

        if (file_exists($resolvedNewPath)) {
            throw new RuntimeException('Destination already exists');
        }

        // Ensure the parent directory of destination exists
        $parentDir = dirname($resolvedNewPath);
        if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
            throw new RuntimeException('Unable to create parent directory');
        }

        if (!rename($resolvedOldPath, $resolvedNewPath)) {
            throw new RuntimeException('Unable to rename directory');
        }
    }

    private function isDirectoryInternal(string $resolvedPath): bool {
        return is_dir($resolvedPath);
    }

    private function isFileInternal(string $resolvedPath): bool {
        return is_file($resolvedPath);
    }

    public function isDirectory(string $path): bool {
        try {
            $resolvedPath = $this->resolvePath($path);

            return file_exists($resolvedPath) && $this->isDirectoryInternal($resolvedPath);
        } catch (InvalidArgumentException $e) {
            // Path is invalid (e.g., outside base dir)
            return false;
        }
    }

    public function isFile(string $path): bool {
        try {
            $resolvedPath = $this->resolvePath($path);

            return file_exists($resolvedPath) && $this->isFileInternal($resolvedPath);
        } catch (InvalidArgumentException $e) {
            // Path is invalid (e.g., outside base dir)
            return false;
        }
    }
}
