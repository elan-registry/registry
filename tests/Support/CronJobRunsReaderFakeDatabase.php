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

    private ?string $lastJobName = null;

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
     * @param string $sql SQL with `?` placeholders
     * @param array<mixed> $params Values bound to the placeholders, in order
     */
    public function query(string $sql, array $params = []): self
    {
        $this->lastJobName = isset($params[0]) ? (string) $params[0] : null;

        return $this;
    }

    public function error(): bool
    {
        return $this->lastJobName !== null && ($this->errors[$this->lastJobName] ?? false);
    }

    public function first(bool $assoc = false): array|object
    {
        if ($this->lastJobName === null || !isset($this->rows[$this->lastJobName])) {
            return $assoc ? [] : (object) [];
        }

        $row = $this->rows[$this->lastJobName];

        return $assoc ? $row : (object) $row;
    }
}
