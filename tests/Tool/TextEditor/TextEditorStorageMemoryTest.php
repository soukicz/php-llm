<?php

namespace Soukicz\Llm\Tests\Tool\TextEditor;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Soukicz\Llm\Tool\TextEditor\TextEditorStorageMemory;

class TextEditorStorageMemoryTest extends TestCase {
    private TextEditorStorageMemory $storage;

    protected function setUp(): void {
        $this->storage = new TextEditorStorageMemory();
    }

    // File Operations Tests

    public function testCreateAndGetFile(): void {
        $this->storage->createFile('test.txt', 'Hello World');
        $this->assertEquals('Hello World', $this->storage->getFileContent('test.txt'));
    }

    public function testCreateFileWithLeadingSlash(): void {
        $this->storage->createFile('/test.txt', 'Hello World');
        $this->assertEquals('Hello World', $this->storage->getFileContent('test.txt'));
        $this->assertEquals('Hello World', $this->storage->getFileContent('/test.txt'));
    }

    public function testCreateFileInDirectory(): void {
        $this->storage->createFile('dir/subdir/test.txt', 'Hello World');
        $this->assertEquals('Hello World', $this->storage->getFileContent('dir/subdir/test.txt'));

        // Check that directories were created
        $this->assertTrue($this->storage->isDirectory('dir'));
        $this->assertTrue($this->storage->isDirectory('dir/subdir'));
    }

    public function testCreateFileAlreadyExists(): void {
        $this->storage->createFile('test.txt', 'Hello');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File already exists');
        $this->storage->createFile('test.txt', 'World');
    }

    public function testCreateFileWhereDirectoryExists(): void {
        $this->storage->createDirectory('test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A directory exists at this path');
        $this->storage->createFile('test', 'content');
    }

    public function testGetFileContentNotFound(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File not found');
        $this->storage->getFileContent('nonexistent.txt');
    }

    public function testSetFileContent(): void {
        $this->storage->createFile('test.txt', 'Original');
        $this->storage->setFileContent('test.txt', 'Modified');
        $this->assertEquals('Modified', $this->storage->getFileContent('test.txt'));
    }

    public function testSetFileContentNotFound(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File not found');
        $this->storage->setFileContent('nonexistent.txt', 'content');
    }

    public function testDeleteFile(): void {
        $this->storage->createFile('test.txt', 'Hello');
        $this->assertTrue($this->storage->isFile('test.txt'));

        $this->storage->deleteFile('test.txt');
        $this->assertFalse($this->storage->isFile('test.txt'));
    }

    public function testDeleteFileNotFound(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File not found');
        $this->storage->deleteFile('nonexistent.txt');
    }

    public function testRenameFile(): void {
        $this->storage->createFile('old.txt', 'Content');
        $this->storage->renameFile('old.txt', 'new.txt');

        $this->assertFalse($this->storage->isFile('old.txt'));
        $this->assertTrue($this->storage->isFile('new.txt'));
        $this->assertEquals('Content', $this->storage->getFileContent('new.txt'));
    }

    public function testRenameFileToNewDirectory(): void {
        $this->storage->createFile('old.txt', 'Content');
        $this->storage->renameFile('old.txt', 'newdir/new.txt');

        $this->assertFalse($this->storage->isFile('old.txt'));
        $this->assertTrue($this->storage->isFile('newdir/new.txt'));
        $this->assertTrue($this->storage->isDirectory('newdir'));
        $this->assertEquals('Content', $this->storage->getFileContent('newdir/new.txt'));
    }

    public function testRenameFileSourceNotFound(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source file not found');
        $this->storage->renameFile('nonexistent.txt', 'new.txt');
    }

    public function testRenameFileDestinationExists(): void {
        $this->storage->createFile('old.txt', 'Old');
        $this->storage->createFile('new.txt', 'New');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destination file already exists');
        $this->storage->renameFile('old.txt', 'new.txt');
    }

    public function testRenameFileDestinationIsDirectory(): void {
        $this->storage->createFile('file.txt', 'Content');
        $this->storage->createDirectory('dir');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A directory exists at the destination path');
        $this->storage->renameFile('file.txt', 'dir');
    }

    // Directory Operations Tests

    public function testCreateDirectory(): void {
        $this->storage->createDirectory('testdir');
        $this->assertTrue($this->storage->isDirectory('testdir'));
    }

    public function testCreateNestedDirectory(): void {
        $this->storage->createDirectory('dir1/dir2/dir3');
        $this->assertTrue($this->storage->isDirectory('dir1'));
        $this->assertTrue($this->storage->isDirectory('dir1/dir2'));
        $this->assertTrue($this->storage->isDirectory('dir1/dir2/dir3'));
    }

    public function testCreateDirectoryAlreadyExists(): void {
        $this->storage->createDirectory('test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Directory already exists');
        $this->storage->createDirectory('test');
    }

    public function testCreateDirectoryWhereFileExists(): void {
        $this->storage->createFile('test', 'content');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A file exists at this path');
        $this->storage->createDirectory('test');
    }

    public function testCreateRootDirectory(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot create root directory');
        $this->storage->createDirectory('');
    }

    public function testGetDirectoryContent(): void {
        $this->storage->createFile('file1.txt', 'Content1');
        $this->storage->createFile('file2.txt', 'Content2');
        $this->storage->createDirectory('dir1');
        $this->storage->createDirectory('dir2');

        $contents = $this->storage->getDirectoryContent('');
        $this->assertCount(4, $contents);
        $this->assertContains('file1.txt', $contents);
        $this->assertContains('file2.txt', $contents);
        $this->assertContains('dir1', $contents);
        $this->assertContains('dir2', $contents);
    }

    public function testGetDirectoryContentNested(): void {
        $this->storage->createDirectory('parent');
        $this->storage->createFile('parent/file1.txt', 'Content1');
        $this->storage->createFile('parent/file2.txt', 'Content2');
        $this->storage->createDirectory('parent/subdir');
        $this->storage->createFile('parent/subdir/nested.txt', 'Nested');

        $contents = $this->storage->getDirectoryContent('parent');
        $this->assertCount(3, $contents);
        $this->assertContains('file1.txt', $contents);
        $this->assertContains('file2.txt', $contents);
        $this->assertContains('subdir', $contents);
        $this->assertNotContains('nested.txt', $contents);
    }

    public function testGetDirectoryContentNotFound(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Directory not found');
        $this->storage->getDirectoryContent('nonexistent');
    }

    public function testDeleteDirectory(): void {
        $this->storage->createDirectory('test');
        $this->assertTrue($this->storage->isDirectory('test'));

        $this->storage->deleteDirectory('test');
        $this->assertFalse($this->storage->isDirectory('test'));
    }

    public function testDeleteDirectoryNotEmpty(): void {
        $this->storage->createDirectory('test');
        $this->storage->createFile('test/file.txt', 'content');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Directory is not empty');
        $this->storage->deleteDirectory('test');
    }

    public function testDeleteDirectoryWithSubdirectory(): void {
        $this->storage->createDirectory('test');
        $this->storage->createDirectory('test/subdir');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Directory is not empty');
        $this->storage->deleteDirectory('test');
    }

    public function testDeleteDirectoryNotFound(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Directory not found');
        $this->storage->deleteDirectory('nonexistent');
    }

    public function testDeleteRootDirectory(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete root directory');
        $this->storage->deleteDirectory('');
    }

    public function testRenameDirectory(): void {
        $this->storage->createDirectory('old');
        $this->storage->createFile('old/file.txt', 'content');
        $this->storage->createDirectory('old/subdir');
        $this->storage->createFile('old/subdir/nested.txt', 'nested content');

        $this->storage->renameDirectory('old', 'new');

        $this->assertFalse($this->storage->isDirectory('old'));
        $this->assertTrue($this->storage->isDirectory('new'));
        $this->assertTrue($this->storage->isFile('new/file.txt'));
        $this->assertTrue($this->storage->isDirectory('new/subdir'));
        $this->assertTrue($this->storage->isFile('new/subdir/nested.txt'));
        $this->assertEquals('content', $this->storage->getFileContent('new/file.txt'));
        $this->assertEquals('nested content', $this->storage->getFileContent('new/subdir/nested.txt'));
    }

    public function testRenameDirectoryToNestedPath(): void {
        $this->storage->createDirectory('old');
        $this->storage->renameDirectory('old', 'parent/new');

        $this->assertFalse($this->storage->isDirectory('old'));
        $this->assertTrue($this->storage->isDirectory('parent'));
        $this->assertTrue($this->storage->isDirectory('parent/new'));
    }

    public function testRenameDirectorySourceNotFound(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source directory not found');
        $this->storage->renameDirectory('nonexistent', 'new');
    }

    public function testRenameDirectoryDestinationExists(): void {
        $this->storage->createDirectory('old');
        $this->storage->createDirectory('new');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destination already exists');
        $this->storage->renameDirectory('old', 'new');
    }

    public function testRenameDirectoryDestinationIsFile(): void {
        $this->storage->createDirectory('old');
        $this->storage->createFile('new', 'content');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destination already exists');
        $this->storage->renameDirectory('old', 'new');
    }

    public function testRenameRootDirectory(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot rename root directory');
        $this->storage->renameDirectory('', 'new');
    }

    // Type Check Tests

    public function testIsFile(): void {
        $this->storage->createFile('file.txt', 'content');
        $this->storage->createDirectory('dir');

        $this->assertTrue($this->storage->isFile('file.txt'));
        $this->assertFalse($this->storage->isFile('dir'));
        $this->assertFalse($this->storage->isFile('nonexistent'));
    }

    public function testIsDirectory(): void {
        $this->storage->createFile('file.txt', 'content');
        $this->storage->createDirectory('dir');

        $this->assertTrue($this->storage->isDirectory('dir'));
        $this->assertTrue($this->storage->isDirectory('')); // Root directory
        $this->assertFalse($this->storage->isDirectory('file.txt'));
        $this->assertFalse($this->storage->isDirectory('nonexistent'));
    }

    // Path Validation Tests

    public function testInvalidPathWithNullByte(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Path contains null bytes');
        $this->storage->createFile("test\0.txt", 'content');
    }

    public function testInvalidPathWithDot(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Path contains dangerous sequence');
        $this->storage->createFile('.', 'content');
    }

    public function testInvalidPathWithDotDot(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Path contains dangerous sequence');
        $this->storage->createFile('../test.txt', 'content');
    }

    public function testInvalidPathWithDotSlash(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Path contains dangerous sequence');
        $this->storage->createFile('./test.txt', 'content');
    }

    public function testPathWithMiddleDotDot(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Path contains dangerous sequence');
        $this->storage->createFile('dir/../test.txt', 'content');
    }

    // Edge Cases

    public function testEmptyContent(): void {
        $this->storage->createFile('empty.txt', '');
        $this->assertEquals('', $this->storage->getFileContent('empty.txt'));
    }

    public function testLargeContent(): void {
        $largeContent = str_repeat('A', 1000000); // 1MB
        $this->storage->createFile('large.txt', $largeContent);
        $this->assertEquals($largeContent, $this->storage->getFileContent('large.txt'));
    }

    public function testDeepNesting(): void {
        $deepPath = 'a/b/c/d/e/f/g/h/i/j/file.txt';
        $this->storage->createFile($deepPath, 'deep content');
        $this->assertEquals('deep content', $this->storage->getFileContent($deepPath));

        // Check all parent directories were created
        $this->assertTrue($this->storage->isDirectory('a'));
        $this->assertTrue($this->storage->isDirectory('a/b'));
        $this->assertTrue($this->storage->isDirectory('a/b/c/d/e/f/g/h/i/j'));
    }

    public function testSpecialCharactersInPath(): void {
        $specialPath = 'test file (1) [2] {3} @4 #5.txt';
        $this->storage->createFile($specialPath, 'content');
        $this->assertEquals('content', $this->storage->getFileContent($specialPath));
    }

    public function testIsFileWithInvalidPath(): void {
        $this->assertFalse($this->storage->isFile('../invalid'));
    }

    public function testIsDirectoryWithInvalidPath(): void {
        $this->assertFalse($this->storage->isDirectory('../invalid'));
    }
}
