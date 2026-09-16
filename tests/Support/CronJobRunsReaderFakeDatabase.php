<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * CronJobRunsReaderFakeDatabase - FakeDatabase double for CronJobRunsReaderTest
 *
 * `CronJobRunsReader::fetchRow()` issues a single query shape — `SELECT
 * enabled, last_run_at FROM er_cron_job_runs WHERE job_name = ?` — and reads
 * the result with `first(true)` (associative array). All four
 * `CronJobEnabledState` cases fall out of two independent axes this double
 * exposes per job_name: whether the query itself fails (`error()` true, the
 * UNREADABLE case) and whether a row exists for that job_name (MISSING when
 * it doesn't; DISABLED/ENABLED per the row's `enabled` column when it does).
 *
 * Row/error state is configured per job_name via {@see self::withRow()} and
 * {@see self::withError()}, mirroring `AbstractCronJobFakeDatabase`'s SQL
 * dispatch but keyed by the bound `job_name` parameter instead of the SQL
 * text, since every call here shares one query shape and differs only in
 * which job is being asked about. A job_name with no configured row and no
 * configured error defaults to "no row" (MISSING) — the same
 * safest-default convention `AbstractCronJobFakeDatabase`'s `$rowExists`
 * and `FakeDatabase::$firstRow` both use.
 *
 * `lastOutcomeCounts()` issues a second, distinct query shape (`SELECT
 * last_sent_count, last_skipped_count, last_failed_count FROM
 * er_cron_job_runs WHERE job_name = ?`), configured independently via
 * {@see self::withOutcomeCounts()} and {@see self::withOutcomeCountsError()}
 * / {@see self::withOutcomeCountsThrowing()} — kept separate from
 * `withRow()`/`withError()` above because `lastOutcomeCounts()` has its own
 * never-throws contract (a query that throws mid-`query()` call, distinct
 * from one that merely reports `error()`) that `status()`/`fetchRow()` do
 * not need to model.
 *
 * Deliberately a *named* class rather than `new class extends FakeDatabase { ... }`:
 * PHPStan reports `impureMethod.pure` when an anonymous class overrides one of
 * DatabaseInterface's `@phpstan-impure` methods (`query()`, `error()`,
 * `first()` here) with a body that doesn't depend on mutable state. See
 * AbstractCronJobFakeDatabase's docblock for the same rationale.
 *
 * @package Tests\Support
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/2054
 */
class CronJobRunsReaderFakeDatabase extends FakeDatabase
{
    /** @var array<string, array{enabled: int, last_run_at: string|null}> */
    private array $rows = [];

    /** @var array<string, bool> */
    private array $errors = [];

    /** @var array<string, array{last_sent_count: int|null, last_skipped_count: int|null, last_failed_count: int|null}> */
    private array $outcomeCountRows = [];

    /** @var array<string, bool> */
    private array $outcomeCountErrors = [];

    /** @var array<string, bool> */
    private array $outcomeCountThrows = [];

    private ?string $lastJobName = null;

    /** Whether the most recently issued query() call was the outcome-counts SELECT. */
    private bool $lastQueryWasOutcomeCounts = false;

    /**
     * Configure the row returned for a given job_name — an ENABLED or
     * DISABLED case depending on $enabled, with $lastRunAt as the raw
     * `last_run_at` column value (null for "has never run").
     */
    public function withRow(string $jobName, bool $enabled, ?string $lastRunAt = null): self
    {
        $this->rows[$jobName] = [
            'enabled' => $enabled ? 1 : 0,
            'last_run_at' => $lastRunAt,
        ];
        unset($this->errors[$jobName]);

        return $this;
    }

    /**
     * Configure the query for a given job_name to simulate a failure — the
     * UNREADABLE case. Overrides any row previously configured via
     * withRow() for the same job_name.
     */
    public function withError(string $jobName): self
    {
        $this->errors[$jobName] = true;
        unset($this->rows[$jobName]);

        return $this;
    }

    /**
     * Configure lastOutcomeCounts()'s row for a given job_name. Pass null for
     * any of the three counts to model a row where that column is still NULL
     * — in particular, $sentCount === null models "this job has a row but has
     * never recorded outcome counts", the load-bearing distinction the class
     * docblock calls out. All-zero ($sentCount = 0, etc.) models a run that
     * genuinely sent/skipped/failed nothing, which must read back distinctly
     * from the null case.
     *
     * Either way this is a successful read, so `lastOutcomeCounts()` reports
     * `unreadable => false` — the two fault configurators below are what set
     * it true.
     */
    public function withOutcomeCounts(
        string $jobName,
        ?int $sentCount,
        ?int $skippedCount = null,
        ?int $failedCount = null
    ): self {
        $this->outcomeCountRows[$jobName] = [
            'last_sent_count' => $sentCount,
            'last_skipped_count' => $skippedCount,
            'last_failed_count' => $failedCount,
        ];
        unset($this->outcomeCountErrors[$jobName], $this->outcomeCountThrows[$jobName]);

        return $this;
    }

    /**
     * Configure the outcome-counts query for a given job_name to report
     * failure via error() (not throw) — the ordinary DatabaseInterface fault
     * path.
     */
    public function withOutcomeCountsError(string $jobName): self
    {
        $this->outcomeCountErrors[$jobName] = true;
        unset($this->outcomeCountRows[$jobName]);

        return $this;
    }

    /**
     * Configure the outcome-counts query for a given job_name to throw a
     * \Throwable directly out of query() — modeling the real \DB::query()
     * prepare()-time PDOException for a missing column (the half-applied-
     * migration case lastOutcomeCounts()'s try/catch exists for).
     */
    public function withOutcomeCountsThrowing(string $jobName): self
    {
        $this->outcomeCountThrows[$jobName] = true;
        unset($this->outcomeCountRows[$jobName]);

        return $this;
    }

    /**
     * @param string $sql SQL with `?` placeholders
     * @param array<mixed> $params Values bound to the placeholders, in order
     */
    public function query(string $sql, array $params = []): self
    {
        $this->lastJobName = isset($params[0]) ? (string) $params[0] : null;
        $this->lastQueryWasOutcomeCounts = stripos($sql, 'last_sent_count') !== false;

        if ($this->lastQueryWasOutcomeCounts
            && $this->lastJobName !== null
            && ($this->outcomeCountThrows[$this->lastJobName] ?? false)
        ) {
            throw new \RuntimeException('simulated prepare()-time failure: missing column');
        }

        return $this;
    }

    public function error(): bool
    {
        if ($this->lastJobName === null) {
            return false;
        }

        if ($this->lastQueryWasOutcomeCounts) {
            return $this->outcomeCountErrors[$this->lastJobName] ?? false;
        }

        return $this->errors[$this->lastJobName] ?? false;
    }

    public function first(bool $assoc = false): array|object
    {
        if ($this->lastJobName === null) {
            return $assoc ? [] : (object) [];
        }

        if ($this->lastQueryWasOutcomeCounts) {
            if (!isset($this->outcomeCountRows[$this->lastJobName])) {
                return $assoc ? [] : (object) [];
            }

            $row = $this->outcomeCountRows[$this->lastJobName];

            return $assoc ? $row : (object) $row;
        }

        if (!isset($this->rows[$this->lastJobName])) {
            return $assoc ? [] : (object) [];
        }

        $row = $this->rows[$this->lastJobName];

        return $assoc ? $row : (object) $row;
    }
}
