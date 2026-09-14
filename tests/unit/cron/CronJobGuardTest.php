<?php

declare(strict_types=1);

use ElanRegistry\Cron\CronJobGuard;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\CronJobGuardFakeDatabase;

require_once __DIR__ . '/../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../Support/CronJobGuardFakeDatabase.php';

/**
 * Unit tests for CronJobGuard — the atomic-claim guard used to prevent
 * duplicate/overlapping scheduled cron runs (#2027, #2034).
 */
#[Group('fast')]
final class CronJobGuardTest extends TestCase
{
    public function testClaimSucceedsOnFirstRunWithNullLastRunAt(): void
    {
        $db = new CronJobGuardFakeDatabase(claimSucceeds: true);

        $this->assertTrue((new CronJobGuard($db))->claim('reconciliation', 24));
        $this->assertStringContainsString(
            'IS NULL',
            $db->lastSql(),
            'A first-ever run (NULL last_run_at) must be claimable — the NULL branch must stay in the SQL'
        );
    }

    public function testClaimFailsWhenAlreadyClaimedWithinInterval(): void
    {
        // Simulates two callers racing for the same guard row: the first
        // claim() call wins, the second (same fake, same guard instance)
        // must lose — proving the guard doesn't unconditionally succeed.
        $db = new CronJobGuardFakeDatabase(claimSucceedsOnce: true);
        $guard = new CronJobGuard($db);

        $this->assertTrue($guard->claim('reconciliation', 24));
        $this->assertFalse($guard->claim('reconciliation', 24));
    }

    public function testClaimSucceedsAtIntervalBoundary(): void
    {
        $db = new CronJobGuardFakeDatabase(claimSucceeds: true);

        $this->assertTrue((new CronJobGuard($db))->claim('reconciliation', 24));
        $this->assertStringContainsString(
            '< NOW() - INTERVAL ? HOUR',
            $db->lastSql(),
            'The boundary condition must be expressed as an exclusive < comparison'
        );
    }

    public function testClaimUsesDatabaseClockNotPhpTime(): void
    {
        $db = new CronJobGuardFakeDatabase(claimSucceeds: true);

        (new CronJobGuard($db))->claim('reconciliation', 24);

        $this->assertStringContainsString('NOW()', $db->lastSql());
        $this->assertStringContainsString(
            'job_name = ?',
            $db->lastSql(),
            'The claim must be scoped to the specific job_name row'
        );
        $this->assertStringContainsString(
            'enabled = 1',
            $db->lastSql(),
            'The claim must gate on the row being enabled'
        );
        $this->assertSame(
            ['reconciliation', 24],
            $db->lastParams(),
            'The job name and interval hours must be bound — no PHP date()/time()-derived value'
        );
    }

    public function testClaimRejectsUnrecognizedJobName(): void
    {
        $db = new CronJobGuardFakeDatabase(claimSucceeds: true);

        $this->assertFalse((new CronJobGuard($db))->claim('not_a_real_job', 24));
        $this->assertSame(
            '',
            $db->lastSql(),
            'An unrecognized job name must be rejected before any query is issued'
        );
    }

    /**
     * `job_name` is `varchar(64)` under MySQL's default case-insensitive
     * collation — `job_name = 'RECONCILIATION'` matches the seeded
     * `'reconciliation'` row at the SQL layer. The allowlist's strict
     * `in_array(..., true)` check is the only thing preventing a
     * case-variant name from claiming a different job's row; if the
     * allowlist check were ever relaxed to case-insensitive comparison, the
     * DB layer would silently agree and two logically distinct job names
     * could share one guard row. This test documents that the strictness is
     * load-bearing, not incidental.
     */
    public function testClaimRejectsCaseVariantOfAllowedJobName(): void
    {
        $db = new CronJobGuardFakeDatabase(claimSucceeds: true);

        $this->assertFalse((new CronJobGuard($db))->claim('RECONCILIATION', 24));
        $this->assertSame(
            '',
            $db->lastSql(),
            'A case-variant of an allowed job name must be rejected before any query is issued'
        );
    }

    public function testClaimBindsIntervalHoursAsParameter(): void
    {
        $db = new CronJobGuardFakeDatabase(claimSucceeds: true);

        (new CronJobGuard($db))->claim('reconciliation', 48);

        $this->assertStringContainsString('?', $db->lastSql());
        $this->assertStringNotContainsString('48', $db->lastSql(), 'The interval must be bound, not interpolated');
        $this->assertSame(['reconciliation', 48], $db->lastParams());
    }

    /**
     * The fake's count() is driven purely by its constructor flags — it has
     * no concept of an `enabled` column, so it can't distinguish "the row
     * didn't match because it's disabled" from "the row didn't match because
     * the interval hasn't elapsed". Both produce count() === 0 from the
     * guard's perspective. That's exactly the point of putting `enabled = 1`
     * in the same WHERE clause as the interval check (see CronJobGuard's own
     * class docblock): a disabled job's claim silently no-ops the same way a
     * too-recent claim does, with no separate code path required.
     * claimSucceeds: false is already the "claim reports 0 rows changed"
     * case, so it doubles as the fixture for this scenario.
     */
    public function testClaimFailsWhenJobDisabled(): void
    {
        $db = new CronJobGuardFakeDatabase(claimSucceeds: false);

        $this->assertFalse((new CronJobGuard($db))->claim('reconciliation', 24));
    }

    /**
     * intervalHours < 1 is rejected before any query is issued — this
     * connection doesn't set MYSQL_ATTR_FOUND_ROWS, so count() reports
     * changed-rows, not matched-rows. An interval of 0 would let two claims
     * within the same second both match the WHERE clause while the second's
     * identical-value write reports 0 rows changed, making claim() report
     * false on a row that genuinely matched — and one second later, no
     * minimum interval would be enforced at all. See CronJobGuard::claim()'s
     * own inline comment for the full explanation.
     */
    public function testClaimRejectsIntervalHoursOfZero(): void
    {
        $db = new CronJobGuardFakeDatabase(claimSucceeds: true);

        $this->assertFalse((new CronJobGuard($db))->claim('reconciliation', 0));
        $this->assertSame(
            '',
            $db->lastSql(),
            'An interval of 0 must be rejected before any query is issued'
        );
    }

    /**
     * A negative interval is the more dangerous case than 0: `NOW() -
     * INTERVAL -24 HOUR` is a future timestamp, so `last_run_at < <future>`
     * would match almost any existing row, turning the guard into a no-op
     * that permits unlimited concurrent claims. The `< 1` check must reject
     * this the same way it rejects 0 — tested separately, since a
     * `< 1` guard could be miswritten as `=== 0` and still pass a 0-only test.
     */
    public function testClaimRejectsNegativeIntervalHours(): void
    {
        $db = new CronJobGuardFakeDatabase(claimSucceeds: true);

        $this->assertFalse((new CronJobGuard($db))->claim('reconciliation', -1));
        $this->assertSame(
            '',
            $db->lastSql(),
            'A negative interval must be rejected before any query is issued'
        );
    }

    /**
     * error() is not independently asserted here: this codebase's established
     * logger() mock (tests/bootstrap-unit.php, tracked via the global
     * $mockLogEntries array) is used elsewhere for tests that need to verify
     * *what* was logged, but CronJobGuard::claim() only needs to prove it
     * returns false on a database error without throwing — asserting the
     * exact log line is out of scope here and would duplicate coverage
     * already implied by the return-value assertion.
     *
     * claimSucceeds is explicitly true here (not the implicit default) so
     * count() would report a successful claim if error() were ignored —
     * proving the error check is checked before count(), not after.
     */
    public function testClaimReturnsFalseOnDatabaseError(): void
    {
        $db = new CronJobGuardFakeDatabase(claimSucceeds: true, queryErrors: true);

        $this->assertFalse((new CronJobGuard($db))->claim('reconciliation', 24));
    }
}
