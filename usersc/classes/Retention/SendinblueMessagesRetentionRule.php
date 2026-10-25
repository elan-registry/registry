<?php

declare(strict_types=1);

namespace ElanRegistry\Retention;

use DateTimeImmutable;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\RetentionPurgeException;

/**
 * Retention rule for the sendinblue plugin's log of sent messages,
 * `plg_sendinblue_messages` (#2384).
 *
 * app/owner/privacy.php promises that this log keeps a recipient address for
 * 90 days. The window comes from `RETENTION_SENDINBLUE_MESSAGES_DAYS` in
 * usersc/includes/config.php, through {@see RetentionPolicy::rules()}.
 *
 * DELETE OR CLEAR. A row older than the window is deleted, unless an
 * `er_email_events` row still names its `message_id`. The rule keeps that row
 * and clears only its `recipient_email`. The row keeps its `message_id` and
 * `tags`, so that the owner-contact filter of
 * {@see \ElanRegistry\Car\CarRepository::countSoftBouncesSinceLastDelivered()}
 * (#2460) still works. No address stays past the window.
 *
 * NO TRANSACTION. Each statement is idempotent. If the run stops between the
 * two statements, the next run does the rest.
 *
 * The cutoff is in PHP time, because the plugin writes `accepted_at` with
 * PHP's `date()`.
 *
 * @package ElanRegistry\Retention
 * @since v2.30.10
 * @see https://github.com/elan-registry/registry/issues/2384
 */
final class SendinblueMessagesRetentionRule implements RetentionRule
{
    /** The name for log lines. */
    public const NAME = 'sendinblue_messages';

    /**
     * @param DatabaseInterface $db The database connection
     * @param int $retentionDays Days that a row keeps its address. Must be >= 1.
     * @throws \InvalidArgumentException If `$retentionDays` is less than 1
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly int $retentionDays,
    ) {
        // A window of 0 or less would purge the rows of mail sent today.
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException(
                "SendinblueMessagesRetentionRule: retention days must be at least 1, got {$retentionDays}"
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * {@inheritDoc}
     */
    public function purge(DateTimeImmutable $now): RetentionResult
    {
        // Check for the table before the DELETE. A SQLSTATE after the DELETE
        // cannot tell which table is missing: the subquery reads
        // er_email_events, and a missing er_email_events is a real failure.
        if (!$this->pluginTableExists()) {
            return RetentionResult::skipped(self::NAME, 'plg_sendinblue_messages table absent (sendinblue plugin not installed)');
        }

        $cutoff = $now->modify("-{$this->retentionDays} days")->format('Y-m-d H:i:s');

        // er_email_events uses utf8mb4_unicode_ci. The plugin makes its table
        // with DEFAULT CHARSET=utf8mb4 and no collation, so it gets the charset
        // default (utf8mb4_0900_ai_ci on MySQL 8). COLLATE prevents error 1267.
        // utf8mb4_bin on both sides also makes the match exact and
        // case-sensitive, as Brevo message IDs are.
        $this->db->query(
            'DELETE FROM plg_sendinblue_messages
              WHERE accepted_at < ?
                AND NOT EXISTS (
                    SELECT 1
                      FROM er_email_events e
                     WHERE e.brevo_message_id COLLATE utf8mb4_bin = plg_sendinblue_messages.message_id COLLATE utf8mb4_bin
                )',
            [$cutoff]
        );
        $this->throwIfFailed('delete');
        $deleted = $this->db->count();

        // Only the rows that the delete kept are still below the cutoff.
        $this->db->query(
            "UPDATE plg_sendinblue_messages
                SET recipient_email = ''
              WHERE accepted_at < ?
                AND recipient_email != ''",
            [$cutoff]
        );
        $this->throwIfFailed('clear');
        $cleared = $this->db->count();

        return RetentionResult::purged(self::NAME, $deleted, $cleared);
    }

    /**
     * Tell whether plg_sendinblue_messages exists in the current schema.
     *
     * @return bool True when the table exists
     * @throws RetentionPurgeException If the check fails or returns no COUNT row
     */
    private function pluginTableExists(): bool
    {
        $this->db->query(
            'SELECT COUNT(*) AS cnt
               FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_name = ?',
            ['plg_sendinblue_messages']
        );
        $this->throwIfFailed('table check');

        // A failed check must not read as "absent": the rule would skip and
        // keep addresses past the window with no failure signal.
        $row = $this->db->first();
        if (!is_object($row) || !isset($row->cnt)) {
            throw RetentionPurgeException::forStep(
                'SendinblueMessagesRetentionRule table check returned no COUNT row',
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
                "SendinblueMessagesRetentionRule {$step} failed: " . $this->db->errorString(),
                $step,
                preg_match('/^[0-9A-Z]{5}$/', $sqlState) === 1 ? $sqlState : null
            );
        }
    }
}
