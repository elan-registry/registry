<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;

/**
 * AbstractCronJob - Template-method base for all UserSpice cron jobs
 *
 * cron.php's dispatcher runs every active job's file via `include_once`
 * **in-process**, with no subprocess boundary (see `users/cron/cron.php`): one
 * job's fatal error or infinite loop can kill every job after it in that hit's
 * `sort` order, and nothing at the dispatcher level enforces a timeout. This
 * class is the three-layer mitigation every new cron job must build on:
 *
 *   1. Crash isolation: `execute()` runs inside a `try/catch(\Throwable)` that
 *      logs and never rethrows, so one job's bug can't cascade to the next job
 *      in cron.php's loop.
 *   2. A `set_time_limit()` backstop sized from `CRON_TRANSPORT_INTERVAL_MINUTES`,
 *      applied only once a run has actually been claimed (see `run()`), so a
 *      hung job doesn't run forever.
 *   3. A job-owned `enabled` flag (`er_cron_job_runs.enabled`, independent of
 *      UserSpice's own `crons.active`) checked before any work runs.
 *
 * The enabled check is a dedicated read rather than an inference from
 * `CronJobGuard::claim()`'s boolean: `claim()` deliberately conflates
 * "disabled", "claimed too recently" and "unrecognized job name" into a single
 * silent `false` (see its class docblock), which is correct for a guard but
 * leaves an operator who paused a job with no signal that this is why it
 * stopped running. Reading the row here closes that gap, and resolves it to
 * three distinct states ({@see CronJobEnabledState}) rather than one boolean:
 * a deliberate pause logs under `LOG_CATEGORY_CRON_JOB_SKIPPED`, while a
 * missing or unreadable row logs under `LOG_CATEGORY_CRON_JOB_FAILURE` — an
 * infrastructure fault must never be indistinguishable from an operator's own
 * choice. The guard keeps its own `enabled = 1` gate, so the two never
 * disagree about whether work runs.
 *
 * See DEPLOYMENT.md's cron-author contract, "Timeout & Crash Isolation"
 * section, for the full rationale — this docblock intentionally doesn't
 * duplicate it.
 *
 * Subclasses implement `jobName()`, `guardIntervalHours()` and `execute()`
 * only. `run()` is `final` — subclasses must not override the
 * crash-isolation/guard sequencing.
 *
 * @package ElanRegistry\Cron
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1889
 */
abstract class AbstractCronJob
{
    public function __construct(protected readonly DatabaseInterface $db)
    {
    }

    /** The er_cron_job_runs.job_name / CronJobGuard::ALLOWED_JOB_NAMES value for this job. */
    abstract protected function jobName(): string;

    /** Minimum hours between claimed runs — passed to CronJobGuard::claim(). Must be >= 1. */
    abstract protected function guardIntervalHours(): int;

    /** The job's actual work. Any \Throwable here is caught, logged, and swallowed by run(). */
    abstract protected function execute(): void;

    /**
     * Run this job: check enabled, claim the guard, set a time-limit
     * backstop, execute with crash isolation. Never throws.
     */
    final public function run(): void
    {
        $jobName = $this->jobName();

        $state = $this->enabledState();

        if ($state !== CronJobEnabledState::ENABLED) {
            // MISSING and UNREADABLE were already logged (under
            // LOG_CATEGORY_CRON_JOB_FAILURE) inside enabledState() — logging
            // again here would double-count the same fault.
            if ($state === CronJobEnabledState::DISABLED && $this->shouldLogSkip()) {
                logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED, "Cron job '{$jobName}' is disabled (er_cron_job_runs.enabled = 0) — skipped.");
                $this->recordSkipLogged();
            }
            return;
        }

        try {
            // A false claim is the routine "not due yet" / lost-the-race case,
            // not a failure — return quietly rather than logging on every
            // cron hit, matching CronJobGuard's own silent-no-op default.
            if (!(new CronJobGuard($this->db))->claim($jobName, $this->guardIntervalHours())) {
                return;
            }

            // Backstop against a hung job blocking every job after it in
            // cron.php's in-process dispatch loop: one transport interval is
            // the longest a job can run before the next cron hit would want to
            // start work anyway.
            //
            // Deliberately after the claim, not before it. cron.php dispatches
            // every job in one PHP process, so calling this on every hit would
            // reset the shared execution clock once per enabled job — on the
            // ~143 of 144 daily hits where the claim fails immediately, that
            // grants a fresh full interval to a process that is doing no work,
            // making the real budget N-jobs × the interval instead of bounding
            // it. Only a claimed run is about to consume time, so only a
            // claimed run arms the backstop.
            set_time_limit(CRON_TRANSPORT_INTERVAL_MINUTES * 60);

            $this->execute();
        } catch (\Throwable $e) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, "Cron job '{$jobName}' failed: " . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * Run this job's work immediately, bypassing both the enabled check and
     * the CronJobGuard claim.
     *
     * For the manual "run now" admin maintenance path only — an operator who
     * explicitly triggers a run has already made the scheduling decision the
     * guard exists to make. Carries the same crash isolation as `run()` so a
     * job bug cannot fatal the admin page that invoked it. Never throws.
     *
     * Deliberately does not call `set_time_limit()`: this runs inside an
     * admin-triggered page request, which already carries the web SAPI's own
     * `max_execution_time`. Re-arming it here would silently extend that page
     * request's budget past what the operator's own request was granted.
     */
    final public function runNow(): void
    {
        try {
            $this->execute();
        } catch (\Throwable $e) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, "Cron job '{$this->jobName()}' failed (manual run): " . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * True when at least one guard interval has elapsed since this job last
     * *logged* a skip line (or it never has), as reported by the same row read.
     *
     * Set by {@see self::enabledState()}; only meaningful when that returned
     * DISABLED.
     */
    private bool $skipLogIsDue = false;

    /**
     * Resolve this job's er_cron_job_runs row into an enabled state.
     *
     * Fails safe in all three non-enabled cases — nothing runs — but reports
     * and logs them distinctly, because they call for opposite operator
     * responses. MISSING and UNREADABLE are genuine faults (a job whose
     * bookkeeping row was never seeded will never run again, silently), so
     * they are logged here under LOG_CATEGORY_CRON_JOB_FAILURE rather than
     * being left for the caller to report as an operator's deliberate pause.
     * DISABLED is not logged here — only `run()` logs it, and only when
     * {@see self::$skipLogIsDue} says the skip is worth another line.
     *
     * @return CronJobEnabledState
     */
    private function enabledState(): CronJobEnabledState
    {
        $jobName = $this->jobName();

        // query() never throws; a failure is reported by error() (see DatabaseInterface).
        $this->db->query(
            'SELECT enabled,
                    (last_skip_logged_at IS NULL
                     OR last_skip_logged_at < NOW() - INTERVAL ? HOUR) AS skip_log_is_due
               FROM er_cron_job_runs
              WHERE job_name = ?',
            [$this->guardIntervalHours(), $jobName]
        );

        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': er_cron_job_runs could not be read — job not run."
                . ' This is an infrastructure fault, not a paused job: %s',
                $jobName,
                $this->db->errorString() ?: 'unknown'
            ));
            return CronJobEnabledState::UNREADABLE;
        }

        $row = $this->db->first(true);

        if (!is_array($row) || !isset($row['enabled'])) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': no er_cron_job_runs row — job can never run until one is seeded."
                . ' Check that the job name matches a migration-seeded row.',
                $jobName
            ));
            return CronJobEnabledState::MISSING;
        }

        if (!(bool) $row['enabled']) {
            $this->skipLogIsDue = (bool) ($row['skip_log_is_due'] ?? true);
            return CronJobEnabledState::DISABLED;
        }

        return CronJobEnabledState::ENABLED;
    }

    /**
     * Whether run() should emit the "job is disabled" skip line on this hit.
     *
     * Rate-limited to roughly once per guard interval, not once per cron hit.
     * cron.php fires every CRON_TRANSPORT_INTERVAL_MINUTES (~144 hits/day), so
     * logging unconditionally would put ~1000 identical rows in the log for a
     * job left paused a week — exactly the pathology issue #1974 removed from
     * the cron transport earlier in this same milestone, where a per-hit line
     * carried no diagnostic value past its first occurrence. Under-logging
     * here is deliberate; see #1974.
     *
     * The throttle is keyed on a dedicated column, er_cron_job_runs
     * .last_skip_logged_at, which {@see self::recordSkipLogged()} advances
     * each time the line actually fires. It cannot be keyed on last_run_at —
     * the obvious-looking choice, since that is what CronJobGuard::claim()
     * gates the enabled case on. last_run_at is written *only* by claim(), and
     * a disabled job never reaches claim(): run() returns above. So while a
     * job stays paused its last_run_at never moves, and a predicate of the
     * form "last_run_at older than one interval" latches permanently true —
     * silencing at most the first interval, then logging on every hit forever
     * after. For a job seeded with last_run_at = NULL and paused before its
     * first-ever run, it is true from the very first hit and never silences
     * anything at all. Only a column the logging path itself writes can
     * throttle the logging path, which is why this one is separate and why
     * last_run_at stays exclusively CronJobGuard's.
     */
    private function shouldLogSkip(): bool
    {
        return $this->skipLogIsDue;
    }

    /**
     * Stamp last_skip_logged_at so the next interval's worth of cron hits stay
     * quiet. Called only when the skip line actually fired — stamping on every
     * disabled hit would keep the timestamp perpetually fresh and suppress the
     * line forever, the opposite failure to the one described above.
     *
     * Not routed through CronJobGuard: that class owns run-claim semantics and
     * the last_run_at column, and conflating log bookkeeping with a claim
     * would let a paused job's logging move a timestamp the scheduler reads.
     * Deliberately a plain write after the read rather than an atomic
     * claim-style UPDATE ... WHERE: cron.php dispatches jobs sequentially
     * in-process, and the worst case under a genuine race is one duplicate log
     * line, not a job running twice.
     *
     * A failed write is not logged: it would be reporting a logging failure by
     * logging, and the only consequence is that the next hit repeats the skip
     * line — noise the caller is already tolerating one interval of.
     */
    private function recordSkipLogged(): void
    {
        $this->db->query(
            'UPDATE er_cron_job_runs SET last_skip_logged_at = NOW() WHERE job_name = ?',
            [$this->jobName()]
        );
    }
}
