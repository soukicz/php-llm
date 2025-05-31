<?php

namespace Soukicz\Llm\Tool\TextEditor;

use InvalidArgumentException;
use RuntimeException;

class TextEditorStorageMemory implements TextEditorStorage {
    /**
     * @var array<string, string> Files storage (path => content)
     */
    private array $files = [];

    /**
     * @var array<string, bool> Directories storage (path => true)
     */
    private array $directories = [];

    public function __construct() {
        // Root directory always exists
        $this->directories[''] = true;
    }

    private function normalizePath(string $path): string {
        // Remove leading and trailing slashes
        $path = trim($path, '/');

        // Check for null bytes
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Path contains null bytes');
        }

        // Block dangerous sequences
        if (str_starts_with($path, './') || $path === '.' || str_contains($path, '../')) {
            throw new InvalidArgumentException('Path contains dangerous sequence');
        }

        return $path;
    }

    private function createParentDirectories(string $path): void {
        if ($path === '') {
            return;
        }

        $parts = explode('/', $path);
        $currentPath = '';

        // Create all parent directories except the last part
        for ($i = 0; $i < count($parts) - 1; $i++) {
            if ($i > 0) {
                $currentPath .= '/';
            }
            $currentPath .= $parts[$i];

            if (!isset($this->directories[$currentPath])) {
                // Check if a file exists at this path
                if (isset($this->files[$currentPath])) {
                    throw new RuntimeException('Cannot create directory: a file exists at path ' . $currentPath);
                }
                $this->directories[$currentPath] = true;
            }
        }
    }

    public function getFileContent(string $path): string {
        $path = $this->normalizePath($path);

        if (!isset($this->files[$path])) {
            throw new RuntimeException('File not found');
        }

        return $this->files[$path];
    }

    public function setFileContent(string $path, string $content): void {
        $path = $this->normalizePath($path);

        if (!isset($this->files[$path])) {
            throw new RuntimeException('File not found');
        }

        $this->files[$path] = $content;
    }

    public function createFile(string $path, string $content): void {
        $path = $this->normalizePath($path);

        if (isset($this->files[$path])) {
            throw new RuntimeException('File already exists');
        }

        if (isset($this->directories[$path])) {
            throw new RuntimeException('A directory exists at this path');
        }

        // Create parent directories if needed
        $this->createParentDirectories($path);

        $this->files[$path] = $content;
    }

    public function deleteFile(string $path): void {
        $path = $this->normalizePath($path);

        if (!isset($this->files[$path])) {
            throw new RuntimeException('File not found');
        }

        unset($this->files[$path]);
    }

    public function renameFile(string $oldPath, string $newPath): void {
        $oldPath = $this->normalizePath($oldPath);
        $newPath = $this->normalizePath($newPath);

        if (!isset($this->files[$oldPath])) {
            throw new RuntimeException('Source file not found');
        }

        if (isset($this->files[$newPath])) {
            throw new RuntimeException('Destination file already exists');
        }

        if (isset($this->directories[$newPath])) {
            throw new RuntimeException('A directory exists at the destination path');
        }

        // Create parent directories for destination if needed
        $this->createParentDirectories($newPath);

        // Move the file
        $this->files[$newPath] = $this->files[$oldPath];
        unset($this->files[$oldPath]);
    }

    /**
     * @return string[]
     */
    public function getDirectoryContent(string $path): array {
        $path = $this->normalizePath($path);

        if (!isset($this->directories[$path])) {
            throw new RuntimeException('Directory not found');
        }

        $contents = [];
        $pathPrefix = $path === '' ? '' : $path . '/';

        // Find all direct children (files and directories)
        foreach ($this->files as $filePath => $content) {
            if ($path === '' ? !str_contains($filePath, '/') : (str_starts_with($filePath, $pathPrefix) && !str_contains(substr($filePath, strlen($pathPrefix)), '/'))) {
                $contents[] = $path === '' ? $filePath : substr($filePath, strlen($pathPrefix));
            }
        }

        foreach ($this->directories as $dirPath => $true) {
            if ($dirPath === $path) {
                continue; // Skip self
            }

            if ($path === '' ? !str_contains($dirPath, '/') : (str_starts_with($dirPath, $pathPrefix) && !str_contains(substr($dirPath, strlen($pathPrefix)), '/'))) {
                $contents[] = $path === '' ? $dirPath : substr($dirPath, strlen($pathPrefix));
            }
        }

        sort($contents);

        return $contents;
    }

    public function createDirectory(string $path): void {
        $path = $this->normalizePath($path);

        if ($path === '') {
            throw new RuntimeException('Cannot create root directory');
        }

        if (isset($this->directories[$path])) {
            throw new RuntimeException('Directory already exists');
        }

        if (isset($this->files[$path])) {
            throw new RuntimeException('A file exists at this path');
        }

        // Create parent directories if needed
        $this->createParentDirectories($path);

        $this->directories[$path] = true;
    }

    public function deleteDirectory(string $path): void {
        $path = $this->normalizePath($path);

        if ($path === '') {
            throw new RuntimeException('Cannot delete root directory');
        }

        if (!isset($this->directories[$path])) {
            throw new RuntimeException('Directory not found');
        }

        // Check if directory is empty
        $pathPrefix = $path . '/';

        foreach ($this->files as $filePath => $content) {
            if (str_starts_with($filePath, $pathPrefix)) {
                throw new RuntimeException('Directory is not empty');
            }
        }

        foreach ($this->directories as $dirPath => $true) {
            if ($dirPath !== $path && str_starts_with($dirPath, $pathPrefix)) {
                throw new RuntimeException('Directory is not empty');
            }
        }

        unset($this->directories[$path]);
    }

    public function renameDirectory(string $oldPath, string $newPath): void {
        $oldPath = $this->normalizePath($oldPath);
        $newPath = $this->normalizePath($newPath);

        if ($oldPath === '') {
            throw new RuntimeException('Cannot rename root directory');
        }

        if (!isset($this->directories[$oldPath])) {
            throw new RuntimeException('Source directory not found');
        }

        if (isset($this->directories[$newPath]) || isset($this->files[$newPath])) {
            throw new RuntimeException('Destination already exists');
        }

        // Create parent directories for destination if needed
        $this->createParentDirectories($newPath);

        // Move the directory and all its contents
        $oldPathPrefix = $oldPath . '/';
        $newPathPrefix = $newPath . '/';

        // Collect all paths to move (to avoid modifying while iterating)
        $filesToMove = [];
        $dirsToMove = [];

        foreach ($this->files as $filePath => $content) {
            if ($filePath === $oldPath || str_starts_with($filePath, $oldPathPrefix)) {
                $filesToMove[$filePath] = $content;
            }
        }

        foreach ($this->directories as $dirPath => $true) {
            if ($dirPath === $oldPath || str_starts_with($dirPath, $oldPathPrefix)) {
                $dirsToMove[] = $dirPath;
            }
        }

        // Move files
        foreach ($filesToMove as $filePath => $content) {
            $relativePath = $filePath === $oldPath ? '' : substr($filePath, strlen($oldPathPrefix));
            $newFilePath = $newPath . ($relativePath === '' ? '' : '/' . $relativePath);
            $this->files[$newFilePath] = $content;
            unset($this->files[$filePath]);
        }

        // Move directories
        foreach ($dirsToMove as $dirPath) {
            if ($dirPath === $oldPath) {
                $this->directories[$newPath] = true;
            } else {
                $relativePath = substr($dirPath, strlen($oldPathPrefix));
                $this->directories[$newPathPrefix . $relativePath] = true;
            }
            unset($this->directories[$dirPath]);
        }
    }

    public function isDirectory(string $path): bool {
        try {
            $path = $this->normalizePath($path);

            return isset($this->directories[$path]);
        } catch (InvalidArgumentException $e) {
            // Invalid path
            return false;
        }
    }

    public function isFile(string $path): bool {
        try {
            $path = $this->normalizePath($path);

            return isset($this->files[$path]);
        } catch (InvalidArgumentException $e) {
            // Invalid path
            return false;
        }
    }
}
