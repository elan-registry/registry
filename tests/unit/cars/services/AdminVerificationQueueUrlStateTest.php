<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the `?status=`, `?window=`, `?show=`, and `?activity_window=`
 * allow-list checks that read $_GET in app/admin/index.php (#1896).
 *
 * These checks are inline script statements, not extracted functions — there
 * is no `validateQueueStatus($raw): string` to call directly. This test loads
 * the exact source slice that computes the four variables (via the same
 * source-slice + temp-file extraction IntegrationTestCase::
 * loadOwnerFieldDriftFunctions() uses for
 * app/admin/scripts/maintenance/26-Reconcile-Owner-Fields.php) with $_GET set
 * to one case, then asserts on the resulting variable. A test that
 * reimplemented the allow-list logic separately and compared outputs would be
 * tautological; this instead executes the real statements.
 *
 * If the source file is restructured so these markers move, extraction throws
 * with a message naming the file to update.
 */
final class AdminVerificationQueueUrlStateTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_GET['status'], $_GET['window'], $_GET['show'], $_GET['activity_window']);
        parent::tearDown();
    }

    /**
     * Extract and run the $queueStatus / $queueWindowDays / $queueShowLimit /
     * $activityWindowDays block from app/admin/index.php, starting at the
     * `verificationGetMappedChoice()` helper declaration (used by `window`
     * and `activity_window`) and ending just before the
     * `verifyHistoryFieldsForAdminAction` function declaration that follows
     * it in the file.
     *
     * @return array{status: string, window: ?int, show: int, activityWindow: ?int}
     */
    private function runQueueUrlStateBlock(): array
    {
        $scriptPath = __DIR__ . '/../../../../app/admin/index.php';
        $source = file_get_contents($scriptPath);
        if ($source === false) {
            throw new RuntimeException('Could not read ' . $scriptPath);
        }

        $startMarker = "if (!function_exists('verificationGetMappedChoice'))";
        $endMarker = 'if (!function_exists(\'verifyHistoryFieldsForAdminAction\'))';

        $startPos = strpos($source, $startMarker);
        $endPos = strpos($source, $endMarker);

        if ($startPos === false || $endPos === false || $endPos <= $startPos) {
            throw new RuntimeException(
                'Could not locate the queue URL-state block in ' . $scriptPath
                . ' — the script may have been restructured; update runQueueUrlStateBlock() to match.'
            );
        }

        $slice = substr($source, $startPos, $endPos - $startPos);

        $tempFile = tempnam(sys_get_temp_dir(), 'adminIndexQueueState_');
        if ($tempFile === false) {
            throw new RuntimeException('Could not create temp file for queue URL-state extraction');
        }

        $preamble = "<?php\ndeclare(strict_types=1);\nuse ElanRegistry\\Car\\CarRepository;\n";
        $returnStmt = "\nreturn [\$queueStatus, \$queueWindowDays, \$queueShowLimit, \$activityWindowDays];\n";
        $written = file_put_contents($tempFile, $preamble . $slice . $returnStmt);
        if ($written === false) {
            unlink($tempFile);
            throw new RuntimeException('Could not write extracted queue-state source to temp file');
        }

        try {
            [$status, $windowDays, $showLimit, $activityWindowDays] = require $tempFile;
        } finally {
            unlink($tempFile);
        }

        return [
            'status' => $status,
            'window' => $windowDays,
            'show' => $showLimit,
            'activityWindow' => $activityWindowDays,
        ];
    }

    // --- $queueStatus -------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function queueStatusAllowListProvider(): array
    {
        $cases = [];
        foreach (CarRepository::QUEUE_STATUSES as $status) {
            $cases["status={$status}"] = [$status];
        }
        return $cases;
    }

    #[DataProvider('queueStatusAllowListProvider')]
    public function testQueueStatusAcceptsEachAllowListValue(string $status): void
    {
        $_GET['status'] = $status;

        $result = $this->runQueueUrlStateBlock();

        $this->assertSame($status, $result['status']);
    }

    public function testQueueStatusFallsBackToAllForAnInvalidValue(): void
    {
        $_GET['status'] = 'not-a-real-status';

        $result = $this->runQueueUrlStateBlock();

        $this->assertSame('all', $result['status']);
    }

    public function testQueueStatusFallsBackToAllWhenAbsent(): void
    {
        unset($_GET['status']);

        $result = $this->runQueueUrlStateBlock();

        $this->assertSame('all', $result['status']);
    }

    // --- $queueWindowDays -----------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: ?int}>
     */
    public static function queueWindowAllowListProvider(): array
    {
        return [
            'window=7' => ['7', 7],
            'window=30' => ['30', 30],
            'window=90' => ['90', 90],
            'window=365' => ['365', 365],
            'window=0 (all time)' => ['0', null],
        ];
    }

    #[DataProvider('queueWindowAllowListProvider')]
    public function testQueueWindowAcceptsEachAllowListValue(string $raw, ?int $expected): void
    {
        $_GET['window'] = $raw;

        $result = $this->runQueueUrlStateBlock();

        $this->assertSame($expected, $result['window']);
    }

    public function testQueueWindowFallsBackTo30ForAnInvalidValue(): void
    {
        $_GET['window'] = '30abc';

        $result = $this->runQueueUrlStateBlock();

        $this->assertSame(30, $result['window']);
    }

    public function testQueueWindowFallsBackTo30ForANegativeValue(): void
    {
        $_GET['window'] = '-30';

        $result = $this->runQueueUrlStateBlock();

        $this->assertSame(30, $result['window']);
    }

    // --- $queueShowLimit ------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function queueShowAllowListProvider(): array
    {
        return [
            'show=10' => ['10', 10],
            'show=25' => ['25', 25],
            'show=50' => ['50', 50],
            'show=100' => ['100', 100],
        ];
    }

    #[DataProvider('queueShowAllowListProvider')]
    public function testQueueShowAcceptsEachAllowListValue(string $raw, int $expected): void
    {
        $_GET['show'] = $raw;

        $result = $this->runQueueUrlStateBlock();

        $this->assertSame($expected, $result['show']);
    }

    public function testQueueShowFallsBackTo25ForAnInvalidValue(): void
    {
        $_GET['show'] = '999';

        $result = $this->runQueueUrlStateBlock();

        $this->assertSame(25, $result['show']);
    }

    public function testQueueShowFallsBackTo25ForANonNumericValue(): void
    {
        $_GET['show'] = 'abc';

        $result = $this->runQueueUrlStateBlock();

        $this->assertSame(25, $result['show']);
    }

    // --- $activityWindowDays ---------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: ?int}>
     */
    public static function activityWindowAllowListProvider(): array
    {
        return [
            'activity_window=7' => ['7', 7],
            'activity_window=30' => ['30', 30],
            'activity_window=90' => ['90', 90],
            'activity_window=365' => ['365', 365],
            'activity_window=0 (all time)' => ['0', null],
        ];
    }

    #[DataProvider('activityWindowAllowListProvider')]
    public function testActivityWindowAcceptsEachAllowListValue(string $raw, ?int $expected): void
    {
        $_GET['activity_window'] = $raw;

        $result = $this->runQueueUrlStateBlock();

        $this->assertSame($expected, $result['activityWindow']);
    }

    public function testActivityWindowFallsBackTo30ForAnInvalidValue(): void
    {
        $_GET['activity_window'] = '45';

        $result = $this->runQueueUrlStateBlock();

        $this->assertSame(30, $result['activityWindow']);
    }

    public function testActivityWindowFallsBackTo30WhenAbsent(): void
    {
        unset($_GET['activity_window']);

        $result = $this->runQueueUrlStateBlock();

        $this->assertSame(30, $result['activityWindow']);
    }
}
