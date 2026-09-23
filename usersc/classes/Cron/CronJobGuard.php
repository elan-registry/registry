<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;

/**
 * Atomic-claim guard for cron jobs, used to prevent duplicate/overlapping
 * scheduled runs.
 *
 * Backed by the generic `er_cron_job_runs` table (#2034) rather than a bespoke
 * per-job column on `settings` — more cron jobs were coming (#1889, #1885,
 * others unnamed), and a bespoke column approach would have meant a new
 * migration for each one. `$jobName` must be in the `ALLOWED_JOB_NAMES`
 * allowlist below — it grows only when a new caller genuinely needs a new
 * job, each claimed by {@see AbstractCronJob::run()} on behalf of its own
 * job class (e.g. {@see BrevoEventReconciliationJob} #1889,
 * {@see BrevoSuppressionSyncJob} #1923). The allowlist and the seeded
 * `er_cron_job_runs` rows must stay in sync — a job name present in only one
 * of the two fails silently (allowlist-only: `claim()` always returns
 * `false`; table-only: unreachable, since nothing can pass that name through
 * the allowlist check). Adding a job is therefore a two-step change: a
 * migration seeding its row, and an allowlist entry here.
 *
 * `claim()`'s own query gates on `enabled = 1`, so a job an operator has
 * paused (via `er_cron_job_runs.enabled`, independent of UserSpice's own
 * `crons.active`) silently fails to claim — the same outcome as "claimed too
 * recently", or as an unrecognized job name. This centralizes the
 * enabled-check in the one place every caller already goes through, rather
 * than requiring each caller to check `enabled` separately before calling
 * `claim()` — but it also means none of these three cases (disabled,
 * too-recent, unrecognized name) is distinguishable from the others by a
 * caller, and none is logged. That's acceptable for a guard (silent no-op is
 * the safe default), but on its own it would mean an operator who disables a
 * job gets no signal that this is why it stopped running. That gap is closed
 * outside this class, not in it: {@see AbstractCronJob::run()} now does a
 * separate `er_cron_job_runs` read before claiming and reports the three
 * not-enabled cases distinctly ({@see CronJobEnabledState}) — a deliberate
 * pause logged as a skip, a missing or unreadable row logged as a failure.
 * The remaining half of that gap is the operator's *control* surface: pausing
 * a job still means a direct `UPDATE` on `er_cron_job_runs.enabled`, until
 * #2038 adds the admin UI for it.
 */
final class CronJobGuard
{
    private const ALLOWED_JOB_NAMES = [
        'brevo_reconciliation',
        'brevo_suppression_sync',
        'send_verification_batch',
    ];

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Atomically claim a cron run by updating the job's last-run timestamp,
     * but only if the job is enabled and the interval has elapsed since the
     * last claim.
     *
     * @param string $jobName Must be in ALLOWED_JOB_NAMES
     * @param int $intervalHours Minimum hours since the last claim. Must be >= 1.
     * @return bool True if this call claimed the run, false otherwise
     */
    public function claim(string $jobName, int $intervalHours): bool
    {
        if (!in_array($jobName, self::ALLOWED_JOB_NAMES, true)) {
            return false;
        }

        // $intervalHours < 1 is rejected rather than passed through: this
        // database connection does not set MYSQL_ATTR_FOUND_ROWS (see
        // users/classes/DB.php), so count() below reports MySQL's
        // changed-rows semantics, not matched-rows. With an interval of 0,
        // two claims within the same second both match the WHERE clause, but
        // the second writes an identical last_run_at value — MySQL reports 0
        // rows changed for that write, count() returns 0, and claim()
        // reports false even though the row matched and a genuine claim was
        // attempted. One second later the "guard" would impose no minimum
        // interval at all. No caller passes 0 today, but nothing enforced
        // that until now.
        if ($intervalHours < 1) {
            return false;
        }

        $this->db->query(
            "UPDATE er_cron_job_runs
                SET last_run_at = NOW()
              WHERE job_name = ?
                AND enabled = 1
                AND (last_run_at IS NULL OR last_run_at < NOW() - INTERVAL ? HOUR)",
            [$jobName, $intervalHours]
        );

        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_REQUEST, "CronJobGuard::claim failed for {$jobName}: " . $this->db->errorString());
            return false;
        }

        return $this->db->count() === 1;
    }

    /**
     * Stamp `last_failure_at` for a run whose `execute()` threw.
     *
     * The counterpart to {@see self::claim()}, and here rather than in
     * {@see AbstractCronJob::run()} for the same reason `claim()` is: this
     * class owns `er_cron_job_runs`' run-tracking columns, and a caller that
     * wrote `last_failure_at` directly would be a second place with an opinion
     * about how a run is recorded. It also inherits the allowlist check for
     * free, so an unrecognized job name cannot stamp a row `claim()` would
     * never have let it claim.
     *
     * WHY THIS COLUMN HAS TO EXIST AT ALL. `claim()` stamps `last_run_at`
     * *before* the work runs — it has to, since the stamp is what stops a
     * second concurrent run. So `last_run_at` alone can only ever say "a run
     * was claimed", and a job that throws on every hit still presents a fresh
     * timestamp and (via {@see CronJobRunsReader::badgeFor()}) a green "Ran"
     * badge. Recording the failure on its own column is what lets the reader
     * compare the two and report the most recent claimed run's actual outcome.
     *
     * NEVER THROWS, AND NEVER REPORTS. `run()`'s catch block calls this
     * immediately before writing the failure log line an operator actually
     * reads, and that line must be written whatever happens here — so this
     * returns void, swallows the prepare()-time PDOException an unapplied
     * 20260922171500 migration raises (DB::query() calls PDO::prepare()
     * outside its own try block with ERRMODE_EXCEPTION set), and logs its own
     * failure under a distinct message so "the job failed" and "the job failed
     * AND we could not record it" stay separable in the log. Losing the stamp
     * degrades the dashboard to the stale-badge behaviour that existed before
     * this column; letting it escape would suppress the failure report
     * entirely, which is strictly worse.
     *
     * A plain `UPDATE`, not an atomic claim-style write: the caller already
     * won the claim before `execute()` ran, so there is no concurrent run to
     * race. No `count()` check either — MySQL reports rows CHANGED, not
     * matched (this connection sets no MYSQL_ATTR_FOUND_ROWS, see
     * users/classes/DB.php), and `NOW()` at one-second resolution means two
     * failures inside the same second legitimately change nothing. A
     * `count() === 0` check would log a false "row not seeded" line into the
     * very category an operator filters on to find real faults — the same
     * pitfall {@see SendVerificationBatchJob::recordRunCounts()} documents.
     *
     * @param string $jobName Must be in ALLOWED_JOB_NAMES
     */
    public function recordFailure(string $jobName): void
    {
        if (!in_array($jobName, self::ALLOWED_JOB_NAMES, true)) {
            return;
        }

        try {
            $this->db->query(
                'UPDATE er_cron_job_runs SET last_failure_at = NOW() WHERE job_name = ?',
                [$jobName]
            );
        } catch (\Throwable $e) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': the run failure itself could not be recorded on er_cron_job_runs"
                . ' — the dashboard will keep showing the previous outcome.'
                . ' Check that 20260922171500_add_cron_job_runs_last_failure_at has applied: %s',
                $jobName,
                $e->getMessage()
            ));
            return;
        }

        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': the run failure itself could not be recorded on er_cron_job_runs"
                . ' — the dashboard will keep showing the previous outcome: %s',
                $jobName,
                $this->db->errorString() ?: 'unknown'
            ));
        }
    }
}
