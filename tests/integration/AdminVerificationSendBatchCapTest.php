<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Cron\SendVerificationBatchJob;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2330: the `verification_send_batch` POST in app/admin/index.php cuts an
 * oversize car_ids[] to the configured batch size, before it filters ids.
 * The cut writes one log row and shows one admin message.
 *
 * The request runs the real page through invokeEndpoint(). No submitted id
 * names an existing car, so no email is sent. The test needs no Brevo setup.
 *
 * #2457: a nested car_ids[] element is rejected by its type, not cast to car 1.
 * A valid id for a car that does not exist still reaches the car lookup.
 *
 * The test changes `er_verification_settings.batch_size` and the
 * `er_cron_job_runs.enabled` flag of the send job. tearDown restores both.
 */
#[Group('integration')]
#[Group('admin')]
final class AdminVerificationSendBatchCapTest extends IntegrationTestCase
{
    private const PERMISSION_ADMIN = 2;

    private int $adminId = 0;
    private ?int $originalBatchSize = null;
    private ?int $originalCronEnabled = null;

    /** @var array<string, mixed>|null The whole id = 1 row, saved by a test that deletes it */
    private ?array $deletedSettingsRow = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->deletedSettingsRow = null;

        // securePage() treats `users.permissions = 0` as a banned account.
        $this->adminId = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($this->adminId, self::PERMISSION_ADMIN);

        $batch = $this->db->query('SELECT batch_size FROM er_verification_settings WHERE id = 1')->first();
        $this->assertIsObject($batch, 'er_verification_settings must have its id = 1 row');
        $this->originalBatchSize = (int) $batch->batch_size;

        $cron = $this->db->query(
            'SELECT enabled FROM er_cron_job_runs WHERE job_name = ?',
            [SendVerificationBatchJob::JOB_NAME]
        )->first();
        $this->assertIsObject($cron, 'er_cron_job_runs must have the send job row');
        $this->originalCronEnabled = (int) $cron->enabled;

        $this->db->query('UPDATE er_verification_settings SET batch_size = 2 WHERE id = 1');
        $this->assertFalse($this->db->error(), 'Test setup: batch size: ' . $this->db->errorString());
        $this->db->query(
            'UPDATE er_cron_job_runs SET enabled = 1 WHERE job_name = ?',
            [SendVerificationBatchJob::JOB_NAME]
        );
        $this->assertFalse($this->db->error(), 'Test setup: cron enabled: ' . $this->db->errorString());
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            // First, so the UPDATE below finds the row again.
            if ($this->deletedSettingsRow !== null) {
                $columns = array_keys($this->deletedSettingsRow);
                $this->db->query(
                    'INSERT INTO er_verification_settings (' . implode(', ', $columns) . ') VALUES ('
                        . implode(', ', array_fill(0, count($columns), '?')) . ')',
                    array_values($this->deletedSettingsRow)
                );
                if ($this->db->error()) {
                    // An assert here would hide the real test failure.
                    fwrite(STDERR, 'tearDown: settings row restore failed: ' . $this->db->errorString() . "\n");
                }
                // The read logs as user 0, which the user_id delete below misses.
                $this->db->query(
                    "DELETE FROM logs WHERE user_id = 0 AND logtype = 'VerificationConfigWarning'"
                    . " AND lognote LIKE 'er_verification_settings%'"
                    . " AND logdate >= NOW() - INTERVAL 10 MINUTE"
                );
            }
            if ($this->originalBatchSize !== null) {
                $this->db->query('UPDATE er_verification_settings SET batch_size = ? WHERE id = 1', [$this->originalBatchSize]);
            }
            if ($this->originalCronEnabled !== null) {
                $this->db->query(
                    'UPDATE er_cron_job_runs SET enabled = ? WHERE job_name = ?',
                    [$this->originalCronEnabled, SendVerificationBatchJob::JOB_NAME]
                );
            }
            if ($this->adminId > 0) {
                $this->db->query('DELETE FROM logs WHERE user_id = ?', [$this->adminId]);
            }
        }
        parent::tearDown();
    }

    /**
     * Run app/admin/index.php as the admin with a POST of verification_send_batch.
     *
     * @param list<string|list<string>> $carIds
     * @return array{exitCode: int, raw: string}
     */
    private function postSendBatch(array $carIds): array
    {
        $result = $this->invokeEndpoint(
            'app/admin/index.php',
            ['command' => 'verification_send_batch', 'car_ids' => $carIds],
            $this->adminId
        );

        // The page prints the admin message in its HTML, so it is in raw.
        return ['exitCode' => $result['exitCode'], 'raw' => $result['raw']];
    }

    public function testFiveIdsWithBatchSizeTwoLogsTheCutAndShowsTheAdminMessage(): void
    {
        $result = $this->postSendBatch(['0', '-1', '-2', '-3', '-4']);

        $this->assertSame(0, $result['exitCode'], 'The page must run to the end: ' . substr($result['raw'], 0, 500));

        $cutLog = 'Verification send: car_ids[] carried 5 ids, exceeding the configured batch size of 2; truncated to the first 2.';
        $this->assertSame(1, $this->countMatchingLogs(LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $cutLog, $this->adminId), 'The cut writes one log row that names both numbers');

        $this->assertStringContainsString(
            'Only the first 2 cars were sent',
            $result['raw'],
            'The admin sees the cut message'
        );

        // The cut runs before the id filter, so only two ids reach it.
        $invalidRows = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            'Verification send: invalid car id in car_ids[]%',
            $this->adminId
        );
        $this->assertSame(2, $invalidRows, 'Only the first two ids reach the id filter');
    }

    public function testIdsWithinTheBatchSizeWriteNoCutLogAndShowNoCutMessage(): void
    {
        $result = $this->postSendBatch(['0', '-1']);

        $this->assertSame(0, $result['exitCode'], 'The page must run to the end: ' . substr($result['raw'], 0, 500));
        $this->assertStringNotContainsString('Only the first', $result['raw']);
        $count = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            '%exceeding the configured batch size%',
            $this->adminId
        );
        $this->assertSame(0, $count, 'Control: two ids with batch size two write no cut row');
    }

    public function testNestedCarIdElementLogsANonScalarRowAndReadsNoCar(): void
    {
        $result = $this->postSendBatch(['0', ['7']]);

        $this->assertSame(0, $result['exitCode'], 'The page must run to the end: ' . substr($result['raw'], 0, 500));

        $nonScalarRows = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            'Verification send: non-scalar car id in car_ids[]: array',
            $this->adminId
        );
        $this->assertSame(1, $nonScalarRows, 'The nested element writes one row that names its type');

        $nonPositiveRows = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            'Verification send: invalid car id in car_ids[]%',
            $this->adminId
        );
        $this->assertSame(1, $nonPositiveRows, 'The scalar 0 keeps its non-positive row');

        // An (int) cast turns ['7'] into car 1. A car read writes one of these rows.
        $carReadRows = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            'Verification send: car %',
            $this->adminId
        ) + $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            'Verification send: findById threw%',
            $this->adminId
        );
        $this->assertSame(0, $carReadRows, 'No submitted value reaches findById()');

        $this->assertSame(1, $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            'Verification send: batch complete — 0 sent, 0 unrecorded, 2 skipped, 0 failed',
            $this->adminId
        ), 'Each rejected value is one skipped row');
    }

    /**
     * A valid id must still reach processBatch(). If the handler passed an
     * empty list, every batch would report "0 sent" with no error.
     */
    public function testValidIdReachesTheCarLookupAfterTheFilter(): void
    {
        $missingId = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) + 1000 AS id FROM cars')->first()->id;

        $result = $this->postSendBatch(['0', (string) $missingId]);

        $this->assertSame(0, $result['exitCode'], 'The page must run to the end: ' . substr($result['raw'], 0, 500));

        $this->assertSame(1, $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            "Verification send: car {$missingId} was in the preview but no longer exists; skipped",
            $this->adminId
        ), 'The valid id reaches findById() and takes the not-found path');

        $this->assertSame(1, $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            'Verification send: batch complete — 0 sent, 0 unrecorded, 2 skipped, 0 failed',
            $this->adminId
        ), 'The rejected 0 and the missing car are each one skipped row');
    }

    /**
     * #2572: a batch size that cannot be read stops the send. The id '0' would
     * write an "invalid car id" row if the handler went on with a default size.
     */
    public function testUnreadableBatchSizeSendsNothingAndTheAdminMessageNamesTheConfigWarningLog(): void
    {
        $row = $this->db->query('SELECT * FROM er_verification_settings WHERE id = 1')->first();
        $this->assertIsObject($row, 'Test setup: the id = 1 row exists before the delete');
        $this->deletedSettingsRow = (array) $row;
        $this->db->query('DELETE FROM er_verification_settings WHERE id = 1');
        $this->assertFalse($this->db->error(), 'Test setup: delete row: ' . $this->db->errorString());
        $this->assertSame(0, $this->db->query('SELECT id FROM er_verification_settings WHERE id = 1')->count(), 'Test setup: the row is gone');

        $result = $this->postSendBatch(['0']);

        $this->assertSame(0, $result['exitCode'], 'The page must run to the end: ' . substr($result['raw'], 0, 500));
        $this->assertStringContainsString(
            'The batch size could not be read, so no email was sent. Check the system log for VerificationConfigWarning entries.',
            $result['raw'],
            'The admin message names the log to check'
        );
        $this->assertSame(
            0,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_CAR_VERIFICATION, 'Verification send: invalid car id%', $this->adminId),
            'The handler stopped before it filtered the ids'
        );
        $this->assertSame(
            0,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_CAR_VERIFICATION, 'Verification send: batch complete%', $this->adminId),
            'The handler did not run the send loop'
        );
        $this->assertSame(
            1,
            $this->countMatchingLogs(
                LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
                'Verification send: manual send blocked — batch size unreadable%',
                $this->adminId
            ),
            'The blocked send writes one CarVerification row for the admin'
        );
        $this->assertGreaterThanOrEqual(
            1,
            $this->countMatchingLogs(
                LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
                'er_verification_settings%',
                0
            ),
            'The failed read wrote a VerificationConfigWarning line'
        );
    }

    /**
     * A rejected id comes from the form. A CR or LF in it must not forge a
     * log line, and a long value must not fill the log.
     */
    public function testRejectedCarIdLogStripsControlCharactersAndIsCut(): void
    {
        $prefix = 'Verification send: invalid car id in car_ids[]: ';

        $result = $this->postSendBatch(["1\r\nFAKE", str_repeat('9a', 100)]);

        $this->assertSame(0, $result['exitCode'], 'The page must run to the end: ' . substr($result['raw'], 0, 500));

        $rows = $this->db->query(
            'SELECT lognote FROM logs WHERE logtype = ? AND user_id = ? AND lognote LIKE ?',
            [LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $this->adminId, 'Verification send: invalid car id%']
        )->results();
        $this->assertFalse($this->db->error(), 'Log read: ' . $this->db->errorString());
        $this->assertCount(2, $rows, 'Each rejected scalar writes one row');

        foreach ($rows as $row) {
            $note = (string) $row->lognote;
            $this->assertStringStartsWith($prefix, $note);
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $note, 'No control character reaches the log');
            $this->assertLessThanOrEqual(64, mb_strlen(substr($note, strlen($prefix))), 'The logged value is cut to 64 characters');
        }
        $this->assertContains(
            $prefix . "'1FAKE'",
            array_map(static fn (object $row): string => (string) $row->lognote, $rows),
            'The strip removes only the control characters'
        );
    }
}
