<?php

declare(strict_types=1);

namespace Soukicz\Llm\Tests\Tool\TextEditor;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude35Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude37Sonnet;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude45Sonnet;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Tool\TextEditor\TextEditorStorageFilesystem;
use Soukicz\Llm\Tool\TextEditor\TextEditorTool;

class TextEditorToolTest extends TestCase {
    private string $testBaseDir;
    private TextEditorStorageFilesystem $storage;
    private TextEditorTool $tool;

    protected function setUp(): void {
        parent::setUp();

        // Create test directory
        $this->testBaseDir = sys_get_temp_dir() . '/anthropic_tool_functional_test_' . uniqid('', true);
        mkdir($this->testBaseDir, 0755, true);

        // Create subdirectory
        mkdir($this->testBaseDir . '/subdir', 0755, true);

        // Create test files with various content
        file_put_contents($this->testBaseDir . '/simple.txt', 'Hello World');
        file_put_contents($this->testBaseDir . '/multiline.txt', "Line 1\nLine 2\nLine 3\nLine 4\nLine 5");
        file_put_contents($this->testBaseDir . '/subdir/nested.txt', 'Nested content');

        // Create storage and tool instances
        $this->storage = new TextEditorStorageFilesystem($this->testBaseDir);
        $this->tool = new TextEditorTool($this->storage);
    }

    protected function tearDown(): void {
        // Clean up test files and directories
        $this->recursiveDelete($this->testBaseDir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * @dataProvider viewFileProvider
     */
    public function testViewFile(array $input, string $expectedContent): void {
        $response = $this->tool->handle($input);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertEquals($expectedContent, $response->getMessages()[0]->getText());
    }

    public static function viewFileProvider(): array {
        return [
            'simple file' => [
                ['command' => 'view', 'path' => 'simple.txt'],
                'Hello World',
            ],
            'multiline file' => [
                ['command' => 'view', 'path' => 'multiline.txt'],
                "Line 1\nLine 2\nLine 3\nLine 4\nLine 5",
            ],
            'nested file' => [
                ['command' => 'view', 'path' => 'subdir/nested.txt'],
                'Nested content',
            ],
            'file with line range' => [
                ['command' => 'view', 'path' => 'multiline.txt', 'view_range' => [2, 4]],
                "Line 2\nLine 3\nLine 4",
            ],
            'file from line to end' => [
                ['command' => 'view', 'path' => 'multiline.txt', 'view_range' => [3, -1]],
                "Line 3\nLine 4\nLine 5",
            ],
        ];
    }

    public function testViewDirectoryByAbsolutePath(): void {
        // Test viewing directory by absolute path
        $response = $this->tool->handle(['command' => 'view', 'path' => '/']);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $content = $response->getMessages()[0]->getText();

        $this->assertStringContainsString('Directory contents of', $content);
        $this->assertStringContainsString('FILE: simple.txt', $content);
        $this->assertStringContainsString('FILE: multiline.txt', $content);
        $this->assertStringContainsString('DIR: subdir', $content);
    }

    public function testViewSubdirectory(): void {
        $response = $this->tool->handle(['command' => 'view', 'path' => 'subdir']);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $content = $response->getMessages()[0]->getText();

        $this->assertStringContainsString('Directory contents of', $content);
        $this->assertStringContainsString('FILE: nested.txt', $content);
    }

    public function testViewNonExistentFile(): void {
        $response = $this->tool->handle(['command' => 'view', 'path' => 'nonexistent.txt']);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertEquals('Error: File not found', $response->getMessages()[0]->getText());
    }

    public function testCreateFile(): void {
        $content = 'This is new content';
        $response = $this->tool->handle([
            'command' => 'create',
            'path' => 'new_file.txt',
            'file_text' => $content,
        ]);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertStringContainsString('Successfully created file:', $response->getMessages()[0]->getText());

        // Verify file was created with correct content
        $this->assertFileExists($this->testBaseDir . '/new_file.txt');
        $this->assertEquals($content, file_get_contents($this->testBaseDir . '/new_file.txt'));
    }

    public function testCreateFileInSubdirectory(): void {
        $content = 'Content in subdirectory';
        $response = $this->tool->handle([
            'command' => 'create',
            'path' => 'subdir/new_nested.txt',
            'file_text' => $content,
        ]);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertStringContainsString('Successfully created file:', $response->getMessages()[0]->getText());

        // Verify file was created
        $this->assertFileExists($this->testBaseDir . '/subdir/new_nested.txt');
        $this->assertEquals($content, file_get_contents($this->testBaseDir . '/subdir/new_nested.txt'));
    }

    public function testCreateFileInNewDirectory(): void {
        $content = 'Content in new directory';
        $response = $this->tool->handle([
            'command' => 'create',
            'path' => 'newdir/file.txt',
            'file_text' => $content,
        ]);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertStringContainsString('Successfully created file:', $response->getMessages()[0]->getText());

        // Verify directory and file were created
        $this->assertDirectoryExists($this->testBaseDir . '/newdir');
        $this->assertFileExists($this->testBaseDir . '/newdir/file.txt');
        $this->assertEquals($content, file_get_contents($this->testBaseDir . '/newdir/file.txt'));
    }

    public function testCreateExistingFile(): void {
        $response = $this->tool->handle([
            'command' => 'create',
            'path' => 'simple.txt',
            'file_text' => 'New content',
        ]);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertEquals('Error: File already exists', $response->getMessages()[0]->getText());

        // Verify original content unchanged
        $this->assertEquals('Hello World', file_get_contents($this->testBaseDir . '/simple.txt'));
    }

    public function testReplaceInFile(): void {
        $response = $this->tool->handle([
            'command' => 'str_replace',
            'path' => 'simple.txt',
            'old_str' => 'Hello',
            'new_str' => 'Hi',
        ]);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertEquals('Successfully replaced 1 occurrence', $response->getMessages()[0]->getText());

        // Verify content was changed
        $this->assertEquals('Hi World', file_get_contents($this->testBaseDir . '/simple.txt'));
    }

    public function testReplaceInFileMultiline(): void {
        $response = $this->tool->handle([
            'command' => 'str_replace',
            'path' => 'multiline.txt',
            'old_str' => "Line 2\nLine 3",
            'new_str' => "Modified Line 2\nModified Line 3",
        ]);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertEquals('Successfully replaced 1 occurrence', $response->getMessages()[0]->getText());

        // Verify content was changed
        $expected = "Line 1\nModified Line 2\nModified Line 3\nLine 4\nLine 5";
        $this->assertEquals($expected, file_get_contents($this->testBaseDir . '/multiline.txt'));
    }

    public function testReplaceInFileNotFound(): void {
        $response = $this->tool->handle([
            'command' => 'str_replace',
            'path' => 'simple.txt',
            'old_str' => 'NotFound',
            'new_str' => 'Replacement',
        ]);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertEquals('Error: No match found for replacement. Please check your text and try again.', $response->getMessages()[0]->getText());

        // Verify content unchanged
        $this->assertEquals('Hello World', file_get_contents($this->testBaseDir . '/simple.txt'));
    }

    public function testReplaceInFileMultipleMatches(): void {
        // Create file with multiple matches
        file_put_contents($this->testBaseDir . '/duplicate.txt', 'test test test');

        $response = $this->tool->handle([
            'command' => 'str_replace',
            'path' => 'duplicate.txt',
            'old_str' => 'test',
            'new_str' => 'replaced',
        ]);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertEquals('Successfully replaced 1 occurrence', $response->getMessages()[0]->getText());

        // Verify only first occurrence was replaced
        $this->assertEquals('replaced test test', file_get_contents($this->testBaseDir . '/duplicate.txt'));
    }

    public function testReplaceInNonExistentFile(): void {
        $response = $this->tool->handle([
            'command' => 'str_replace',
            'path' => 'nonexistent.txt',
            'old_str' => 'old',
            'new_str' => 'new',
        ]);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertEquals('Error: File not found', $response->getMessages()[0]->getText());
    }

    public function testInsertToFile(): void {
        $response = $this->tool->handle([
            'command' => 'insert',
            'path' => 'multiline.txt',
            'new_str' => 'Inserted Line',
            'insert_line' => 2,
        ]);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertEquals('Successfully inserted text after line 2', $response->getMessages()[0]->getText());

        // Verify content was inserted
        $expected = "Line 1\nLine 2\nInserted Line\nLine 3\nLine 4\nLine 5";
        $this->assertEquals($expected, file_get_contents($this->testBaseDir . '/multiline.txt'));
    }

    public function testInsertToFileAtBeginning(): void {
        $response = $this->tool->handle([
            'command' => 'insert',
            'path' => 'simple.txt',
            'new_str' => 'First Line',
            'insert_line' => 0,
        ]);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertEquals('Successfully inserted text after line 0', $response->getMessages()[0]->getText());

        // Verify content was inserted at beginning
        $expected = "First Line\nHello World";
        $this->assertEquals($expected, file_get_contents($this->testBaseDir . '/simple.txt'));
    }

    public function testInsertToFileAtEnd(): void {
        $response = $this->tool->handle([
            'command' => 'insert',
            'path' => 'multiline.txt',
            'new_str' => 'Last Line',
            'insert_line' => 5,
        ]);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertEquals('Successfully inserted text after line 5', $response->getMessages()[0]->getText());

        // Verify content was inserted at end
        $expected = "Line 1\nLine 2\nLine 3\nLine 4\nLine 5\nLast Line";
        $this->assertEquals($expected, file_get_contents($this->testBaseDir . '/multiline.txt'));
    }

    public function testInsertToFileInvalidLineNumber(): void {
        $response = $this->tool->handle([
            'command' => 'insert',
            'path' => 'multiline.txt',
            'new_str' => 'Invalid',
            'insert_line' => 10,
        ]);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertStringContainsString('Line number 10 is out of range', $response->getMessages()[0]->getText());

        // Verify content unchanged
        $expected = "Line 1\nLine 2\nLine 3\nLine 4\nLine 5";
        $this->assertEquals($expected, file_get_contents($this->testBaseDir . '/multiline.txt'));
    }

    public function testInsertToNonExistentFile(): void {
        $response = $this->tool->handle([
            'command' => 'insert',
            'path' => 'nonexistent.txt',
            'new_str' => 'text',
            'insert_line' => 1,
        ]);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertEquals('Error: File not found', $response->getMessages()[0]->getText());
    }

    public function testUnknownCommand(): void {
        $response = $this->tool->handle([
            'command' => 'invalid_command',
            'path' => 'simple.txt',
        ]);

        $this->assertInstanceOf(LLMMessageContents::class, $response);
        $this->assertEquals('ERROR: Unknown command: invalid_command', $response->getMessages()[0]->getText());
    }

    public function testToolProperties(): void {
        $this->assertEquals('str_replace_based_edit_tool', $this->tool->getName());
        $this->assertEquals('str_replace_based_edit_tool', $this->tool->getAnthropicName());
    }

    public function testGetAnthropicTypeForClaude4Models(): void {
        $model = new AnthropicClaude45Sonnet(AnthropicClaude45Sonnet::VERSION_20250929);
        $this->assertEquals('text_editor_20250728', $this->tool->getAnthropicType($model));
    }

    public function testGetAnthropicTypeForClaude37(): void {
        $model = new AnthropicClaude37Sonnet(AnthropicClaude37Sonnet::VERSION_20250219);
        $this->assertEquals('text_editor_20250124', $this->tool->getAnthropicType($model));
    }

    public function testGetAnthropicTypeForOtherModels(): void {
        $model = new AnthropicClaude35Sonnet(AnthropicClaude35Sonnet::VERSION_20241022);
        $this->assertEquals('text_editor_20250429', $this->tool->getAnthropicType($model));
    }

    public function testStorageMethods(): void {
        // Test isFile method
        $this->assertTrue($this->storage->isFile('simple.txt'));
        $this->assertFalse($this->storage->isFile('subdir'));
        $this->assertFalse($this->storage->isFile('nonexistent.txt'));

        // Test isDirectory method
        $this->assertTrue($this->storage->isDirectory('subdir'));
        $this->assertFalse($this->storage->isDirectory('simple.txt'));
        $this->assertFalse($this->storage->isDirectory('nonexistent'));

        // Test getDirectoryContent returns string array
        $contents = $this->storage->getDirectoryContent('/');
        $this->assertIsArray($contents);
        $this->assertContains('simple.txt', $contents);
        $this->assertContains('multiline.txt', $contents);
        $this->assertContains('subdir', $contents);
    }
}
