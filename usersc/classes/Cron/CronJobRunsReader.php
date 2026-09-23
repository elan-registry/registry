<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

use DateTimeImmutable;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;

/**
 * CronJobRunsReader - Read-only, never-throws access to er_cron_job_runs for display
 *
 * `AbstractCronJob::enabledState()` (private) reads this same table, but is
 * scoped to the per-job cron *dispatch* context — it takes no job name
 * parameter (it reads `$this->jobName()`), it advances skip-logging
 * bookkeeping as a side effect, and it is unreachable from outside a job
 * subclass. Admin UI that merely wants to *display* a job's status (the
 * verification tab's reconciliation row, #2054; a future general cron
 * admin page, #2038) is a different caller with different needs: any job
 * name, no side effects, and the row's display columns (`last_run_at`,
 * `last_failure_at`, and the `last_*_count` tallies) in addition to
 * `enabled` — none of which the dispatch path has any use for. Rather
 * than widen `AbstractCronJob`'s scope or inline the query in a view file,
 * this is a small dedicated reader — the same shape as
 * {@see \ElanRegistry\Car\VerificationSettings}, the established pattern
 * for this kind of read-only, admin-facing settings/status class.
 *
 * Never-throws contract, matching both of the above: a failed query or a
 * missing row reports a `CronJobEnabledState` case rather than raising, and
 * both of those two fault cases are logged under
 * `LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE` — the same category and
 * message style `AbstractCronJob::enabledState()` uses for the same two
 * cases. `DISABLED` and `ENABLED` are not logged here: reading state for
 * display is not a fault condition, and logging on every admin page view
 * would add noise with no diagnostic value.
 *
 * @package ElanRegistry\Cron
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/2054
 */
final class CronJobRunsReader
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * Resolve a job's er_cron_job_runs row into an enabled state.
     *
     * Thin wrapper around {@see self::status()} for callers that only need
     * the state. Prefer {@see self::status()} when both the state and the
     * last-run timestamp are needed — calling both this and
     * {@see self::lastRunAt()} separately issues two queries and (on a
     * fault) logs twice for what is really one logical read.
     *
     * @param string $jobName The er_cron_job_runs.job_name value to look up
     * @return CronJobEnabledState UNREADABLE on query failure, MISSING when
     *                             no row matches, otherwise DISABLED or
     *                             ENABLED per the row's `enabled` column
     */
    public function state(string $jobName): CronJobEnabledState
    {
        return $this->status($jobName)['state'];
    }

    /**
     * Timestamp of the job's most recent claimed run.
     *
     * Thin wrapper around {@see self::status()}; see that method's docblock
     * for why callers needing both values should call it directly instead of
     * combining this with {@see self::state()}.
     *
     * Returns null whenever there is nothing meaningful to show: the row is
     * missing or unreadable (see {@see self::state()}), the job has never
     * run (`last_run_at IS NULL`), or the stored value could not be parsed.
     * Callers distinguish "never run" from "unreadable" via {@see self::state()}
     * — this method alone does not.
     *
     * @param string $jobName The er_cron_job_runs.job_name value to look up
     * @return DateTimeImmutable|null When the job last ran, or null
     */
    public function lastRunAt(string $jobName): ?DateTimeImmutable
    {
        return $this->status($jobName)['lastRunAt'];
    }

    /**
     * Resolve a job's er_cron_job_runs row into its state and last-run
     * timestamp in a single read.
     *
     * `state()` and `lastRunAt()` used to each call `fetchRow()`
     * independently, which meant a full status read cost two queries and — on
     * a fault — logged the same failure twice. It also left a race: `state()`
     * could succeed while a separate `lastRunAt()` call failed mid-render (or
     * vice versa), which could report state and timestamp as of two different
     * moments. This method reads the row once and derives both values from
     * it, closing both gaps.
     *
     * `lastFailureAt` joined the same read rather than getting a method of its
     * own for exactly the reasons above: it is only ever useful *in comparison
     * with* `lastRunAt` (see {@see self::badgeFor()}), so reading the two at
     * different moments could report a failure as current that a run since
     * completed — the precise race this method was created to close.
     *
     * @param string $jobName The er_cron_job_runs.job_name value to look up
     * @return array{state: CronJobEnabledState, lastRunAt: ?DateTimeImmutable, lastFailureAt: ?DateTimeImmutable}
     */
    public function status(string $jobName): array
    {
        $row = $this->fetchRow($jobName);

        if ($row === null) {
            return [
                'state' => $this->db->error() ? CronJobEnabledState::UNREADABLE : CronJobEnabledState::MISSING,
                'lastRunAt' => null,
                'lastFailureAt' => null,
            ];
        }

        $state = (bool) $row['enabled'] ? CronJobEnabledState::ENABLED : CronJobEnabledState::DISABLED;

        return [
            'state' => $state,
            'lastRunAt' => $this->parseTimestamp($jobName, 'last_run_at', $row['last_run_at'] ?? null),
            // Absent from the row entirely (rather than NULL) on a schema where
            // 20260922171500 has not applied — fetchRow() tolerates that, see
            // its own comment — and `?? null` then reads it as "never failed",
            // which is the same benign state a freshly seeded row reports.
            'lastFailureAt' => $this->parseTimestamp($jobName, 'last_failure_at', $row['last_failure_at'] ?? null),
        ];
    }

    /**
     * Parse a raw timestamp column value into a DateTimeImmutable.
     *
     * Generalized from a `last_run_at`-only parser when `last_failure_at`
     * arrived: both columns are written exclusively by
     * {@see CronJobGuard}'s own `NOW()` statements, so both carry exactly the
     * same guarantees about what a stored value can legitimately be — and
     * therefore the same zero-date reasoning below. Duplicating the parser per
     * column would have meant duplicating that reasoning, and two copies of it
     * could drift. The column name is passed only so a log line names the
     * column an operator has to go and look at.
     *
     * @param string $jobName Used only for log messages
     * @param string $column The column name, used only for log messages
     * @param mixed $rawValue The row's column value
     * @return DateTimeImmutable|null Null when empty or unparseable
     */
    private function parseTimestamp(string $jobName, string $column, mixed $rawValue): ?DateTimeImmutable
    {
        if (empty($rawValue)) {
            return null;
        }

        $value = (string) $rawValue;

        // MySQL's zero-date ('0000-00-00 00:00:00') does not throw when passed
        // to DateTimeImmutable's constructor — it silently parses to a bogus
        // year -1 date instead, which would otherwise slip past the catch
        // block below as a "successfully parsed" value. These columns are only
        // ever written by CronJobGuard's `NOW()` statements, so a zero-date
        // here can only mean external/manual tampering or a schema-level
        // default misconfiguration — treat it the same as any other
        // unparseable value. A bogus year -1 reaching badgeFor() would be
        // worse than a null: it compares the two timestamps, and a year -1
        // last_failure_at would silently lose every comparison, hiding a real
        // failure behind a green badge.
        if (str_starts_with($value, '0000-00-00')) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': unparseable %s \"%s\": MySQL zero-date",
                $jobName,
                $column,
                $value
            ));
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable $e) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': unparseable %s \"%s\": %s",
                $jobName,
                $column,
                $value,
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Read the sent/skipped/failed tallies a job recorded on its last claimed
     * run.
     *
     * Deliberately separate from {@see self::status()} rather than another key
     * on its return array: those three columns are written by only one job so
     * far ({@see SendVerificationBatchJob}), are purely a dashboard readout,
     * and play no part in the enable/pause decision `status()` and `state()`
     * exist to serve. Callers that need the state pay for one query; callers
     * that also want the tallies pay for a second, and nothing that only
     * checks whether a job may run has to know these columns exist.
     *
     * Same never-throws, fails-safe contract as the rest of this class: a
     * failed query, a missing row, or a row whose counts are still NULL (the
     * job has never recorded a run) all report `counts => null` rather than
     * raising or inventing zeros — "never run" and "ran and did nothing" must
     * stay distinguishable in the UI.
     *
     * A bare `?array` return could not carry that far enough, which is the
     * bug this shape fixes: it collapsed three situations into one null —
     * the job has genuinely never run (routine), the query threw, and the
     * query reported `error()` (both infrastructure faults). The UI rendered
     * all three as the reassuring "No automatic run yet", so an operator had
     * no way to tell a healthy new job from one whose dashboard bookkeeping
     * is broken. This is the same problem {@see CronJobEnabledState} solves
     * for the enabled/disabled read, where MISSING and UNREADABLE are
     * deliberately not folded into DISABLED.
     *
     * So this returns a compound, non-nullable array in the shape
     * {@see self::status()} established for exactly this reason — two
     * orthogonal facts from one read, rather than one overloaded nullable:
     *
     * - `counts` is the tally array when a run has been recorded, and null
     *   otherwise (never run, or unreadable — in which case there is nothing
     *   truthful to show).
     * - `unreadable` is true when the read itself failed (the throw and
     *   `error()` paths) OR when no row exists at all for this job — the same
     *   MISSING condition {@see self::status()} reports as
     *   {@see CronJobEnabledState::MISSING} rather than a routine state, since
     *   a job that was never seeded cannot be claimed either. Only a row that
     *   exists with still-NULL counts is the routine never-run case, and
     *   leaves this false.
     *
     * Callers must therefore check `unreadable` before treating a null
     * `counts` as "never run". A separate `outcomeCountsUnreadable()` method
     * was rejected for the reason `state()`/`lastRunAt()` document above: it
     * would issue a second query and log the same fault twice for what is one
     * logical read, and could report the two halves as of two different
     * moments.
     *
     * The fault cases ARE logged here rather than left to the status read the
     * caller does alongside this one: these three columns are added by their
     * own migration, so this query can fail on a schema where `status()`'s
     * query succeeds (the half-applied-migration case).
     *
     * @param string $jobName The er_cron_job_runs.job_name value to look up
     * @return array{counts: array{sent: int, skipped: int, failed: int}|null, unreadable: bool}
     */
    public function lastOutcomeCounts(string $jobName): array
    {
        // query() reports ordinary failures via error() rather than raising,
        // but it is NOT throw-free: DB::query() calls PDO::prepare() outside
        // its own try block with ERRMODE_EXCEPTION set, so a prepare()-time
        // fault — a missing column, which is precisely the half-applied
        // migration case for these three columns — throws a PDOException
        // straight out. Catching \Throwable here is what keeps this class's
        // never-throws contract true.
        try {
            $this->db->query(
                'SELECT last_sent_count, last_skipped_count, last_failed_count'
                . ' FROM er_cron_job_runs WHERE job_name = ?',
                [$jobName]
            );
        } catch (\Throwable $e) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': er_cron_job_runs outcome counts could not be read — counts unavailable."
                . ' This is an infrastructure fault, not a job that has never run.'
                . ' Check that 20260916000000_add_cron_job_runs_last_outcome_counts has applied: %s',
                $jobName,
                $e->getMessage()
            ));
            return ['counts' => null, 'unreadable' => true];
        }

        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': er_cron_job_runs outcome counts could not be read — counts unavailable."
                . ' This is an infrastructure fault, not a job that has never run: %s',
                $jobName,
                $this->db->errorString() ?: 'unknown'
            ));
            return ['counts' => null, 'unreadable' => true];
        }

        $row = $this->db->first(true);

        // No row at all is the same MISSING condition status()/fetchRow()
        // resolve to — the seed migration never ran, or the row was deleted.
        // That is an infrastructure fault (the job cannot even be claimed),
        // not "healthy but new", so `unreadable` is true here, matching
        // status()'s CronJobEnabledState::MISSING for this same case. Logged,
        // not silent: the missing-row case elsewhere in this class (fetchRow())
        // is always logged, and an operator has no other way to learn the seed
        // migration is the actual cause behind a blank "Automatic Sending" card.
        if ($row === []) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': no er_cron_job_runs row — outcome counts unavailable."
                . ' Check that the job name matches a migration-seeded row.',
                $jobName
            ));
            return ['counts' => null, 'unreadable' => true];
        }

        // A row exists but its counts are still NULL: the job has genuinely
        // never recorded a run. Routine, not a fault — `unreadable` stays
        // false so the UI can keep showing its benign "no automatic run yet"
        // text, distinct from the row-missing case just above.
        if (!isset($row['last_sent_count'])) {
            return ['counts' => null, 'unreadable' => false];
        }

        return [
            'counts' => [
                'sent' => (int) $row['last_sent_count'],
                'skipped' => (int) ($row['last_skipped_count'] ?? 0),
                'failed' => (int) ($row['last_failed_count'] ?? 0),
            ],
            'unreadable' => false,
        ];
    }

    /**
     * Map a job's state and its run/failure timestamps to display attributes
     * for the verification tab's per-job status badges.
     *
     * THE FAILURE CASE IS WHY THIS TAKES TWO TIMESTAMPS. `last_run_at` is
     * stamped by {@see CronJobGuard::claim()} before the work runs, so on its
     * own it cannot distinguish a run that finished from one that threw — and
     * this method used to render the green "Ran" badge for both, so a job
     * crashing nightly for a week advertised a healthy timestamp from minutes
     * ago. Comparing the two timestamps answers the question the badge is
     * actually asking: was the MOST RECENT claimed run the one that failed?
     *
     * `>=`, not `>`: both columns are written by `NOW()` at one-second
     * resolution, and a run that is claimed and throws immediately writes both
     * within the same second. A strict `>` would read that tie as a success
     * and render the green badge for the very failure this case exists to
     * surface — the common case for a job that throws on its first statement.
     *
     * The converse — `lastRunAt` strictly newer than `lastFailureAt` — is a
     * failure a later run has already superseded, so the normal
     * `Ran`/`Never run` logic applies. Stale failures are deliberately not
     * surfaced: the badge reports the current state of the job, and the log
     * (under `LOG_CATEGORY_CRON_JOB_FAILURE`, surfaced on this same tab) is
     * where the history lives.
     *
     * Only the ENABLED arm consults the failure timestamp. DISABLED reports
     * the operator's own pause — which is the actionable fact for a job
     * nobody is expecting to run — and MISSING/UNREADABLE already render the
     * danger badge, where a failure stamp read from a row that could not be
     * read would be meaningless.
     *
     * @param CronJobEnabledState $state
     * @param DateTimeImmutable|null $lastRunAt When a run was last claimed
     * @param DateTimeImmutable|null $lastFailureAt When a run's execute() last
     *        threw, or null if it never has. Defaults to null so callers
     *        written before this column existed keep their previous behaviour
     *        rather than silently passing the wrong positional argument.
     * @return array{badgeClass: string, icon: string, text: string}
     */
    public static function badgeFor(
        CronJobEnabledState $state,
        ?DateTimeImmutable $lastRunAt,
        ?DateTimeImmutable $lastFailureAt = null
    ): array {
        $failureIsCurrent = $lastFailureAt !== null
            && ($lastRunAt === null || $lastFailureAt >= $lastRunAt);

        return match ($state) {
            CronJobEnabledState::ENABLED => match (true) {
                $failureIsCurrent => [
                    'badgeClass' => 'badge text-bg-danger',
                    'icon' => 'fa-triangle-exclamation',
                    'text' => 'Last run failed',
                ],
                $lastRunAt !== null =>
                    ['badgeClass' => 'badge text-bg-success', 'icon' => 'fa-check-circle', 'text' => 'Ran'],
                default =>
                    ['badgeClass' => 'badge text-bg-secondary', 'icon' => 'fa-hourglass-half', 'text' => 'Never run'],
            },
            CronJobEnabledState::DISABLED =>
                ['badgeClass' => 'badge text-bg-secondary', 'icon' => 'fa-pause-circle', 'text' => 'Paused'],
            CronJobEnabledState::MISSING, CronJobEnabledState::UNREADABLE => [
                'badgeClass' => 'badge text-bg-danger',
                'icon' => 'fa-exclamation-circle',
                'text' => 'Status unavailable',
            ],
        };
    }

    /**
     * Read one job's er_cron_job_runs row.
     *
     * Called by {@see self::status()}, the sole entry point for a status
     * read. Returns null for both the UNREADABLE and MISSING cases — the
     * caller distinguishes them via `$this->db->error()`, checked only
     * immediately after this call returns (see {@see DatabaseInterface}:
     * result state reflects only the most recent query).
     *
     * @param string $jobName The er_cron_job_runs.job_name value to look up
     * @return array<string, mixed>|null The row, or null if unreadable/missing
     */
    private function fetchRow(string $jobName): ?array
    {
        // query() reports ordinary failures via error() (see
        // DatabaseInterface) rather than raising, but it is not throw-free:
        // DB::query() calls PDO::prepare() outside its try block with
        // ERRMODE_EXCEPTION set, so a prepare()-time fault (a missing column
        // or table) throws. `enabled` and `last_run_at` have existed since the
        // table was created, but `last_failure_at` is migration-added
        // (20260922171500), so this statement acquired the same
        // half-applied-migration exposure lastOutcomeCounts() already guards
        // against — hence the catch, which did not previously exist here.
        //
        // The fallback re-reads the two original columns rather than giving
        // up: an unapplied failure-timestamp migration must degrade this tab
        // to its previous behaviour (a status badge with no failure
        // awareness), not blank out every job's status row on the page —
        // worse information, not no information. A row
        // returned from that second query simply has no `last_failure_at` key,
        // which status() reads as "never failed".
        try {
            $this->db->query(
                'SELECT enabled, last_run_at, last_failure_at FROM er_cron_job_runs WHERE job_name = ?',
                [$jobName]
            );
        } catch (\Throwable $e) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': er_cron_job_runs.last_failure_at could not be read — falling back to"
                . ' status without failure detection, so a crashed run may still show as having run.'
                . ' Check that 20260922171500_add_cron_job_runs_last_failure_at has applied: %s',
                $jobName,
                $e->getMessage()
            ));

            $this->db->query(
                'SELECT enabled, last_run_at FROM er_cron_job_runs WHERE job_name = ?',
                [$jobName]
            );
        }

        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': er_cron_job_runs could not be read — status unavailable."
                . ' This is an infrastructure fault, not a paused job: %s',
                $jobName,
                $this->db->errorString() ?: 'unknown'
            ));
            return null;
        }

        $row = $this->db->first(true);

        if (!is_array($row) || !isset($row['enabled'])) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': no er_cron_job_runs row — status unavailable."
                . ' Check that the job name matches a migration-seeded row.',
                $jobName
            ));
            return null;
        }

        return $row;
    }
}
