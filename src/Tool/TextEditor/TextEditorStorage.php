<?php

namespace Soukicz\Llm\Tool\TextEditor;

interface TextEditorStorage {
    public function getFileContent(string $path): string;

    public function setFileContent(string $path, string $content): void;

    public function createFile(string $path, string $content): void;

    public function deleteFile(string $path): void;

    public function renameFile(string $oldPath, string $newPath): void;

    /**
     * @return string[]
     */
    public function getDirectoryContent(string $path): array;

    public function createDirectory(string $path): void;

    public function deleteDirectory(string $path): void;

    public function renameDirectory(string $oldPath, string $newPath): void;

    public function isDirectory(string $path): bool;

    public function isFile(string $path): bool;
}
