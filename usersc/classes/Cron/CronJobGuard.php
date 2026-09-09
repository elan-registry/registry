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
 * job. Currently just `reconciliation`, claimed by
 * {@see AbstractCronJob::run()} on behalf of
 * {@see BrevoEventReconciliationJob} (#1889). The allowlist and the seeded
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
        'reconciliation',
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
}
