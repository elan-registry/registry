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
 * name, no side effects, and `last_run_at` in addition to `enabled`. Rather
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
     * @param string $jobName The er_cron_job_runs.job_name value to look up
     * @return array{state: CronJobEnabledState, lastRunAt: ?DateTimeImmutable}
     */
    public function status(string $jobName): array
    {
        $row = $this->fetchRow($jobName);

        if ($row === null) {
            return [
                'state' => $this->db->error() ? CronJobEnabledState::UNREADABLE : CronJobEnabledState::MISSING,
                'lastRunAt' => null,
            ];
        }

        $state = (bool) $row['enabled'] ? CronJobEnabledState::ENABLED : CronJobEnabledState::DISABLED;

        return [
            'state' => $state,
            'lastRunAt' => $this->parseLastRunAt($jobName, $row['last_run_at'] ?? null),
        ];
    }

    /**
     * Parse a raw `last_run_at` column value into a DateTimeImmutable.
     *
     * @param string $jobName Used only for log messages
     * @param mixed $rawLastRunAt The row's `last_run_at` column value
     * @return DateTimeImmutable|null Null when empty or unparseable
     */
    private function parseLastRunAt(string $jobName, mixed $rawLastRunAt): ?DateTimeImmutable
    {
        if (empty($rawLastRunAt)) {
            return null;
        }

        $value = (string) $rawLastRunAt;

        // MySQL's zero-date ('0000-00-00 00:00:00') does not throw when passed
        // to DateTimeImmutable's constructor — it silently parses to a bogus
        // year -1 date instead, which would otherwise slip past the catch
        // block below as a "successfully parsed" value. This column is only
        // ever written by CronJobGuard::claim()'s `NOW()`, so a zero-date here
        // can only mean external/manual tampering or a schema-level default
        // misconfiguration — treat it the same as any other unparseable value.
        if (str_starts_with($value, '0000-00-00')) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': unparseable last_run_at \"%s\": MySQL zero-date",
                $jobName,
                $value
            ));
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable $e) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s': unparseable last_run_at \"%s\": %s",
                $jobName,
                $value,
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Map a job's state and last-run timestamp to display attributes for the
     * verification tab's reconciliation status badge.
     *
     * @param CronJobEnabledState $state
     * @param DateTimeImmutable|null $lastRunAt
     * @return array{badgeClass: string, icon: string, text: string}
     */
    public static function badgeFor(CronJobEnabledState $state, ?DateTimeImmutable $lastRunAt): array
    {
        return match ($state) {
            CronJobEnabledState::ENABLED => $lastRunAt !== null
                ? ['badgeClass' => 'badge text-bg-success', 'icon' => 'fa-check-circle', 'text' => 'Ran']
                : ['badgeClass' => 'badge text-bg-secondary', 'icon' => 'fa-hourglass-half', 'text' => 'Never run'],
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
        // query() never throws; a failure is reported by error() (see DatabaseInterface).
        $this->db->query(
            'SELECT enabled, last_run_at FROM er_cron_job_runs WHERE job_name = ?',
            [$jobName]
        );

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
