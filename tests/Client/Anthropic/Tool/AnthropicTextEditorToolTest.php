<?php

declare(strict_types=1);

namespace Soukicz\Llm\Client\Anthropic\Tool;

use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Tool\ToolResponse;

class AnthropicTextEditorToolTest extends TestCase {
    private string $testBaseDir;
    private AnthropicTextEditorTool $tool;

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

        $this->tool = new AnthropicTextEditorTool('test_tool', $this->testBaseDir);
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

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertEquals($expectedContent, $response->getData());
    }

    public static function viewFileProvider(): array {
        return [
            'simple file' => [
                ['command' => 'view', 'path' => 'simple.txt'],
                'Hello World'
            ],
            'multiline file' => [
                ['command' => 'view', 'path' => 'multiline.txt'],
                "Line 1\nLine 2\nLine 3\nLine 4\nLine 5"
            ],
            'nested file' => [
                ['command' => 'view', 'path' => 'subdir/nested.txt'],
                'Nested content'
            ],
            'file with line range' => [
                ['command' => 'view', 'path' => 'multiline.txt', 'view_range' => [2, 4]],
                "Line 2\nLine 3\nLine 4"
            ],
            'file from line to end' => [
                ['command' => 'view', 'path' => 'multiline.txt', 'view_range' => [3, -1]],
                "Line 3\nLine 4\nLine 5"
            ],
        ];
    }

    public function testViewDirectoryByAbsolutePath(): void {
        // Test viewing directory by absolute path
        $response = $this->tool->handle(['command' => 'view', 'path' => $this->testBaseDir]);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $content = $response->getData();

        $this->assertStringContainsString('Directory contents of', $content);
        $this->assertStringContainsString('FILE: simple.txt', $content);
        $this->assertStringContainsString('FILE: multiline.txt', $content);
        $this->assertStringContainsString('DIR: subdir', $content);
    }

    public function testViewSubdirectory(): void {
        $response = $this->tool->handle(['command' => 'view', 'path' => 'subdir']);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $content = $response->getData();

        $this->assertStringContainsString('Directory contents of', $content);
        $this->assertStringContainsString('FILE: nested.txt', $content);
    }

    public function testViewNonExistentFile(): void {
        $response = $this->tool->handle(['command' => 'view', 'path' => 'nonexistent.txt']);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertEquals('Error: File not found', $response->getData());
    }

    public function testCreateFile(): void {
        $content = 'This is new content';
        $response = $this->tool->handle([
            'command' => 'create',
            'path' => 'new_file.txt',
            'file_text' => $content
        ]);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertStringContainsString('Successfully created file:', $response->getData());

        // Verify file was created with correct content
        $this->assertFileExists($this->testBaseDir . '/new_file.txt');
        $this->assertEquals($content, file_get_contents($this->testBaseDir . '/new_file.txt'));
    }

    public function testCreateFileInSubdirectory(): void {
        $content = 'Content in subdirectory';
        $response = $this->tool->handle([
            'command' => 'create',
            'path' => 'subdir/new_nested.txt',
            'file_text' => $content
        ]);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertStringContainsString('Successfully created file:', $response->getData());

        // Verify file was created
        $this->assertFileExists($this->testBaseDir . '/subdir/new_nested.txt');
        $this->assertEquals($content, file_get_contents($this->testBaseDir . '/subdir/new_nested.txt'));
    }

    public function testCreateFileInNewDirectory(): void {
        $content = 'Content in new directory';
        $response = $this->tool->handle([
            'command' => 'create',
            'path' => 'newdir/file.txt',
            'file_text' => $content
        ]);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertStringContainsString('Successfully created file:', $response->getData());

        // Verify directory and file were created
        $this->assertDirectoryExists($this->testBaseDir . '/newdir');
        $this->assertFileExists($this->testBaseDir . '/newdir/file.txt');
        $this->assertEquals($content, file_get_contents($this->testBaseDir . '/newdir/file.txt'));
    }

    public function testCreateExistingFile(): void {
        $response = $this->tool->handle([
            'command' => 'create',
            'path' => 'simple.txt',
            'file_text' => 'New content'
        ]);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertEquals('Error: File already exists', $response->getData());

        // Verify original content unchanged
        $this->assertEquals('Hello World', file_get_contents($this->testBaseDir . '/simple.txt'));
    }

    public function testReplaceInFile(): void {
        $response = $this->tool->handle([
            'command' => 'str_replace',
            'path' => 'simple.txt',
            'old_str' => 'Hello',
            'new_str' => 'Hi'
        ]);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertEquals('Successfully replaced text in file', $response->getData());

        // Verify content was changed
        $this->assertEquals('Hi World', file_get_contents($this->testBaseDir . '/simple.txt'));
    }

    public function testReplaceInFileMultiline(): void {
        $response = $this->tool->handle([
            'command' => 'str_replace',
            'path' => 'multiline.txt',
            'old_str' => "Line 2\nLine 3",
            'new_str' => "Modified Line 2\nModified Line 3"
        ]);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertEquals('Successfully replaced text in file', $response->getData());

        // Verify content was changed
        $expected = "Line 1\nModified Line 2\nModified Line 3\nLine 4\nLine 5";
        $this->assertEquals($expected, file_get_contents($this->testBaseDir . '/multiline.txt'));
    }

    public function testReplaceInFileNotFound(): void {
        $response = $this->tool->handle([
            'command' => 'str_replace',
            'path' => 'simple.txt',
            'old_str' => 'NotFound',
            'new_str' => 'Replacement'
        ]);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertEquals('Error: No match found for replacement. Please check your text and try again.', $response->getData());

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
            'new_str' => 'replaced'
        ]);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertStringContainsString('Found 3 matches for replacement text', $response->getData());

        // Verify content unchanged
        $this->assertEquals('test test test', file_get_contents($this->testBaseDir . '/duplicate.txt'));
    }

    public function testReplaceInNonExistentFile(): void {
        $response = $this->tool->handle([
            'command' => 'str_replace',
            'path' => 'nonexistent.txt',
            'old_str' => 'old',
            'new_str' => 'new'
        ]);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertEquals('Error: File not found', $response->getData());
    }

    public function testInsertToFile(): void {
        $response = $this->tool->handle([
            'command' => 'insert',
            'path' => 'multiline.txt',
            'new_str' => 'Inserted Line',
            'insert_line' => 2
        ]);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertEquals('Successfully inserted text after line 2', $response->getData());

        // Verify content was inserted
        $expected = "Line 1\nLine 2\nInserted Line\nLine 3\nLine 4\nLine 5";
        $this->assertEquals($expected, file_get_contents($this->testBaseDir . '/multiline.txt'));
    }

    public function testInsertToFileAtBeginning(): void {
        $response = $this->tool->handle([
            'command' => 'insert',
            'path' => 'simple.txt',
            'new_str' => 'First Line',
            'insert_line' => 0
        ]);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertEquals('Successfully inserted text after line 0', $response->getData());

        // Verify content was inserted at beginning
        $expected = "First Line\nHello World";
        $this->assertEquals($expected, file_get_contents($this->testBaseDir . '/simple.txt'));
    }

    public function testInsertToFileAtEnd(): void {
        $response = $this->tool->handle([
            'command' => 'insert',
            'path' => 'multiline.txt',
            'new_str' => 'Last Line',
            'insert_line' => 5
        ]);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertEquals('Successfully inserted text after line 5', $response->getData());

        // Verify content was inserted at end
        $expected = "Line 1\nLine 2\nLine 3\nLine 4\nLine 5\nLast Line";
        $this->assertEquals($expected, file_get_contents($this->testBaseDir . '/multiline.txt'));
    }

    public function testInsertToFileInvalidLineNumber(): void {
        $response = $this->tool->handle([
            'command' => 'insert',
            'path' => 'multiline.txt',
            'new_str' => 'Invalid',
            'insert_line' => 10
        ]);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertStringContainsString('Line number 10 is out of range', $response->getData());

        // Verify content unchanged
        $expected = "Line 1\nLine 2\nLine 3\nLine 4\nLine 5";
        $this->assertEquals($expected, file_get_contents($this->testBaseDir . '/multiline.txt'));
    }

    public function testInsertToNonExistentFile(): void {
        $response = $this->tool->handle([
            'command' => 'insert',
            'path' => 'nonexistent.txt',
            'new_str' => 'text',
            'insert_line' => 1
        ]);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertEquals('Error: File not found', $response->getData());
    }

    public function testUnknownCommand(): void {
        $response = $this->tool->handle([
            'command' => 'invalid_command',
            'path' => 'simple.txt'
        ]);

        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertEquals('ERROR: Unknown command: invalid_command', $response->getData());
    }

    public function testToolProperties(): void {
        $this->assertEquals('test_tool', $this->tool->getName());
        $this->assertEquals('text_editor_20250429', $this->tool->getType());
    }
}
