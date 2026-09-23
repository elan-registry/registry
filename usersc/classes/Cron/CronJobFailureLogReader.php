<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;

/**
 * CronJobFailureLogReader - Read-only, never-throws access to recent
 * LOG_CATEGORY_CRON_JOB_FAILURE log entries for display
 *
 * `LOG_CATEGORY_CRON_JOB_FAILURE` was, until this class existed, write-only.
 * {@see AbstractCronJob::run()}, {@see SendVerificationBatchJob},
 * {@see CronJobRunsReader} and {@see CronJobGuard::recordFailure()} all file
 * entries under it, and several of those messages literally instruct the
 * reader to "check the system log for details" — but nothing in the admin UI
 * read the category back. An operator had to know the category name and go to
 * UserSpice's own log viewer to discover that an unattended pipeline had been
 * failing, which in practice means nobody discovered it. A per-job badge
 * ({@see CronJobRunsReader::badgeFor()}) reports only the job's most recent
 * run; this reports the fault channel as a whole, including faults from jobs
 * whose own row could not be read and from the manual `runNow()` path, which
 * never stamps a row at all.
 *
 * Its own class rather than another method on {@see CronJobRunsReader}: that
 * class is scoped to one table (`er_cron_job_runs`) and one job at a time,
 * and its never-throws contract is about a row read. This reads `logs`,
 * across all jobs, and is a display-only summary — sharing nothing but the
 * category constant. Same shape and contract as that class, though: read-only,
 * never throws, and reports a fault as an explicit unreadable flag rather than
 * a zero a caller would render as reassurance.
 *
 * @package ElanRegistry\Cron
 * @since v2.30.3
 */
final class CronJobFailureLogReader
{
    /**
     * How far back the summary looks.
     *
     * Seven days rather than a shorter window because the jobs filing into
     * this category run roughly daily (a ~20-hour guard interval), so a
     * 24-hour window would show at most one occurrence and could not
     * distinguish a one-off blip from the nightly-recurring fault that is the
     * dangerous case. A week is long enough to make a repeating failure
     * obvious by its count alone, and short enough that a fault fixed last
     * month has aged out rather than alarming an operator about history.
     */
    public const LOOKBACK_DAYS = 7;

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * Summarize cron-job failures logged within the lookback window.
     *
     * `count` is null, NOT 0, when the summary could not be produced — this is
     * a fault channel, and "no failures" is the one reading an operator will
     * take as permission to stop looking. Rendering an unreadable count as a
     * green zero would turn a broken log query into a clean bill of health,
     * the same failure mode {@see \ElanRegistry\Car\VerificationSettings::unmatchedRecipientCount()}
     * returns null to avoid. A null `count` is always accompanied by an empty
     * `recent`, so a caller that only branches on `count` cannot accidentally
     * render half a result.
     *
     * The most recent few entries come back alongside the count because the
     * count alone says only that something is wrong, and an operator standing
     * in front of the dashboard needs enough to decide whether it is one job
     * or all of them before going to the full log viewer.
     *
     * @param int $limit Maximum number of recent entries to return
     * @return array{count: int|null, recent: list<array{loggedAt: string, message: string}>}
     */
    public function recentFailures(int $limit = 5): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - (self::LOOKBACK_DAYS * 24 * 60 * 60));
        $empty = ['count' => null, 'recent' => []];

        $this->db->query(
            'SELECT COUNT(*) AS cnt FROM logs WHERE logtype = ? AND logdate >= ?',
            [LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $cutoff]
        );

        if ($this->db->error()) {
            // Deliberately NOT logged. Every other fault in this subsystem
            // logs under LOG_CATEGORY_CRON_JOB_FAILURE, but this method's
            // whole job is to read that category — logging a failure to read
            // the log into the log it could not read would add a row that
            // this same query may be unable to see, and would grow the
            // category by one entry on every admin page view for as long as
            // the fault lasts. The null return is the signal; the caller
            // renders it as an explicit "unavailable".
            return $empty;
        }

        $countRow = $this->db->first(true);

        if (!is_array($countRow) || !isset($countRow['cnt'])) {
            return $empty;
        }

        $count = (int) $countRow['cnt'];

        if ($count === 0) {
            // A genuine zero, and the only place this class reports one: the
            // count query succeeded and found nothing. No second query — there
            // is nothing to list.
            return ['count' => 0, 'recent' => []];
        }

        // Bound defensively rather than trusting the caller: this value is
        // interpolated into the LIMIT clause, which cannot be a bound
        // parameter under this connection's emulated-prepares setting (a
        // bound LIMIT arrives quoted as a string and is rejected). Casting to
        // int and clamping is what makes that interpolation safe, so neither
        // step is optional.
        $safeLimit = max(1, min(50, $limit));

        $this->db->query(
            'SELECT logdate, lognote FROM logs WHERE logtype = ? AND logdate >= ?'
            . ' ORDER BY logdate DESC, id DESC LIMIT ' . $safeLimit,
            [LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $cutoff]
        );

        if ($this->db->error()) {
            // The count is already known good — report it rather than
            // discarding it because the detail query failed. A count with no
            // detail still tells an operator to go and look.
            return ['count' => $count, 'recent' => []];
        }

        $recent = [];

        foreach ($this->db->results(true) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $recent[] = [
                'loggedAt' => (string) ($row['logdate'] ?? ''),
                'message' => (string) ($row['lognote'] ?? ''),
            ];
        }

        return ['count' => $count, 'recent' => $recent];
    }
}
