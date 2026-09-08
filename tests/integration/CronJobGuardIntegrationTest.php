<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Cron\CronJobGuard;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real-DB behavioral test for CronJobGuard::claim() against the
 * `er_cron_job_runs` table (#2034).
 *
 * Named CronJobGuardIntegrationTest, not CronJobGuardTest, to avoid
 * duplicating tests/unit/cron/CronJobGuardTest.php's global class name — the
 * two are only ever loaded together by phpunit.xml's default (no -c flag)
 * config, which combines the Unit and Integration testsuites into a single
 * process and previously fataled with "Cannot redeclare class" the moment
 * both files were parsed. composer test:full's split -c phpunit-unit.xml /
 * -c phpunit-integration.xml invocations run each suite as a separate
 * process and never hit this, which is why it went unnoticed.
 *
 * tests/unit/cron/CronJobGuardTest.php already covers claim()'s logic
 * against a fake database — this file exists to prove the real SQL actually
 * behaves as expected against real MySQL boolean/datetime column types and
 * the `job_name` primary key, which a mocked-DB unit test cannot fully prove
 * (this repo's own convention for new SQL: execute it, don't just read it).
 *
 * Uses the seeded `reconciliation` row (created by the
 * 20260908203118_create_cron_job_runs migration) as its fixture, snapshotting
 * and restoring its `enabled`/`last_run_at` columns the same way
 * VerificationSettingsCronReadyTest.php snapshots and restores
 * er_verification_settings.last_cron_request_at.
 */
#[Group('integration')]
final class CronJobGuardIntegrationTest extends IntegrationTestCase
{
    private const JOB_NAME = 'reconciliation';

    /** Original enabled value for the 'reconciliation' row, restored in tearDown(). */
    private bool $originalEnabled = true;

    /** Original last_run_at value for the 'reconciliation' row, restored in tearDown(). */
    private ?string $originalLastRunAt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->db->query(
            'SELECT enabled, last_run_at FROM er_cron_job_runs WHERE job_name = ?',
            [self::JOB_NAME]
        );
        $this->assertFalse(
            $this->db->error(),
            'Failed to read original er_cron_job_runs row: ' . $this->db->errorString()
                . ' — likely means this migration has not been applied to the test schema'
        );
        $row = $this->db->first();
        $this->assertIsObject($row, "er_cron_job_runs must already have a seeded '" . self::JOB_NAME . "' row");

        $this->originalEnabled = (bool) $row->enabled;
        $this->originalLastRunAt = !empty($row->last_run_at) ? (string) $row->last_run_at : null;
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->db->query(
                'UPDATE er_cron_job_runs SET enabled = ?, last_run_at = ? WHERE job_name = ?',
                [$this->originalEnabled ? 1 : 0, $this->originalLastRunAt, self::JOB_NAME]
            );
        }

        parent::tearDown();
    }

    private function setFixtureState(bool $enabled, ?string $lastRunAt): void
    {
        $this->db->query(
            'UPDATE er_cron_job_runs SET enabled = ?, last_run_at = ? WHERE job_name = ?',
            [$enabled ? 1 : 0, $lastRunAt, self::JOB_NAME]
        );
        $this->assertFalse($this->db->error(), 'Failed to set er_cron_job_runs fixture: ' . $this->db->errorString());
    }

    private function fetchLastRunAt(): ?string
    {
        $this->db->query('SELECT last_run_at FROM er_cron_job_runs WHERE job_name = ?', [self::JOB_NAME]);
        $row = $this->db->first();
        $this->assertIsObject($row);

        return !empty($row->last_run_at) ? (string) $row->last_run_at : null;
    }

    public function testClaimSucceedsForANeverRunEnabledJob(): void
    {
        $this->setFixtureState(enabled: true, lastRunAt: null);

        $guard = new CronJobGuard($this->db);

        $this->assertTrue($guard->claim(self::JOB_NAME, 24));
        $this->assertNotNull(
            $this->fetchLastRunAt(),
            'A successful claim must actually update last_run_at in the real table'
        );
    }

    public function testClaimNoOpsWhenJobDisabled(): void
    {
        // Claimable interval-wise (NULL last_run_at), but disabled — the
        // `enabled = 1` clause in claim()'s WHERE must still block it.
        $this->setFixtureState(enabled: false, lastRunAt: null);

        $guard = new CronJobGuard($this->db);

        $this->assertFalse($guard->claim(self::JOB_NAME, 24));
        $this->assertNull(
            $this->fetchLastRunAt(),
            'A disabled job claim must not update last_run_at'
        );
    }

    public function testClaimFailsWhenAlreadyClaimedWithinInterval(): void
    {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $this->setFixtureState(enabled: true, lastRunAt: $now);

        $guard = new CronJobGuard($this->db);

        $this->assertFalse($guard->claim(self::JOB_NAME, 24));
        $this->assertSame(
            $now,
            $this->fetchLastRunAt(),
            'A too-recent claim must not update last_run_at'
        );
    }

    /**
     * The `< NOW() - INTERVAL ? HOUR` comparison in claim()'s WHERE clause is
     * strictly exclusive: a last_run_at exactly `$intervalHours` old does NOT
     * count as elapsed, only one strictly older does. This can only be
     * proven against real MySQL datetime arithmetic — a fake database has no
     * actual notion of elapsed time to get right or wrong, so this boundary
     * was previously asserted only via a unit test checking the SQL text
     * contains `< NOW() - INTERVAL ? HOUR` (a string, not a behavior) — a
     * change from `<` to `<=` would have passed every existing test.
     */
    public function testClaimBoundaryIsStrictlyExclusive(): void
    {
        $exactlyAtInterval = (new DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
        $this->setFixtureState(enabled: true, lastRunAt: $exactlyAtInterval);
        $guard = new CronJobGuard($this->db);

        $this->assertFalse(
            $guard->claim(self::JOB_NAME, 24),
            'A last_run_at exactly 24 hours old must NOT be claimable — the boundary is strictly exclusive'
        );

        $justOverInterval = (new DateTimeImmutable('-24 hours -1 second'))->format('Y-m-d H:i:s');
        $this->setFixtureState(enabled: true, lastRunAt: $justOverInterval);

        $this->assertTrue(
            $guard->claim(self::JOB_NAME, 24),
            'A last_run_at more than 24 hours old must be claimable'
        );
    }

    /**
     * Two sequential claim()s against the real connection: the atomic
     * conditional UPDATE must self-guard even without an interval elapsing
     * between them, proving the single-statement UPDATE (not just PHP-level
     * logic) is what enforces one winner. True concurrent-connection racing
     * isn't practical in PHPUnit; sequential calls against the real DB are
     * the closest available proxy for the atomicity property this class
     * exists to provide.
     */
    public function testSequentialClaimsOnlyFirstSucceeds(): void
    {
        $this->setFixtureState(enabled: true, lastRunAt: null);
        $guard = new CronJobGuard($this->db);

        $this->assertTrue($guard->claim(self::JOB_NAME, 24));
        $this->assertFalse(
            $guard->claim(self::JOB_NAME, 24),
            'A second claim immediately after the first must not also succeed'
        );
    }
}
