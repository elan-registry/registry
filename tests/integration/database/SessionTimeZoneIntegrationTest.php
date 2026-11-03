<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Database\SessionTimeZone;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration test for SessionTimeZone (#2589).
 *
 * MySQL NOW() and PHP date() must agree within a minute after the hook runs.
 * The first test pins the session to UTC, so the gap is hours in every month
 * of the year. A server zone that happens to match PHP cannot hide an apply()
 * that does not set PHP's zone. The second test starts a fresh PHP process
 * through users/init.php, the path that every request and cron run takes. It
 * detects a missing loader.php hook only while the server zone differs from
 * PHP's current UTC offset: the Docker db is -07:00, so from November to March.
 * SessionTimeZoneTest checks the hook in the source all year. The third test
 * writes a winter and a summer TIMESTAMP and checks the stored UTC instant.
 * A fixed offset stores one of them 1 hour off, in every month of the year.
 */
#[Group('integration')]
#[Group('database')]
final class SessionTimeZoneIntegrationTest extends IntegrationTestCase
{
    private const FIXTURE = 'tests/integration/fixtures/clock-probe.php';
    private const MAX_GAP_SECONDS = 60;

    protected function tearDown(): void
    {
        // Later tests share this connection. Put the session clock back on PHP time.
        if ($this->databaseConnected) {
            SessionTimeZone::apply($this->db);
        }
        parent::tearDown();
    }

    public function testApplyBringsTheSessionClockOntoPhpTime(): void
    {
        $this->requireDatabase();

        $this->db->query("SET time_zone = '+00:00'");
        $this->assertFalse($this->db->error(), 'Test setup: SET time_zone must succeed');
        $this->assertGreaterThan(
            self::MAX_GAP_SECONDS,
            $this->clockGapSeconds(),
            'Precondition: a UTC session must differ from Pacific PHP time, or the test proves nothing'
        );

        SessionTimeZone::apply($this->db);

        $this->assertLessThanOrEqual(self::MAX_GAP_SECONDS, $this->clockGapSeconds());
    }

    public function testFreshPhpProcessHasMysqlClockOnPhpTime(): void
    {
        $this->requireDatabase();

        $result = $this->invokeEndpoint(self::FIXTURE, [], null, 'omit');

        $this->assertSame(0, $result['exitCode'], 'The probe must exit 0. Output: ' . $result['raw']);
        $this->assertIsArray($result['json'], 'The probe must print JSON. Output: ' . $result['raw']);
        $dbNow = strtotime((string) ($result['json']['db_now'] ?? ''));
        $phpNow = strtotime((string) ($result['json']['php_now'] ?? ''));
        $this->assertNotFalse($dbNow);
        $this->assertNotFalse($phpNow);
        $this->assertLessThanOrEqual(
            self::MAX_GAP_SECONDS,
            abs($dbNow - $phpNow),
            "NOW() was {$result['json']['db_now']}, PHP date() was {$result['json']['php_now']}"
        );
    }

    public function testWinterAndSummerTimestampsStoreTheTrueUtcInstant(): void
    {
        $this->requireDatabase();

        SessionTimeZone::apply($this->db);
        // PHP date() text for two UTC instants, as the application writes it.
        $winterInstant = gmmktime(18, 0, 0, 1, 15, 2027);
        $summerInstant = gmmktime(17, 0, 0, 7, 15, 2027);
        $winter = date('Y-m-d H:i:s', $winterInstant);
        $summer = date('Y-m-d H:i:s', $summerInstant);

        $this->db->query('CREATE TEMPORARY TABLE tz_probe (id INT PRIMARY KEY, ts TIMESTAMP NULL)');
        $this->assertFalse($this->db->error(), 'Test setup: CREATE TEMPORARY TABLE failed: ' . $this->db->errorString());
        try {
            $this->db->query('INSERT INTO tz_probe (id, ts) VALUES (1, ?), (2, ?)', [$winter, $summer]);
            $this->assertFalse($this->db->error(), 'INSERT failed: ' . $this->db->errorString());

            // A write and a read in the same fixed offset give back the same
            // text, so check the stored UTC instant too. UNIX_TIMESTAMP() of a
            // TIMESTAMP column reads the stored value with no zone conversion.
            $this->db->query(
                "SELECT DATE_FORMAT(ts, '%Y-%m-%d %H:%i:%s') AS ts, UNIX_TIMESTAMP(ts) AS utc FROM tz_probe ORDER BY id"
            );
            $this->assertFalse($this->db->error(), 'SELECT failed: ' . $this->db->errorString());
            $rows = $this->db->results();

            $this->assertSame($winter, $rows[0]->ts, 'The January TIMESTAMP must read back as written');
            $this->assertSame($summer, $rows[1]->ts, 'The July TIMESTAMP must read back as written');
            $this->assertSame($winterInstant, (int) $rows[0]->utc, 'The January TIMESTAMP must store the true UTC instant');
            $this->assertSame($summerInstant, (int) $rows[1]->utc, 'The July TIMESTAMP must store the true UTC instant');
        } finally {
            $this->db->query('DROP TEMPORARY TABLE IF EXISTS tz_probe');
        }
    }

    /**
     * Absolute gap in seconds between MySQL NOW() and PHP's wall clock.
     */
    private function clockGapSeconds(): int
    {
        $this->db->query('SELECT ABS(TIMESTAMPDIFF(SECOND, NOW(), ?)) AS gap', [date('Y-m-d H:i:s')]);
        $this->assertFalse($this->db->error(), 'Clock query failed: ' . $this->db->errorString());

        return (int) $this->db->first()->gap;
    }
}
