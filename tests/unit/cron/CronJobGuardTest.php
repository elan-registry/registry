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
 * duplicate/overlapping scheduled cron runs (#2027).
 */
#[Group('fast')]
final class CronJobGuardTest extends TestCase
{
    public function testClaimSucceedsOnFirstRunWithNullColumn(): void
    {
        $db = new CronJobGuardFakeDatabase(claimSucceeds: true);

        $this->assertTrue((new CronJobGuard($db))->claim('reconciliation_last_run', 24));
        $this->assertStringContainsString(
            'IS NULL',
            $db->lastSql(),
            'A first-ever run (NULL column) must be claimable — the NULL branch must stay in the SQL'
        );
    }

    public function testClaimFailsWhenAlreadyClaimedWithinInterval(): void
    {
        // Simulates two callers racing for the same guard column: the first
        // claim() call wins, the second (same fake, same guard instance)
        // must lose — proving the guard doesn't unconditionally succeed.
        $db = new CronJobGuardFakeDatabase(claimSucceedsOnce: true);
        $guard = new CronJobGuard($db);

        $this->assertTrue($guard->claim('reconciliation_last_run', 24));
        $this->assertFalse($guard->claim('reconciliation_last_run', 24));
    }

    public function testClaimSucceedsAtIntervalBoundary(): void
    {
        $db = new CronJobGuardFakeDatabase(claimSucceeds: true);

        $this->assertTrue((new CronJobGuard($db))->claim('reconciliation_last_run', 24));
        $this->assertStringContainsString(
            '< NOW() - INTERVAL ? HOUR',
            $db->lastSql(),
            'The boundary condition must be expressed as an exclusive < comparison'
        );
    }

    public function testClaimUsesDatabaseClockNotPhpTime(): void
    {
        $db = new CronJobGuardFakeDatabase(claimSucceeds: true);

        (new CronJobGuard($db))->claim('reconciliation_last_run', 24);

        $this->assertStringContainsString('NOW()', $db->lastSql());
        $this->assertStringContainsString(
            'id = 1',
            $db->lastSql(),
            'The claim must be scoped to the settings singleton row'
        );
        $this->assertSame(
            [24],
            $db->lastParams(),
            'Only the interval hours must be bound — no PHP date()/time()-derived value'
        );
    }

    public function testClaimRejectsUnrecognizedColumnName(): void
    {
        $db = new CronJobGuardFakeDatabase(claimSucceeds: true);

        $this->assertFalse((new CronJobGuard($db))->claim('not_a_real_column', 24));
        $this->assertSame(
            '',
            $db->lastSql(),
            'An unrecognized column must be rejected before any query is issued'
        );
    }

    public function testClaimBindsIntervalHoursAsParameter(): void
    {
        $db = new CronJobGuardFakeDatabase(claimSucceeds: true);

        (new CronJobGuard($db))->claim('reconciliation_last_run', 48);

        $this->assertStringContainsString('?', $db->lastSql());
        $this->assertStringNotContainsString('48', $db->lastSql(), 'The interval must be bound, not interpolated');
        $this->assertSame([48], $db->lastParams());
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

        $this->assertFalse((new CronJobGuard($db))->claim('reconciliation_last_run', 24));
    }
}
