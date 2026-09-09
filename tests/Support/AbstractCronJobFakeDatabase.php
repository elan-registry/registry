<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * AbstractCronJobFakeDatabase - FakeDatabase double for AbstractCronJobTest
 *
 * AbstractCronJob::run() issues up to three distinct queries against the same
 * connection: its own `SELECT enabled, ... AS skip_log_is_due FROM
 * er_cron_job_runs ...` read, then either CronJobGuard's `UPDATE
 * er_cron_job_runs SET last_run_at ...` claim (when enabled) or its own
 * `UPDATE er_cron_job_runs SET last_skip_logged_at ...` throttle stamp (when
 * disabled and the skip line fired). This fake dispatches on the SQL so all
 * three can be answered from one double with independently controlled
 * outcomes — CronJobGuardFakeDatabase can only answer the claim, having no
 * concept of the `enabled` column.
 *
 * Unlike a canned-value stub, this fake models `last_skip_logged_at` as real
 * mutable state against a simulated clock ({@see self::advanceMinutes()}), so
 * a test can drive many cron hits through it and observe the throttle's
 * behaviour across interval boundaries rather than asserting on a
 * pre-computed answer. That matters here: the bug this fake exists to catch
 * (#1889) was a throttle that worked for exactly one interval and then logged
 * on every hit forever, which a fixed `skipIsDue` flag could not have
 * detected in either position.
 *
 * Deliberately a *named* class rather than `new class extends FakeDatabase { ... }`:
 * PHPStan reports `impureMethod.pure` when an anonymous class overrides one of
 * DatabaseInterface's `@phpstan-impure` methods (`query()`, `error()`,
 * `count()`, `first()` here) with a body that doesn't depend on mutable state.
 * See CronJobGuardFakeDatabase's docblock for the same rationale.
 *
 * @package Tests\Support
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1889
 */
class AbstractCronJobFakeDatabase extends FakeDatabase
{
    /** @var list<string> */
    private array $sqlLog = [];

    private bool $lastQueryWasClaim = false;

    /** Simulated wall clock, in minutes since an arbitrary epoch. */
    private int $nowMinutes = 0;

    /**
     * Simulated `last_skip_logged_at`, in minutes on the same clock, or null
     * for "this job has never logged a skip" (the seeded/never-run state).
     */
    private ?int $lastSkipLoggedAtMinutes;

    /** How many `SET last_skip_logged_at` writes this fake has accepted. */
    private int $skipStampWrites = 0;

    /**
     * @param bool $enabled Value reported for the `enabled` column by the
     *                      `SELECT enabled` read.
     * @param bool $rowExists When false, the `SELECT enabled` read returns no
     *                        row at all — the missing-row fail-safe case.
     * @param bool $claimSucceeds When true, the claim UPDATE reports 1 row
     *                            changed (the claim was won); when false, 0.
     * @param bool $queryErrors When true, error() reports true after query().
     * @param int|null $lastSkipLoggedAtMinutesAgo How long ago this job last
     *                        logged a skip line, in simulated minutes, or null
     *                        (the default) for never. Drives the read's
     *                        computed `skip_log_is_due` column, and is
     *                        advanced by the job's own throttle write — so a
     *                        test can run many hits and watch it throttle.
     * @param int $guardIntervalHours The interval `skip_log_is_due` is
     *                        computed against. Must match the job under test's
     *                        own guardIntervalHours().
     */
    public function __construct(
        private readonly bool $enabled = true,
        private readonly bool $rowExists = true,
        private readonly bool $claimSucceeds = true,
        private readonly bool $queryErrors = false,
        ?int $lastSkipLoggedAtMinutesAgo = null,
        private readonly int $guardIntervalHours = 24,
    ) {
        $this->lastSkipLoggedAtMinutes = $lastSkipLoggedAtMinutesAgo === null
            ? null
            : -$lastSkipLoggedAtMinutesAgo;
    }

    /** Move the simulated clock forward, as the gap between two cron hits. */
    public function advanceMinutes(int $minutes): void
    {
        $this->nowMinutes += $minutes;
    }

    public function query(string $sql, array $params = []): self
    {
        $this->sqlLog[] = $sql;
        $this->lastQueryWasClaim = stripos($sql, 'last_run_at = NOW()') !== false;

        if (stripos($sql, 'last_skip_logged_at = NOW()') !== false) {
            $this->lastSkipLoggedAtMinutes = $this->nowMinutes;
            $this->skipStampWrites++;
        }

        return $this;
    }

    public function error(): bool
    {
        return $this->sqlLog !== [] && $this->queryErrors;
    }

    public function count(): int
    {
        if (!$this->lastQueryWasClaim) {
            return $this->rowExists ? 1 : 0;
        }

        return $this->claimSucceeds ? 1 : 0;
    }

    public function first(bool $assoc = false): array|object
    {
        if ($this->lastQueryWasClaim || !$this->rowExists) {
            return [];
        }

        $row = [
            'enabled' => $this->enabled ? 1 : 0,
            // MySQL returns a boolean expression as 1/0, not true/false.
            'skip_log_is_due' => $this->skipLogIsDue() ? 1 : 0,
        ];

        return $assoc ? $row : (object)$row;
    }

    /**
     * The `(last_skip_logged_at IS NULL OR last_skip_logged_at < NOW() -
     * INTERVAL ? HOUR)` predicate, evaluated against the simulated clock.
     */
    private function skipLogIsDue(): bool
    {
        return $this->lastSkipLoggedAtMinutes === null
            || $this->lastSkipLoggedAtMinutes < $this->nowMinutes - ($this->guardIntervalHours * 60);
    }

    /** How many times the job stamped `last_skip_logged_at`. */
    public function skipStampWrites(): int
    {
        return $this->skipStampWrites;
    }

    /**
     * SQL text of every query() call, in order.
     *
     * @return list<string>
     */
    public function sqlLog(): array
    {
        return $this->sqlLog;
    }
}
