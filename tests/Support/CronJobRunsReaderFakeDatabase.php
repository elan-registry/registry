<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * CronJobRunsReaderFakeDatabase - FakeDatabase double for CronJobRunsReaderTest
 *
 * State is configured per job_name and keyed by the bound `job_name`
 * parameter, because every status call shares one query shape. A job_name
 * with no configured row and no error reads as "no row" (MISSING).
 *
 * The status SELECT ({@see self::withRow()}, {@see self::withError()}) and
 * the lastOutcomeCounts() SELECT ({@see self::withOutcomeCounts()} and its
 * fault configurators) are configured separately, because
 * lastOutcomeCounts() has its own never-throws contract.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 *
 * @package Tests\Support
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/2054
 */
class CronJobRunsReaderFakeDatabase extends FakeDatabase
{
    /** @var array<string, array{enabled: int, last_run_at: string|null, last_failure_at: string|null}> */
    private array $rows = [];

    /** @var array<string, bool> */
    private array $errors = [];

    /** @var array<string, array{last_sent_count: int|null, last_skipped_count: int|null, last_failed_count: int|null}> */
    private array $outcomeCountRows = [];

    /** @var array<string, bool> */
    private array $outcomeCountErrors = [];

    /** @var array<string, bool> */
    private array $outcomeCountThrows = [];

    /** @var array<string, bool> */
    private array $lastFailureAtColumnMissing = [];

    /** @var array<string, bool> */
    private array $lastFailureAtColumnThrows = [];

    private ?string $lastJobName = null;

    /** Whether the most recently issued query() call was the outcome-counts SELECT. */
    private bool $lastQueryWasOutcomeCounts = false;

    /**
     * Whether the most recently issued query() call selected `last_failure_at`.
     *
     * fetchRow() issues one of two status statements — the three-column one,
     * and a two-column fallback after that throws — so first() has to know
     * which of the two it is answering in order to omit the key the fallback
     * could not have selected.
     */
    private bool $lastQuerySelectedLastFailureAt = false;

    /**
     * Configure the row returned for a given job_name (ENABLED or DISABLED).
     * Null $lastRunAt means "has never run"; null $lastFailureAt means "has
     * never failed". They are separate because `badgeFor()` compares them, so
     * a test must be able to set either one and order them both ways.
     */
    public function withRow(
        string $jobName,
        bool $enabled,
        ?string $lastRunAt = null,
        ?string $lastFailureAt = null
    ): self {
        $this->rows[$jobName] = [
            'enabled' => $enabled ? 1 : 0,
            'last_run_at' => $lastRunAt,
            'last_failure_at' => $lastFailureAt,
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
     * Configure lastOutcomeCounts()'s row for a given job_name. A null count
     * models a NULL column: $sentCount === null means "has a row but never
     * recorded outcome counts", which must read back differently from an
     * all-zero run. Both are successful reads (`unreadable => false`).
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
     * Configure the status SELECT to behave as it would on a schema where
     * 20260922171500_add_cron_job_runs_last_failure_at has not applied:
     * `fetchRow()`'s three-column statement fails with MySQL's 1054 "Unknown
     * column", and its two-column retry then succeeds and returns the row
     * configured by {@see self::withRow()} — minus its `last_failure_at` key,
     * exactly as MySQL would return it.
     *
     * MODELS error(), NOT A THROW. This connection leaves ATTR_EMULATE_PREPARES
     * at PDO's default of ON (users/classes/DB.php never sets it), so prepare()
     * cannot detect an unknown column; the fault surfaces at execute(), inside
     * DB::query()'s own `catch (Exception)`, and is reported via
     * error()/errorInfo(). A throwing double here would let the production
     * fallback be dead code while the tests stay green.
     * {@see self::withLastFailureAtColumnMissingAsThrow()} covers the
     * non-emulated variant.
     *
     * Separate from {@see self::withError()}: that models a query that fails
     * outright (UNREADABLE), whereas this models a degraded-but-working read
     * where the status row is still rendered, only without failure detection.
     */
    public function withLastFailureAtColumnMissing(string $jobName): self
    {
        $this->lastFailureAtColumnMissing[$jobName] = true;

        return $this;
    }

    /**
     * The same missing column, but surfacing as a throw out of query() — what
     * happens if ATTR_EMULATE_PREPARES is ever turned off, making prepare()
     * server-side and able to reject an unknown column before execute().
     * `fetchRow()` must take the identical retry path for both.
     */
    public function withLastFailureAtColumnMissingAsThrow(string $jobName): self
    {
        $this->lastFailureAtColumnThrows[$jobName] = true;

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
        $this->lastQuerySelectedLastFailureAt = stripos($sql, 'last_failure_at') !== false;

        if ($this->lastQueryWasOutcomeCounts
            && $this->lastJobName !== null
            && ($this->outcomeCountThrows[$this->lastJobName] ?? false)
        ) {
            throw new \RuntimeException('simulated prepare()-time failure: missing column');
        }

        if ($this->lastQuerySelectedLastFailureAt
            && $this->lastJobName !== null
            && ($this->lastFailureAtColumnThrows[$this->lastJobName] ?? false)
        ) {
            throw new \RuntimeException(
                'simulated prepare()-time failure: unknown column er_cron_job_runs.last_failure_at'
            );
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

        // Only the statement that actually selected the absent column fails;
        // fetchRow()'s two-column retry must then succeed, which is what makes
        // the degraded-but-working path observable.
        if ($this->lastQuerySelectedLastFailureAt
            && ($this->lastFailureAtColumnMissing[$this->lastJobName] ?? false)
        ) {
            return true;
        }

        return $this->errors[$this->lastJobName] ?? false;
    }

    /**
     * MySQL's error triple. Reports 1054 (ER_BAD_FIELD_ERROR) for the
     * missing-column case so fetchRow() can tell an absent column from a
     * genuine outage — it retries only on 1054, and must keep reporting
     * UNREADABLE for anything else.
     *
     * @return array{0: string, 1: int|null, 2: string|null}
     */
    public function errorInfo(): array
    {
        if ($this->lastJobName !== null
            && $this->lastQuerySelectedLastFailureAt
            && ($this->lastFailureAtColumnMissing[$this->lastJobName] ?? false)
        ) {
            return ['42S22', 1054, "Unknown column 'last_failure_at' in 'field list'"];
        }

        return ['HY000', 2006, 'simulated database fault'];
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

        // A statement that did not select the column cannot return it. This is
        // what makes fetchRow()'s fallback path testable: status() must read
        // the absent key as "never failed" rather than notice-ing on it.
        if (!$this->lastQuerySelectedLastFailureAt) {
            unset($row['last_failure_at']);
        }

        return $assoc ? $row : (object) $row;
    }
}
