<?php

declare(strict_types=1);

use ElanRegistry\UploadPathGuard;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the upload path guard used by save.php::uploadImages().
 *
 * The guard logic lives in ElanRegistry\UploadPathGuard::isWithinTarget()
 * (extracted from save.php per #1601) — a pure, PSR-4-autoloaded method with
 * zero framework dependency, so it loads standalone in the unit tier. The
 * behavioral tests below call it directly, so they exercise the exact same
 * code path production runs — no drift risk. Previously these tests asserted
 * against a hand-copied duplicate of the guard condition declared inside this
 * test class, which could silently diverge from the real guard.
 *
 * The guard must:
 *  (a) accept a direct child directory (dirname strips trailing slash and resolves to parent)
 *  (b) accept a genuine nested subdirectory
 *  (c) reject a sibling directory with a shared prefix
 *  (d) reject when realpath() returns false (missing or misconfigured base directory)
 *
 * Two companion source-inspection tests verify save.php is actually wired to
 * the guard — the one part of the contract that can't be verified
 * behaviorally, since save.php can't be loaded standalone in this tier. One
 * confirms the guard condition itself is called (rather than an inline
 * duplicate); the other confirms the throw beneath it is still present, so a
 * weakened guard that logs but no longer blocks the write is also caught.
 *
 * @issue 1601
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
        $this->rmrf($this->baseDir . '-other');
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
     * A car's own image directory is a direct child of the upload base, the
     * most common real-world case — it must be accepted.
     */
    public function testDirectChildDirectoryPasses(): void
    {
        // dirname('/base/123/') resolves to '/base' — equality with the canonical target must pass
        $filePath = $this->baseDir . '/123/';
        $this->assertTrue(UploadPathGuard::isWithinTarget($this->baseDir . '/', $filePath));
    }

    /**
     * A directory nested more than one level below the upload base is still
     * inside it and must be accepted.
     */
    public function testNestedSubdirectoryPasses(): void
    {
        $filePath = $this->baseDir . '/nested/456/';
        $this->assertTrue(UploadPathGuard::isWithinTarget($this->baseDir . '/', $filePath));
    }

    /**
     * A sibling directory whose name starts with the upload base's name is
     * outside the base and must be rejected — this is why the guard compares
     * against the target plus a directory separator, not a bare prefix.
     */
    public function testSiblingDirectoryWithSharedPrefixIsRejected(): void
    {
        // Sibling: /elan_pathguard_XYZ-other/ shares a prefix with the base dir name
        $sibling = $this->baseDir . '-other';
        mkdir($sibling, 0755, true);

        $filePath = $sibling . '/123/';
        mkdir($sibling . '/123', 0755, true);

        $this->assertFalse(UploadPathGuard::isWithinTarget($this->baseDir . '/', $filePath));
    }

    /**
     * A missing or misconfigured upload base makes realpath() return false;
     * the guard must fail closed rather than treating it as a match.
     */
    public function testMissingTargetDirectoryIsRejected(): void
    {
        // realpath() returns false for a path that does not exist
        $filePath = $this->baseDir . '/123/';
        $this->assertFalse(UploadPathGuard::isWithinTarget('/nonexistent/target/', $filePath));
    }

    /**
     * The traversal case the guard exists for: a destination that resolves
     * above the upload base must be rejected.
     */
    public function testParentDirectoryTraversalIsRejected(): void
    {
        // Attempt to write to the parent of the base dir
        $filePath = dirname($this->baseDir) . '/';
        $this->assertFalse(UploadPathGuard::isWithinTarget($this->baseDir . '/', $filePath));
    }

    // -------------------------------------------------------------------
    // Source code inspection: verify save.php implements the contract
    // -------------------------------------------------------------------

    /**
     * save.php::uploadImages() must delegate its path check to
     * UploadPathGuard::isWithinTarget() rather than reimplementing the
     * realpath()/prefix comparison inline. Asserts the full negated guard
     * statement rather than checking 'UploadPathGuard' and 'isWithinTarget('
     * as independent substrings — independent checks would still pass against
     * a save.php that reintroduced an inline duplicate with the class call
     * left dangling (or inverted) elsewhere in the file.
     */
    public function testSavePhpCallsUploadPathGuard(): void
    {
        $saveFile = dirname(__DIR__, 3) . '/app/api/cars/save.php';
        $content = (string) file_get_contents($saveFile);

        $this->assertStringContainsString(
            'if (!UploadPathGuard::isWithinTarget($targetFilePath, $filePath)) {',
            $content,
            'save.php must guard uploads via UploadPathGuard::isWithinTarget()'
        );
    }

    /**
     * A rejected path must actually block the upload, not just get logged.
     * The prior assertion only proves the guard condition is still wired —
     * it would stay green even if the throw beneath it were deleted, silently
     * downgrading the guard to a log-only warning. Asserting the throw is
     * present too closes that gap: a deliberately weakened guard (condition
     * present, enforcement removed) now fails this test.
     */
    public function testSavePhpThrowsWhenGuardRejects(): void
    {
        $saveFile = dirname(__DIR__, 3) . '/app/api/cars/save.php';
        $content = (string) file_get_contents($saveFile);

        $this->assertStringContainsString(
            'throw new ImageProcessingException("Invalid upload path detected");',
            $content,
            'save.php must throw when UploadPathGuard::isWithinTarget() rejects the path'
        );
    }

    /**
     * uploadImages() must build the final image order with
     * CarImageProcessor::resolveUploadOrder() and log the names it drops. A
     * return to an isValidFilename() filter would delete every legacy-named
     * stored photo on a save with a new upload (#2609). The body is cut out
     * of the file so that the other isValidFilename() callers in save.php do
     * not hide a regression here.
     */
    public function testUploadImagesUsesResolveUploadOrderAndLogsDroppedNames(): void
    {
        $saveFile = dirname(__DIR__, 3) . '/app/api/cars/save.php';
        $content = (string) file_get_contents($saveFile);

        $start = strpos($content, 'function uploadImages(');
        $this->assertNotFalse($start, 'save.php must define uploadImages()');
        $end = strpos($content, "\n/**", $start);
        $this->assertNotFalse($end, 'uploadImages() must be followed by another docblock');
        $body = substr($content, $start, $end - $start);

        // Without the stored names, every legacy photo drops again and the
        // resolveUploadOrder() unit tests still pass.
        $this->assertMatchesRegularExpression(
            '/\$storedImage\s*=.*?->data\(\)->image.*?\$storedNames\s*=.*?'
            . 'CarImageProcessor::resolveUploadOrder\(\s*\$requestedOrder\s*,\s*\$newNames\s*,\s*\$storedNames\s*\)/s',
            $body,
            'uploadImages() must read cars.image into $storedNames and pass it to resolveUploadOrder()'
        );
        // The regex above still matches if $storedNames is reset before the call.
        $this->assertSame(
            2,
            preg_match_all('/\$storedNames\s*=/', $body),
            'uploadImages() must assign $storedNames only as [] and from cars.image'
        );
        $this->assertMatchesRegularExpression(
            "/array_diff\\(\\s*\\\$newNames\\s*,\\s*\\\$result\\['kept'\\]\\s*\\).*?\\\$errors\\[\\]\\s*=/s",
            $body,
            'uploadImages() must fail the save when an uploaded file is not in the kept order'
        );
        $this->assertMatchesRegularExpression(
            "/\\['dropped'\\].*?logger\\(/s",
            $body,
            'uploadImages() must log the dropped names'
        );
        $this->assertStringNotContainsString(
            'isValidFilename(',
            $body,
            'uploadImages() must not filter stored names with isValidFilename() (#2609)'
        );
    }

    /**
     * save.php must be syntactically valid PHP so that it can be included
     * by the application without parse errors.
     */
    public function testSavePhpIsValidPhp(): void
    {
        $saveFile = dirname(__DIR__, 3) . '/app/api/cars/save.php';
        $output = [];
        $returnCode = 0;
        exec("php -l " . escapeshellarg($saveFile), $output, $returnCode);

        $this->assertSame(0, $returnCode, 'save.php must pass PHP syntax check (php -l)');
    }
}
