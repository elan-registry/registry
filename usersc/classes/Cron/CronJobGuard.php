<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;

/**
 * Atomic-claim guard for cron jobs, used to prevent duplicate/overlapping
 * scheduled runs.
 *
 * `$columnName` must be in the `ALLOWED_COLUMNS` allowlist below — it grows
 * only when a new caller genuinely needs a new column. Currently just
 * `reconciliation_last_run`, added for issue #1889's future consumption; not
 * used by any caller yet.
 */
final class CronJobGuard
{
    private const ALLOWED_COLUMNS = [
        'reconciliation_last_run',
    ];

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Atomically claim a cron run by updating the settings timestamp column,
     * but only if the interval has elapsed since the last claim.
     *
     * @param string $columnName Must be in ALLOWED_COLUMNS
     * @param int $intervalHours Minimum hours since the last claim
     * @return bool True if this call claimed the run, false otherwise
     */
    public function claim(string $columnName, int $intervalHours): bool
    {
        if (!in_array($columnName, self::ALLOWED_COLUMNS, true)) {
            return false;
        }

        $this->db->query(
            "UPDATE settings
                SET `{$columnName}` = NOW()
              WHERE id = 1
                AND (`{$columnName}` IS NULL OR `{$columnName}` < NOW() - INTERVAL ? HOUR)",
            [$intervalHours]
        );

        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_REQUEST, "CronJobGuard::claim failed for {$columnName}: " . $this->db->errorString());
            return false;
        }

        return $this->db->count() === 1;
    }
}
