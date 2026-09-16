<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

use ElanRegistry\Car\CarVerificationSendService;
use ElanRegistry\Car\VerificationBatchSender;
use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;

/**
 * SendVerificationBatchJob - Automatic nightly send of one verification-email
 * batch
 *
 * The verification pipeline (#1882-#1884) could already send a batch, but only
 * when an admin remembered to click "Send Batch Now" on the Verification System
 * tab. This job puts that same send on UserSpice's cron transport so batches go
 * out unattended, gated by {@see AbstractCronJob}'s claim so it survives being
 * invoked every ~10 minutes by `users/cron/cron.php`.
 *
 * NO SEND LOGIC LIVES HERE. `execute()` selects candidates via
 * {@see CarVerificationSendService::findEligible()} — the same shared service
 * {@see VerificationBatchSender} sends through, not a direct
 * {@see \ElanRegistry\Car\CarRepository} call — and hands their ids straight to
 * {@see VerificationBatchSender::processBatch()} — the exact class
 * the manual admin handler already calls. The eligibility re-check against the
 * row as it stands at send time, the vericode rotate-and-restore-on-failure,
 * the per-car failure containment, and the `sent` `er_email_events` row are all
 * that class's existing behavior. Reimplementing any of it here would create a
 * second definition of "how a verification email is sent" that could drift from
 * the manual path an operator tests against — the two must stay
 * byte-for-byte the same send, differing only in what triggers them.
 *
 * BOUNDED WORK PER INVOCATION. One page of at most
 * {@see VerificationSettings::batchSize()} cars (clamped to [1, 25] at the
 * write side) per claimed run, never a loop until the eligible set empties.
 * cron.php dispatches jobs in-process with no subprocess boundary (see
 * {@see AbstractCronJob}), so an unbounded walk over the ~1,500-car eligible
 * backlog would hold the whole cron hit open — and, more importantly, would
 * defeat the deliberate slow ramp the batch size exists to enforce: sender
 * reputation is the constraint here, not throughput. Whatever this run doesn't
 * reach is simply picked up by the next claim, oldest-verified first.
 *
 * {@see AbstractCronJob::run()} already applies the site-wide
 * {@see VerificationSettings::isEnabled()} gate, the job-owned
 * `er_cron_job_runs.enabled` pause flag, the `CronJobGuard` claim, the
 * `set_time_limit()` backstop, and top-level crash isolation. None of that is
 * repeated here — this class implements only the three abstract methods plus
 * its own dashboard bookkeeping.
 *
 * @package ElanRegistry\Cron
 * @since v2.30.3
 * @see https://github.com/elan-registry/registry/issues/1885
 */
final class SendVerificationBatchJob extends AbstractCronJob
{
    /** er_cron_job_runs.job_name / CronJobGuard::ALLOWED_JOB_NAMES value. */
    public const JOB_NAME = 'send_verification_batch';

    /**
     * Minimum hours between claimed runs.
     *
     * 20, not 24, for the same reason {@see BrevoEventReconciliationJob} uses
     * 20: the claim is tested on whichever cron hit happens to land first past
     * the interval, so a hard 24 would push the effective send time later by
     * one transport interval every day until it wrapped around the clock. 20
     * leaves enough slack for the run to re-anchor at roughly the same hour,
     * while staying comfortably inside a once-a-day cadence — the interval is
     * a floor on the gap between batches, not a schedule.
     */
    private const GUARD_INTERVAL_HOURS = 20;

    /**
     * Collaborators are injected rather than constructed internally, matching
     * {@see BrevoEventReconciliationJob}'s convention: it keeps this class
     * unit-testable with fakes, without a real database, mailer, or Brevo
     * credentials present.
     *
     * @param DatabaseInterface $db AbstractCronJob's enabled-check/guard
     *        connection, also used by {@see self::recordRunCounts()}
     * @param VerificationSettings $settings Read only for the effective batch size
     * @param CarVerificationSendService $sendSvc Read only for
     *        {@see CarVerificationSendService::findEligible()} — the same
     *        shared eligibility query the admin preview and
     *        {@see VerificationBatchSender} (via its own injected instance)
     *        use, per that class's docblock naming this job as one of its two
     *        intended callers. Deliberately not a direct
     *        {@see \ElanRegistry\Car\CarRepository} dependency: this class must
     *        not have its own opinion about how eligibility is determined.
     * @param VerificationBatchSender $sender The shared per-car send loop
     */
    public function __construct(
        DatabaseInterface $db,
        private readonly VerificationSettings $settings,
        private readonly CarVerificationSendService $sendSvc,
        private readonly VerificationBatchSender $sender,
    ) {
        parent::__construct($db);
    }

    /**
     * {@inheritDoc}
     */
    protected function jobName(): string
    {
        return self::JOB_NAME;
    }

    /**
     * {@inheritDoc}
     */
    protected function guardIntervalHours(): int
    {
        return self::GUARD_INTERVAL_HOURS;
    }

    /**
     * Send one batch of verification emails and persist its outcome counts.
     *
     * Reached only after {@see AbstractCronJob::run()} has won the guard claim,
     * so every entry here is a real run — there is no further "is this due"
     * check to make. The work is deliberately thin: pick the batch size, ask
     * the repository for that many eligible cars, hand the ids to
     * {@see VerificationBatchSender}, record what came back.
     */
    protected function execute(): void
    {
        $batchSize = $this->settings->batchSize();

        try {
            $eligible = $this->sendSvc->findEligible($batchSize, 0);
        } catch (CarDatabaseException $e) {
            // A failed eligibility query is a genuine fault, not a quiet night:
            // the job cannot tell "nothing to send" from "couldn't ask", so it
            // logs under the category an operator filters on to find broken
            // jobs and sends nothing this run. The next claim retries — no car
            // is lost, since eligibility is recomputed from scratch each time.
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': eligible-car query FAILED (batch size %d) — nothing sent this run: %s",
                self::JOB_NAME,
                $batchSize,
                $e->getMessage()
            ));
            return;
        }

        $carIds = array_map(static fn (object $car): int => (int) $car->id, $eligible);

        if ($carIds === []) {
            // Routine, not a fault: the eligible set legitimately empties once
            // the backlog is worked through, and every car that is sent to
            // leaves the set for a year. Logged under the verification
            // category (not CRON_JOB_*) so it stays out of the fault channel,
            // but logged at all so an operator checking "did it run last
            // night?" sees a positive answer rather than silence.
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
                'Verification send: cron run found no eligible cars (batch size %d) — nothing sent.',
                $batchSize
            ));
            return;
        }

        $result = $this->sender->processBatch($carIds);

        // Recorded unconditionally, including when every send failed: a run
        // that attempted work and failed is exactly the state the dashboard
        // most needs to show, and suppressing the write on failure would leave
        // the previous run's counts standing as if nothing had happened since.
        //
        // `unrecorded` IS DELIBERATELY FOLDED INTO THE FAILED COUNT. An
        // "unrecorded" car did have its email sent successfully, but the
        // bookkeeping write that marks it as sent failed — so it remains
        // eligible and risks a duplicate send on the next run. There is no
        // fourth column to persist it in (adding one is a schema change out of
        // scope here), and of the two existing buckets it could join, `failed`
        // is the conservative one: showing a false failure prompts an operator
        // to investigate a state that genuinely needs it, whereas hiding it in
        // `sent` would report the run as clean. The routine completion log
        // line below is unaffected and still reports all four buckets
        // distinctly, so the full breakdown stays available in the log even
        // though the dashboard column merges two of them.
        $this->recordRunCounts(
            count($result['sent']),
            count($result['skipped']),
            count($result['failed']) + count($result['unrecorded'])
        );

        // LOG_CATEGORY_CAR_VERIFICATION, not either CRON_JOB_* category: both
        // of those are exception channels (a genuine fault, or a deliberate
        // pause), and a routine completed run is neither — filing success
        // there would dilute the one category an operator filters on to find
        // broken jobs. Matches BrevoEventReconciliationJob's identical choice
        // of a subsystem-specific category for its own run summary, and mirrors
        // the wording of the manual handler's success line in
        // app/admin/index.php so both paths' batches read the same in the log.
        logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
            'Verification send: cron batch complete — %d sent, %d unrecorded, %d skipped, %d failed',
            count($result['sent']),
            count($result['unrecorded']),
            count($result['skipped']),
            count($result['failed'])
        ));
    }

    /**
     * Persist this run's outcome counts on the job's er_cron_job_runs row.
     *
     * The dashboard renders the *last actual run's* outcome on a page load that
     * may happen many hours after cron fired, which logging alone cannot
     * support — hence these three columns. A plain `UPDATE`, deliberately not
     * an atomic claim-style write: {@see AbstractCronJob::run()} already won
     * the {@see CronJobGuard} claim before `execute()` was ever entered, so
     * there is no concurrent run to race against here.
     *
     * FAILURES ARE LOGGED, NEVER RETHROWN. This is best-effort display
     * bookkeeping, not the job's result. The emails this run sent are already
     * sent and already recorded in `er_email_events`; letting a failure to
     * persist three display integers propagate would reach
     * {@see AbstractCronJob::run()}'s catch-all and log the whole run as
     * failed, telling an operator that a successful batch went wrong. Stale
     * dashboard counts are the strictly better failure mode, and the log line
     * below is what makes them diagnosable.
     *
     * @param int $sent Cars whose email was sent and recorded
     * @param int $skipped Cars that left the eligible set before send time
     * @param int $failed Cars whose send failed, plus cars whose send
     *        succeeded but whose bookkeeping write did not — see the call site
     *        in {@see self::execute()} for why those two are merged here
     */
    private function recordRunCounts(int $sent, int $skipped, int $failed): void
    {
        // query() reports ordinary failures via error() rather than raising,
        // but it is NOT throw-free: DB::query() calls PDO::prepare() outside
        // its own try block with ERRMODE_EXCEPTION set, so a prepare()-time
        // fault — a missing column, which is exactly what a half-applied
        // 20260916000000 migration leaves behind — throws a PDOException
        // straight out. Without this catch, that throw would escape the
        // "NEVER RETHROWN" promise above and reach AbstractCronJob::run()'s
        // catch-all, logging a batch that actually sent as a failed run.
        try {
            $this->db->query(
                'UPDATE er_cron_job_runs
                    SET last_sent_count = ?, last_skipped_count = ?, last_failed_count = ?
                  WHERE job_name = ?',
                [$sent, $skipped, $failed, self::JOB_NAME]
            );
        } catch (\Throwable $e) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': run counts could not be written to er_cron_job_runs"
                . ' (the batch itself was unaffected — dashboard counts will be stale).'
                . ' Check that 20260916000000_add_cron_job_runs_last_outcome_counts has applied: %s',
                self::JOB_NAME,
                $e->getMessage()
            ));
            return;
        }

        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': run counts could not be written to er_cron_job_runs"
                . ' (the batch itself was unaffected — dashboard counts will be stale): %s',
                self::JOB_NAME,
                $this->db->errorString() ?: 'unknown'
            ));
            return;
        }

        if ($this->db->count() === 0) {
            // Same "row missing" reading as
            // VerificationSettings::recordCronRequest(): a plain SET of three
            // counts almost never writes the values a row already holds, so an
            // affected-row count of zero points at there being no row for this
            // job_name rather than at a no-op update. That is the same
            // never-seeded condition AbstractCronJob::enabledState() reports as
            // MISSING — unreachable on this path in practice, since a missing
            // row would have stopped run() before execute(), but worth its own
            // distinct line if it ever is reached.
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': no er_cron_job_runs row to write run counts to"
                . ' — the row appears not to be seeded.',
                self::JOB_NAME
            ));
        }
    }
}
