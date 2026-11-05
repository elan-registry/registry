<?php

declare(strict_types=1);

namespace ElanRegistry\Email;

use DateTimeImmutable;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\AdminOperationException;

/**
 * DeliveryLogRepository - the read-only SQL of the admin email delivery tab (#2102)
 *
 * Reads `plg_sendinblue_messages` (the sendinblue plugin's log of sent mail)
 * and its `er_email_events` rows. It never writes, and it never calls Brevo.
 *
 * Events attach only by message ID, never by address. `sent` events are left
 * out in the join, because they are never an outcome.
 *
 * @package ElanRegistry\Email
 * @since v2.31.1
 * @see https://github.com/elan-registry/registry/issues/2102
 */
final class DeliveryLogRepository
{
    /** The tab and loadWindow() both ask, so one request runs the check once. */
    private ?bool $hasTags = null;

    /**
     * @param DatabaseInterface $db The database connection
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Tell whether `plg_sendinblue_messages` exists.
     *
     * It is absent when the sendinblue plugin is not installed.
     *
     * @return bool True when the table exists
     * @throws AdminOperationException If the check fails
     */
    public function tablesReady(): bool
    {
        $this->db->query(
            'SELECT COUNT(*) AS cnt
               FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_name = ?',
            ['plg_sendinblue_messages']
        );
        $this->throwIfFailed('table check');

        return $this->countFromFirstRow('table check') > 0;
    }

    /**
     * Tell whether `plg_sendinblue_messages` has its `tags` column.
     *
     * An old plugin table has no `tags` column (it arrives with plugin
     * migration 00008). Then the tab hides the tag filter.
     *
     * @return bool True when the column exists
     * @throws AdminOperationException If the check fails
     */
    public function hasTagsColumn(): bool
    {
        if ($this->hasTags !== null) {
            return $this->hasTags;
        }

        $this->db->query(
            'SELECT COUNT(*) AS cnt
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = ?
                AND column_name = ?',
            ['plg_sendinblue_messages', 'tags']
        );
        $this->throwIfFailed('tags column check');

        return $this->hasTags = $this->countFromFirstRow('tags column check') > 0;
    }

    /**
     * Load each mail accepted at or after the cutoff, with its outcome events.
     *
     * One row per mail and event. A mail with no event gives one row with
     * null event fields. Keys: `id`, `message_id`, `recipient_email`, `tags`
     * (null when the column is absent), `accepted_at`, `event_id`, `event`,
     * `occurred_at`, `reason`, `user_id`, `car_id`. Values are as PDO returns
     * them, so the caller casts.
     *
     * The cutoff is in PHP time, because the plugin writes `accepted_at` with
     * PHP's `date()`. The plugin table has no index that leads with
     * `accepted_at`, so the query scans the table. A recipient filter can use
     * the `recipient_accepted` index. The plugin owns this table, so the
     * Registry does not add an index to it.
     *
     * @param DateTimeImmutable $cutoff    The oldest `accepted_at` to include
     * @param string|null       $recipient The whole recipient address, or null for any.
     *                                     The column collation makes the match case-insensitive.
     * @param string|null       $tag       One tag (commas already folded), or null for any.
     *                                     Ignored when the `tags` column is absent.
     * @return list<array<string, mixed>> The rows, in no set order
     * @throws AdminOperationException If a query fails
     */
    public function loadWindow(DateTimeImmutable $cutoff, ?string $recipient, ?string $tag): array
    {
        $hasTags = $this->hasTagsColumn();

        $where = ['m.accepted_at >= ?'];
        $params = [$cutoff->format('Y-m-d H:i:s')];
        if ($recipient !== null) {
            $where[] = 'm.recipient_email = ?';
            $params[] = $recipient;
        }
        if ($tag !== null && $hasTags) {
            $where[] = 'FIND_IN_SET(?, m.tags) > 0';
            $params[] = $tag;
        }

        // er_email_events uses utf8mb4_unicode_ci. The plugin table takes the
        // database default, utf8mb4_0900_ai_ci. COLLATE prevents error 1267
        // for this mix of collations. utf8mb4_bin also makes the match exact,
        // because Brevo message IDs are case-sensitive. Only fixed SQL
        // fragments are joined. Every value is bound.
        $this->db->query(
            'SELECT m.id, m.message_id, m.recipient_email, '
            . ($hasTags ? 'm.tags' : 'NULL AS tags')
            . ', m.accepted_at,
                    e.id AS event_id, e.event, e.occurred_at, e.reason, e.user_id, e.car_id
               FROM plg_sendinblue_messages m
               LEFT JOIN er_email_events e
                 ON e.brevo_message_id COLLATE utf8mb4_bin = m.message_id COLLATE utf8mb4_bin
                AND e.event <> \'sent\'
              WHERE ' . implode(' AND ', $where),
            $params
        );
        $this->throwIfFailed('window load');

        // results(true) builds arrays with a JSON round trip, and one value
        // that is not valid UTF-8 makes it return no rows and no error. The
        // object rows come straight from PDO.
        return array_values(array_map(
            static fn(array|object $row): array => (array) $row,
            $this->db->results()
        ));
    }

    /**
     * Read the `cnt` value of the first row of the last query.
     *
     * @param string $step The step name for the exception message
     * @return int The count
     * @throws AdminOperationException If the query returned no COUNT row
     */
    private function countFromFirstRow(string $step): int
    {
        // A missing row must not read as "absent": the tab would show the
        // plugin-not-installed alert for a database fault.
        $row = $this->db->first();
        if (!is_object($row) || !isset($row->cnt)) {
            throw new AdminOperationException("DeliveryLogRepository {$step} returned no COUNT row");
        }

        return (int) $row->cnt;
    }

    /**
     * Throw when the last query failed.
     *
     * @param string $step The step name for the exception message
     * @throws AdminOperationException If the last query failed
     */
    private function throwIfFailed(string $step): void
    {
        if ($this->db->error()) {
            throw new AdminOperationException(
                "DeliveryLogRepository {$step} failed: " . $this->db->errorString()
            );
        }
    }
}
