<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real-DB behavioral test for VerificationSettings::cronReady() /
 * lastCronRequestAt()'s DENIED-row filter (#1926).
 *
 * `users/cron/cron.php` writes a `CronRequest` log row on every hit,
 * including ones its own `cron_ip` allowlist then denies ("Cron request
 * DENIED from $ip."). Before this fix, lastCronRequestAt()'s query had no
 * filter on lognote, so a denied hit would look identical to a healthy one —
 * cronReady() could report the cron transport healthy while every real
 * request to it was actually being rejected. The fix adds a
 * `NOT LIKE '%DENIED%'` filter; this test proves it against a real `logs`
 * table rather than the unit suite's source-text-only guarantee
 * (VerificationSettingsTest::testLastCronRequestAtQueryExcludesDeniedRows).
 */
#[Group('integration')]
final class VerificationSettingsCronReadyTest extends IntegrationTestCase
{
    /** @var int[] logs.id rows created during this test, cleaned up in tearDown() */
    private array $createdLogIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->createdLogIds = [];
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            foreach ($this->createdLogIds as $id) {
                $this->db->query('DELETE FROM logs WHERE id = ?', [$id]);
            }
        }

        parent::tearDown();
    }

    private function insertCronRequestLog(string $lognote, string $logdate): void
    {
        $this->db->insert('logs', [
            'user_id' => 0,
            'logdate' => $logdate,
            'logtype' => LogCategories::LOG_CATEGORY_CRON_REQUEST,
            'lognote' => $lognote,
            'ip' => '203.0.113.99',
        ]);
        $this->assertFalse($this->db->error(), 'Failed to insert CronRequest log fixture: ' . $this->db->errorString());
        $this->createdLogIds[] = (int) $this->db->lastId();
    }

    /**
     * A recent DENIED row must be treated as if no cron request happened at
     * all — cronReady() must report false, not "healthy because something
     * recent exists".
     */
    public function testDeniedCronRequestLogIsIgnoredByCronReady(): void
    {
        $recentTimestamp = (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->insertCronRequestLog('Cron request DENIED from 198.51.100.7.', $recentTimestamp);

        $settings = new VerificationSettings($this->db);

        $this->assertNull(
            $settings->lastCronRequestAt(),
            'A DENIED cron request must not count as a real cron hit'
        );
        $this->assertFalse(
            $settings->cronReady(),
            'cronReady() must not report healthy off the back of a denied request'
        );
    }

    /**
     * A genuine (non-denied) recent row is still correctly picked up — this
     * is the control case proving the filter excludes DENIED specifically,
     * not CronRequest rows in general.
     */
    public function testNonDeniedCronRequestLogIsStillHonoredByCronReady(): void
    {
        $recentTimestamp = (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->insertCronRequestLog('Cron request processed.', $recentTimestamp);

        $settings = new VerificationSettings($this->db);

        $lastRequestAt = $settings->lastCronRequestAt();
        $this->assertNotNull($lastRequestAt, 'A genuine cron request must be picked up');
        $this->assertSame($recentTimestamp, $lastRequestAt->format('Y-m-d H:i:s'));
        $this->assertTrue($settings->cronReady());
    }

    /**
     * A recent DENIED row alongside an older genuine row: the DENIED row must
     * not be selected as "most recent" just because MAX(logdate) would
     * otherwise favor it — the genuine, older row is what should be reported.
     */
    public function testDeniedRowDoesNotShadowAnOlderGenuineRow(): void
    {
        $olderGenuineTimestamp = (new DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s');
        $newerDeniedTimestamp = (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');

        $this->insertCronRequestLog('Cron request processed.', $olderGenuineTimestamp);
        $this->insertCronRequestLog('Cron request DENIED from 198.51.100.7.', $newerDeniedTimestamp);

        $settings = new VerificationSettings($this->db);

        $lastRequestAt = $settings->lastCronRequestAt();
        $this->assertNotNull($lastRequestAt);
        $this->assertSame(
            $olderGenuineTimestamp,
            $lastRequestAt->format('Y-m-d H:i:s'),
            'The newer DENIED row must not be reported as the last cron request'
        );
    }
}
