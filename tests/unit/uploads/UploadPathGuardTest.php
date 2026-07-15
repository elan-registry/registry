<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the upload path guard logic from save.php::uploadImages().
 *
 * The guard must:
 *  (a) accept a direct child directory (dirname strips trailing slash and resolves to parent)
 *  (b) accept a genuine nested subdirectory
 *  (c) reject a sibling directory with a shared prefix
 *  (d) reject when realpath() returns false (missing or misconfigured base directory)
 */
#[Group('fast')]
class UploadPathGuardTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/elan_pathguard_' . uniqid();
        mkdir($this->baseDir, 0755, true);
        mkdir($this->baseDir . '/123', 0755, true);
        mkdir($this->baseDir . '/nested/456', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->baseDir);
        // Sibling created in the sibling test
        $sibling = sys_get_temp_dir() . '/elan_pathguard_sibling_' . basename($this->baseDir);
        if (is_dir($sibling)) {
            $this->rmrf($sibling);
        }
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Replicates the guard from save.php::uploadImages() and returns true when
     * the path is REJECTED (i.e. the guard fires).
     */
    private function guardFires(string $targetFilePath, string $filePath): bool
    {
        $realTargetPath  = realpath($targetFilePath);
        $realFilePath    = realpath(dirname($filePath));
        $canonicalTarget = $realTargetPath !== false
            ? rtrim($realTargetPath, DIRECTORY_SEPARATOR)
            : false;

        return $realTargetPath === false
            || $realFilePath === false
            || ($realFilePath !== $canonicalTarget
                && !str_starts_with($realFilePath, $canonicalTarget . DIRECTORY_SEPARATOR));
    }

    public function testDirectChildDirectoryPasses(): void
    {
        // dirname('/base/123/') resolves to '/base' — equality with $canonicalTarget must pass
        $filePath = $this->baseDir . '/123/';
        $this->assertFalse($this->guardFires($this->baseDir . '/', $filePath));
    }

    public function testNestedSubdirectoryPasses(): void
    {
        $filePath = $this->baseDir . '/nested/456/';
        $this->assertFalse($this->guardFires($this->baseDir . '/', $filePath));
    }

    public function testSiblingDirectoryWithSharedPrefixIsRejected(): void
    {
        // Sibling: /elan_pathguard_XYZ-other/ shares a prefix with the base dir name
        $sibling = $this->baseDir . '-other';
        mkdir($sibling, 0755, true);

        $filePath = $sibling . '/123/';
        mkdir($sibling . '/123', 0755, true);

        $this->assertTrue($this->guardFires($this->baseDir . '/', $filePath));

        $this->rmrf($sibling);
    }

    public function testMissingTargetDirectoryIsRejected(): void
    {
        // realpath() returns false for a path that does not exist
        $filePath = $this->baseDir . '/123/';
        $this->assertTrue($this->guardFires('/nonexistent/target/', $filePath));
    }

    public function testParentDirectoryTraversalIsRejected(): void
    {
        // Attempt to write to the parent of the base dir
        $filePath = dirname($this->baseDir) . '/';
        $this->assertTrue($this->guardFires($this->baseDir . '/', $filePath));
    }
}
