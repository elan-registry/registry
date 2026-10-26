<?php

declare(strict_types=1);

namespace ElanRegistry\Retention;

use DateTimeImmutable;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\RetentionPurgeException;
use ElanRegistry\LogCategories;

/**
 * Alarm rule for stale `er_deleted_user_addresses` holding rows.
 *
 * The trigger `users_delete_capture_addresses` writes a holding row when a
 * `users` row is deleted. The account-deletion privacy step deletes that row
 * in the same transaction as its other deletes. So a holding row older than
 * {@see self::MAX_AGE_HOURS} shows a privacy step that did not commit: the
 * user's profile, plugin mail rows and email events can still hold personal
 * data.
 *
 * DELETES NOTHING. The rerun script
 * (app/admin/scripts/maintenance/30-Rerun-Deletion-Privacy-Step.php) reads
 * the addresses from the holding row, so the row must stay until the rerun.
 * The rule logs the user IDs and the remedy, then throws. The job then fails
 * the run and pings `/fail`, each night until an admin runs the rerun.
 *
 * The cutoff is MySQL's `NOW()`, not the PHP `$now`, because the trigger
 * writes `deleted_at` with MySQL's `NOW()`. PHP and MySQL can use different
 * time zones (see CarRepository::freshnessSql()).
 *
 * Log lines and exception messages hold user IDs and counts only, never an
 * address.
 *
 * @package ElanRegistry\Retention
 * @since v2.30.10
 * @see https://github.com/elan-registry/registry/issues/2419
 */
final class DeletedUserAddressesBacklogRule implements RetentionRule
{
    /** The name for log lines. */
    public const NAME = 'deleted_user_addresses_backlog';

    /**
     * Age at which a holding row shows a failed privacy step.
     *
     * The job runs once a day with a 20-hour guard, so 24 hours alarms on the
     * first or second run after the failure and never on a deletion in progress.
     */
    public const MAX_AGE_HOURS = 24;

    /**
     * @param DatabaseInterface $db The database connection
     */
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Fail when a holding row is older than {@see self::MAX_AGE_HOURS}.
     *
     * @param DateTimeImmutable $now The run time. Not used: see the class comment.
     * @return RetentionResult Zero counts when no row is stale, or a skip when the table does not exist
     * @throws RetentionPurgeException If a query fails, or if one or more holding rows are stale
     */
    public function purge(DateTimeImmutable $now): RetentionResult
    {
        if (!$this->tableExists()) {
            return RetentionResult::skipped(self::NAME, 'er_deleted_user_addresses table absent (migration not run)');
        }

        $this->db->query(
            'SELECT user_id
               FROM er_deleted_user_addresses
              WHERE deleted_at < NOW() - INTERVAL ' . self::MAX_AGE_HOURS . ' HOUR
              ORDER BY user_id'
        );
        $this->throwIfFailed('stale row check');

        $userIds = array_map(
            static fn (object $row): int => (int) $row->user_id,
            $this->db->results()
        );

        if ($userIds === []) {
            return RetentionResult::purged(self::NAME, 0, 0);
        }

        $summary = sprintf(
            'Account-deletion privacy step not complete for %d deleted user(s): er_deleted_user_addresses rows older than %d hours for user IDs %s.',
            count($userIds),
            self::MAX_AGE_HOURS,
            implode(', ', $userIds)
        );

        logger(0, LogCategories::LOG_CATEGORY_RETENTION_PURGE, $summary
            . ' Run app/admin/scripts/maintenance/30-Rerun-Deletion-Privacy-Step.php for each user ID.');

        throw RetentionPurgeException::forStep($summary, 'stale holding rows', null);
    }

    /**
     * Tell whether er_deleted_user_addresses exists in the current schema.
     *
     * @return bool True when the table exists
     * @throws RetentionPurgeException If the check fails or returns no COUNT row
     */
    private function tableExists(): bool
    {
        $this->db->query(
            'SELECT COUNT(*) AS cnt
               FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_name = ?',
            ['er_deleted_user_addresses']
        );
        $this->throwIfFailed('table check');

        // A failed check must not read as "absent": the rule would skip and
        // hide a failed privacy step.
        $row = $this->db->first();
        if (!is_object($row) || !isset($row->cnt)) {
            throw RetentionPurgeException::forStep(
                'DeletedUserAddressesBacklogRule table check returned no COUNT row',
                'table check',
                null
            );
        }

        return (int) $row->cnt > 0;
    }

    /**
     * Throw when the last query failed.
     *
     * @param string $step The statement name for the exception message
     * @throws RetentionPurgeException If the last query failed
     */
    private function throwIfFailed(string $step): void
    {
        if ($this->db->error()) {
            $sqlState = (string) ($this->db->errorInfo()[0] ?? '');
            throw RetentionPurgeException::forStep(
                "DeletedUserAddressesBacklogRule {$step} failed: " . $this->db->errorString(),
                $step,
                preg_match('/^[0-9A-Z]{5}$/', $sqlState) === 1 ? $sqlState : null
            );
        }
    }
}
