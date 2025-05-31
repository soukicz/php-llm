<?php

namespace Soukicz\Llm\Tool\TextEditor;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Tool\ToolResponse;

class TextEditorToolSecurityTest extends TestCase {
    private string $testBaseDir;
    private string $outsideDir;
    private TextEditorStorageFilesystem $storage;
    private TextEditorTool $tool;

    protected function setUp(): void {
        parent::setUp();

        // Create test directories
        $this->testBaseDir = sys_get_temp_dir() . '/anthropic_tool_test_' . uniqid('', true);
        $this->outsideDir = sys_get_temp_dir() . '/outside_test_' . uniqid('', true);

        mkdir($this->testBaseDir, 0755, true);
        mkdir($this->outsideDir, 0755, true);

        // Create test files
        file_put_contents($this->testBaseDir . '/safe_file.txt', 'This is a safe file content');
        file_put_contents($this->testBaseDir . '/another_file.txt', 'Another safe file');
        file_put_contents($this->outsideDir . '/outside_file.txt', 'This file is outside base directory');
        file_put_contents('/tmp/system_file.txt', 'System file that should not be accessible');

        // Create subdirectory in base
        mkdir($this->testBaseDir . '/subdir', 0755, true);
        file_put_contents($this->testBaseDir . '/subdir/sub_file.txt', 'File in subdirectory');

        // Create storage and tool instances
        $this->storage = new TextEditorStorageFilesystem($this->testBaseDir);
        $this->tool = new TextEditorTool($this->storage);
    }

    protected function tearDown(): void {
        // Clean up test files and directories
        $this->recursiveDelete($this->testBaseDir);
        $this->recursiveDelete($this->outsideDir);
        @unlink('/tmp/system_file.txt');

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
     * Test that storage constructor validates base directory
     */
    public function testStorageConstructorValidatesBaseDirectory(): void {
        // Test relative path rejection
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base directory must be an absolute path');
        new TextEditorStorageFilesystem('relative/path');
    }

    public function testStorageConstructorValidatesBaseDirectoryExists(): void {
        // Test non-existent directory rejection
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base directory does not exist or is not accessible');
        new TextEditorStorageFilesystem('/non/existent/directory');
    }

    /**
     * Test classic path traversal attacks - these should return errors through ToolResponse
     */
    public function testPathTraversalAttacks(): void {
        $maliciousPaths = [
            // Classic path traversal
            '../' . basename($this->outsideDir) . '/outside_file.txt',
            '../../tmp/system_file.txt',
            '../../../etc/passwd',

            // More complex traversal
            'safe_file.txt/../../../etc/passwd',
            'subdir/../../' . basename($this->outsideDir) . '/outside_file.txt',
        ];

        foreach ($maliciousPaths as $path) {
            $response = $this->tool->handle(['command' => 'view', 'path' => $path]);
            $this->assertInstanceOf(ToolResponse::class, $response);

            $data = $response->getData();
            $this->assertTrue(
                str_contains($data, 'Error:') && (
                    str_contains($data, 'Path is not in base directory') ||
                    str_contains($data, 'dangerous sequence') ||
                    str_contains($data, 'File not found')
                ),
                "Expected security error for path '$path', got: $data"
            );
        }
    }

    /**
     * Test absolute path attacks outside base directory
     */
    public function testAbsolutePathAttacks(): void {
        $maliciousPaths = [
            '/etc/passwd',
            '/tmp/system_file.txt',
            $this->outsideDir . '/outside_file.txt',
            '/root/.ssh/id_rsa',
        ];

        foreach ($maliciousPaths as $path) {
            $response = $this->tool->handle(['command' => 'view', 'path' => $path]);
            $this->assertInstanceOf(ToolResponse::class, $response);
            $this->assertEquals(
                'Error: File not found',
                $response->getData(),
                "Expected 'File not found' error for path '$path'"
            );
        }
    }

    /**
     * Test null byte injection attacks
     */
    public function testNullByteInjection(): void {
        $maliciousPaths = [
            "safe_file.txt\0/etc/passwd",
            "safe_file.txt\0/../../../etc/passwd",
            "\0/etc/passwd",
        ];

        foreach ($maliciousPaths as $path) {
            $response = $this->tool->handle(['command' => 'view', 'path' => $path]);
            $this->assertInstanceOf(ToolResponse::class, $response);

            $data = $response->getData();
            $this->assertTrue(
                str_contains($data, 'Error:') && str_contains($data, 'null bytes'),
                "Expected null byte error for path '$path', got: $data"
            );
        }
    }

    /**
     * Test double slash and other encoding attacks
     */
    public function testEncodingAttacks(): void {
        $maliciousPaths = [
            './safe_file.txt',
            './../' . basename($this->outsideDir) . '/outside_file.txt',
        ];

        foreach ($maliciousPaths as $path) {
            $response = $this->tool->handle(['command' => 'view', 'path' => $path]);
            $this->assertInstanceOf(ToolResponse::class, $response);

            $data = $response->getData();
            $this->assertTrue(
                str_contains($data, 'Error:') && (
                    str_contains($data, 'dangerous sequence') ||
                    str_contains($data, 'Path is not in base directory')
                ),
                "Expected security error for path '$path', got: $data"
            );
        }
    }

    /**
     * Test that legitimate paths work correctly
     */
    public function testLegitimatePathsWork(): void {
        $legitimatePaths = [
            // Absolute paths within base directory
            '/safe_file.txt',
            '/subdir/sub_file.txt',

            // Relative paths within base directory
            'safe_file.txt',
            'another_file.txt',
            'subdir/sub_file.txt',
        ];

        foreach ($legitimatePaths as $path) {
            $response = $this->tool->handle(['command' => 'view', 'path' => $path]);
            $this->assertInstanceOf(ToolResponse::class, $response);
            $this->assertStringNotContainsString(
                'Error:',
                $response->getData(),
                "Legitimate path '$path' should not produce an error"
            );
        }
    }

    /**
     * Test file creation with path traversal attempts
     */
    public function testFileCreationSecurity(): void {
        $maliciousPaths = [
            '../malicious_file.txt',
            '../../outside_create.txt',
        ];

        foreach ($maliciousPaths as $path) {
            $response = $this->tool->handle([
                'command' => 'create',
                'path' => $path,
                'file_text' => 'malicious content',
            ]);
            $this->assertInstanceOf(ToolResponse::class, $response);

            $data = $response->getData();
            $this->assertTrue(
                str_contains($data, 'Error:') && (
                    str_contains($data, 'dangerous sequence') ||
                    str_contains($data, 'Path is not in base directory')
                ),
                "Expected security error for file creation at '$path', got: $data"
            );
        }
    }

    /**
     * Test string replacement with path traversal attempts
     */
    public function testStringReplacementSecurity(): void {
        $maliciousPaths = [
            '../' . basename($this->outsideDir) . '/outside_file.txt',
            '../../tmp/system_file.txt',
        ];

        foreach ($maliciousPaths as $path) {
            $response = $this->tool->handle([
                'command' => 'str_replace',
                'path' => $path,
                'old_str' => 'old',
                'new_str' => 'new',
            ]);
            $this->assertInstanceOf(ToolResponse::class, $response);

            $data = $response->getData();
            $this->assertTrue(
                str_contains($data, 'Error:') && (
                    str_contains($data, 'dangerous sequence') ||
                    str_contains($data, 'Path is not in base directory') ||
                    str_contains($data, 'File not found')
                ),
                "Expected security error for string replacement in '$path', got: $data"
            );
        }
    }

    /**
     * Test file insertion with path traversal attempts
     */
    public function testFileInsertionSecurity(): void {
        $maliciousPaths = [
            '../' . basename($this->outsideDir) . '/outside_file.txt',
            '../../tmp/system_file.txt',
        ];

        foreach ($maliciousPaths as $path) {
            $response = $this->tool->handle([
                'command' => 'insert',
                'path' => $path,
                'new_str' => 'inserted content',
                'insert_line' => 1,
            ]);
            $this->assertInstanceOf(ToolResponse::class, $response);

            $data = $response->getData();
            $this->assertTrue(
                str_contains($data, 'Error:') && (
                    str_contains($data, 'dangerous sequence') ||
                    str_contains($data, 'Path is not in base directory') ||
                    str_contains($data, 'File not found')
                ),
                "Expected security error for file insertion in '$path', got: $data"
            );
        }
    }

    /**
     * Test that subdirectories within base directory work correctly
     */
    public function testSubdirectoryAccess(): void {
        // Test legitimate subdirectory access
        $response = $this->tool->handle(['command' => 'view', 'path' => 'subdir/sub_file.txt']);
        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertStringContainsString('File in subdirectory', $response->getData());

        // Test legitimate file creation in subdirectory
        $response = $this->tool->handle([
            'command' => 'create',
            'path' => 'subdir/new_file.txt',
            'file_text' => 'New file content',
        ]);
        $this->assertInstanceOf(ToolResponse::class, $response);
        $this->assertStringContainsString('Successfully created', $response->getData());

        // Verify the file was created in the right place
        $this->assertFileExists($this->testBaseDir . '/subdir/new_file.txt');
        $this->assertEquals('New file content', file_get_contents($this->testBaseDir . '/subdir/new_file.txt'));
    }

    /**
     * Test edge cases and boundary conditions
     */
    public function testEdgeCases(): void {
        // Test current directory reference
        $response = $this->tool->handle(['command' => 'view', 'path' => '.']);
        $this->assertInstanceOf(ToolResponse::class, $response);

        $data = $response->getData();
        $this->assertTrue(
            str_contains($data, 'Error:') && str_contains($data, 'dangerous sequence'),
            "Current directory reference should be rejected"
        );
    }

    /**
     * Test security through isFile and isDirectory methods
     */
    public function testStorageSecurityMethods(): void {
        // Test that path traversal attempts return false
        $this->assertFalse($this->storage->isFile('../etc/passwd'));
        $this->assertFalse($this->storage->isDirectory('../etc'));
        $this->assertFalse($this->storage->isFile('../../tmp/system_file.txt'));

        // Test that legitimate paths work
        $this->assertTrue($this->storage->isFile('safe_file.txt'));
        $this->assertTrue($this->storage->isDirectory('subdir'));
    }
}
