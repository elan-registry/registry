<?php

declare(strict_types=1);

use ElanRegistry\UploadPathGuard;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * uploadImages() must use the CarImageProcessor helpers for the image
     * list and stop the save on any error before the database write. The
     * decisions are tested in CarImageProcessorTest, so this test only
     * checks the wiring (#2626). The body is cut out of the file so that the
     * other callers in save.php do not hide a regression here.
     */
    public function testUploadImagesIsWiredToTheCarImageProcessorHelpers(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/app/api/cars/save.php');

        $start = strpos($content, 'function uploadImages(');
        $this->assertNotFalse($start, 'save.php must define uploadImages()');
        $end = strpos($content, "\n/**", $start);
        $this->assertNotFalse($end, 'uploadImages() must be followed by another docblock');
        $body = substr($content, $start, $end - $start);

        // On the add path the early return for no files comes first, so the
        // stored-name read is after it. Order is checked from that return.
        $earlyReturn = strpos($body, 'json_encode([])');
        $stored      = strpos($body, 'CarImageProcessor::storedImageFilenames(');
        $resolve     = strpos($body, 'CarImageProcessor::resolveUploadOrder(');
        $check       = strpos($body, 'CarImageProcessor::checkUploadOrder(');
        $this->assertNotFalse($earlyReturn, 'uploadImages() must clear the list for a new car with no files');
        $this->assertNotFalse($stored, 'uploadImages() must read the stored names with storedImageFilenames()');
        $this->assertNotFalse($resolve, 'uploadImages() must call resolveUploadOrder()');
        $this->assertNotFalse($check, 'uploadImages() must call checkUploadOrder()');
        $this->assertTrue(
            $earlyReturn < $stored && $stored < $resolve && $resolve < $check,
            'uploadImages() must read stored names, then resolve the order, then check it'
        );
        // Two returns are intended. The first is the no-files return for a new
        // car. An update with no files must reach resolve and check, or a
        // dropped or stored unsafe name leaves cars.image with no message
        // (#2626). The second return comes after validateUploadBatch() finds
        // a bad file, and before the first move (#1844).
        $batchCheck = strpos($body, 'CarImageProcessor::validateUploadBatch(');
        $firstMove  = strpos($body, 'move_uploaded_file(');
        $this->assertNotFalse($batchCheck, 'uploadImages() must call validateUploadBatch()');
        $this->assertNotFalse($firstMove, 'uploadImages() must call move_uploaded_file()');
        $this->assertSame(2, substr_count($body, 'return;'), 'uploadImages() must have two early returns only');
        $firstReturn  = (int) strpos($body, 'return;');
        $secondReturn = (int) strpos($body, 'return;', $firstReturn + 1);
        $this->assertLessThan($batchCheck, $firstReturn, 'the first early return is the no-files return, before the batch check');
        $this->assertGreaterThan($batchCheck, $secondReturn, 'the second early return must come after validateUploadBatch()');
        $this->assertLessThan($firstMove, $secondReturn, 'the second early return must come before move_uploaded_file()');
        $this->assertStringContainsString(
            "array_push(\$errors, ...\$batch['errors']);",
            substr($body, $batchCheck, $firstMove - $batchCheck),
            'uploadImages() must add the batch errors before it returns'
        );
        $this->assertStringContainsString(
            "if (!\$hasNewFiles && empty(\$cardetails['id'])) {",
            $body,
            'the early return must apply to a new car with no files only'
        );
        $this->assertMatchesRegularExpression(
            '/^    \$result = CarImageProcessor::resolveUploadOrder\(\$requestedOrder, \$newNames, \$storedNames\);$/m',
            $body,
            'uploadImages() must resolve the order with the stored names at function level, outside if ($hasNewFiles)'
        );
        $this->assertMatchesRegularExpression(
            '/^    \$check  = CarImageProcessor::checkUploadOrder\(\$result, \$newNames, \$failedNames\);$/m',
            $body,
            'uploadImages() must check the order at function level, outside if ($hasNewFiles)'
        );
        $this->assertSame(
            1,
            substr_count($body, '$storedNames = CarImageProcessor::storedImageFilenames('),
            'uploadImages() must set the stored names from storedImageFilenames() once'
        );
        $this->assertStringContainsString(
            "array_push(\$errors, ...\$check['errors']);",
            $body,
            'uploadImages() must add the checkUploadOrder() errors to $errors'
        );
        $this->assertStringContainsString(
            "stored filename(s) fail isSafeFilename()",
            $body,
            'uploadImages() must log stored names that fail isSafeFilename() apart from dropped names'
        );
        // A return to an isValidFilename() filter deletes every legacy-named
        // stored photo on a save with a new upload (#2609).
        $this->assertStringNotContainsString(
            'isValidFilename(',
            $body,
            'uploadImages() must not filter stored names with isValidFilename() (#2609)'
        );
    }

    /**
     * Each save case must stop on $errors after uploadImages() and before the
     * database write, so that a photo error cannot end as "Car updated".
     */
    #[DataProvider('saveCaseProvider')]
    public function testSaveCaseStopsOnUploadErrorsBeforeDatabaseWrite(string $case, string $write): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/app/api/cars/save.php');

        $caseStart = strpos($content, 'case "' . $case . '":');
        $this->assertNotFalse($caseStart, "save.php must have case \"$case\"");
        $upload = strpos($content, 'uploadImages($cardetails, $errors);', $caseStart);
        $this->assertNotFalse($upload, "$case must call uploadImages()");
        $stop = strpos($content, 'if (!empty($errors)) {', $upload);
        $this->assertNotFalse($stop, "$case must check \$errors after uploadImages()");
        $dbWrite = strpos($content, $write . '($cardetails', $upload);
        $this->assertNotFalse($dbWrite, "$case must call $write()");
        $this->assertLessThan($dbWrite, $stop, "$case must stop on \$errors before $write()");
        $this->assertStringContainsString(
            '->send();',
            substr($content, $stop, $dbWrite - $stop),
            "$case must send an error response in the \$errors stop"
        );
    }

    /**
     * Both upload-error stops must send the errors as a list, so that the
     * owner sees one line for each bad photo. implode() would join them into
     * one line (#1844).
     */
    #[DataProvider('saveCaseProvider')]
    public function testUploadErrorStopPassesErrorsAsListNotImploded(string $case, string $write): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/app/api/cars/save.php');

        $caseStart = strpos($content, 'case "' . $case . '":');
        $this->assertNotFalse($caseStart, "save.php must have case \"$case\"");
        $upload = strpos($content, 'uploadImages($cardetails, $errors);', $caseStart);
        $this->assertNotFalse($upload, "$case must call uploadImages()");
        $stop = strpos($content, 'if (!empty($errors)) {', $upload);
        $this->assertNotFalse($stop, "$case must check \$errors after uploadImages()");
        $dbWrite = strpos($content, $write . '($cardetails', $upload);
        $this->assertNotFalse($dbWrite, "$case must call $write()");
        $branch = substr($content, $stop, $dbWrite - $stop);

        $this->assertMatchesRegularExpression('/validationError\(\s*\[\'general\' => \$errors\]/', $branch);
        $this->assertStringNotContainsString('implode(', $branch, "$case must not join the upload errors");
    }

    /**
     * Every error stop in save.php sends errors.general as a list. A joined
     * string makes car-edit.js show all errors on one line with a "general:"
     * label (#1844).
     */
    public function testNoErrorStopJoinsTheErrors(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/app/api/cars/save.php');

        $this->assertSame(5, substr_count($content, "['general' => \$errors]"), 'save.php must send each of its 5 error stops as a list');
        $this->assertDoesNotMatchRegularExpression('/(implode|join)\([^)]*\$errors\)/', $content, 'save.php must not join $errors');
    }

    /**
     * The batch check must run before the first file is moved, so that a
     * rejected batch writes no file to disk (#1844).
     */
    public function testUploadImagesChecksTheBatchBeforeMovingAnyFile(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/app/api/cars/save.php');
        $start = strpos($content, 'function uploadImages(');
        $this->assertNotFalse($start, 'save.php must define uploadImages()');
        $end = strpos($content, "\n/**", $start);
        $this->assertNotFalse($end, 'uploadImages() must be followed by another docblock');
        $body = substr($content, $start, $end - $start);

        $batch = strpos($body, 'validateUploadBatch(');
        $move  = strpos($body, 'move_uploaded_file(');
        $this->assertNotFalse($batch, 'uploadImages() must call validateUploadBatch()');
        $this->assertNotFalse($move, 'uploadImages() must call move_uploaded_file()');
        $this->assertLessThan($move, $batch, 'validateUploadBatch() must come before move_uploaded_file()');
    }

    /**
     * The client check in car-edit.js must use the same minimum as the
     * server, so edit.php must put MIN_FILE_BYTES in editCarConfig.
     */
    public function testEditPageGivesMinFileBytesToEditCarConfig(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/app/owner/cars/edit.php');

        $start = strpos($content, 'window.editCarConfig = {');
        $this->assertNotFalse($start, 'edit.php must define window.editCarConfig');
        $end = strpos($content, "\n};", $start);
        $this->assertNotFalse($end, 'editCarConfig must end with a closing brace');

        $this->assertStringContainsString(
            'minFileSize: <?= (int) \\ElanRegistry\\Car\\CarImageProcessor::MIN_FILE_BYTES ?>,',
            substr($content, $start, $end - $start),
            'editCarConfig must set minFileSize from CarImageProcessor::MIN_FILE_BYTES'
        );
    }

    /**
     * The client half of the min-size rule: car-edit.js must pass the
     * minimum to FilePond, and the submit check must block a new photo that
     * FilePond rejected (LOAD_ERROR on an item that is not LOCAL).
     */
    public function testCarEditJsUsesMinFileSizeAndBlocksRejectedPhotos(): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 3) . '/app/assets/js/car-edit.js');

        $this->assertStringContainsString('minFileSize: cfg.minFileSize', $content, 'car-edit.js must give minFileSize to FilePond');

        $start = strpos($content, 'const invalidNames');
        $this->assertNotFalse($start, 'car-edit.js must build invalidNames in the submit check');
        $end = strpos($content, 'invalidNames.length', $start);
        $this->assertNotFalse($end, 'the submit check must test invalidNames.length');
        $check = substr($content, $start, $end - $start);

        $this->assertStringContainsString('FilePond.FileOrigin.LOCAL', $check, 'the submit check must skip stored (LOCAL) photos');
        $this->assertStringContainsString('FilePond.FileStatus.LOAD_ERROR', $check, 'the submit check must find LOAD_ERROR items');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function saveCaseProvider(): array
    {
        return [
            'addCar'    => ['addCar', 'addCar'],
            'updateCar' => ['updateCar', 'updateCar'],
        ];
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
