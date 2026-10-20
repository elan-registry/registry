<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\DatabaseInterface;
use RuntimeException;

/**
 * SendinblueMessagesTable - the sendinblue plugin's message table, for integration tests
 *
 * The test schema may not have `plg_sendinblue_messages`, because the plugin
 * makes it when it installs. This class holds its column list once, so the
 * test copies cannot drift from the plugin schema.
 *
 * The columns are the table after plugin migrate.php updates 00006-00008
 * (sendinblue 1.8.0). Update 00008 added `tags` after `kind`.
 *
 * The caller owns the drop. A class that gets `true` from
 * {@see createIfMissing()} drops the table in `tearDownAfterClass()`, so no
 * class drops a table that it did not make.
 *
 * @package Tests\Support
 * @see https://github.com/elan-registry/registry/issues/2420
 */
final class SendinblueMessagesTable
{
    private const TABLE = 'plg_sendinblue_messages';

    private const DEFINITION = '(
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        message_id VARCHAR(191) NOT NULL,
        recipient_email VARCHAR(255) NOT NULL,
        sender_email VARCHAR(255) NOT NULL,
        kind VARCHAR(32) NOT NULL,
        tags VARCHAR(255) NULL,
        status VARCHAR(32) NOT NULL,
        accepted_at DATETIME NOT NULL,
        delivered_at DATETIME NULL,
        last_event_at DATETIME NULL,
        last_checked_at DATETIME NULL,
        last_reason VARCHAR(255) NULL,
        UNIQUE KEY message_id (message_id),
        KEY recipient_accepted (recipient_email(128), accepted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    /**
     * Make the table when the schema does not have it
     *
     * @param DatabaseInterface $db Connection to the test schema
     * @return bool True when this call made the table, so the caller must drop it
     * @throws RuntimeException When the existence check or the CREATE fails
     */
    public static function createIfMissing(DatabaseInterface $db): bool
    {
        $row = $db->query(
            'SELECT COUNT(*) AS cnt FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::TABLE]
        )->first();
        if ($db->error() || !is_object($row)) {
            throw new RuntimeException('Could not check for ' . self::TABLE . ': ' . $db->errorString());
        }
        if ((int) $row->cnt > 0) {
            return false;
        }

        $db->query('CREATE TABLE ' . self::TABLE . ' ' . self::DEFINITION);
        if ($db->error()) {
            throw new RuntimeException('Could not create ' . self::TABLE . ': ' . $db->errorString());
        }

        return true;
    }

    /**
     * Make a TEMPORARY table of the same name on this connection
     *
     * A TEMPORARY table hides a real table of the same name, MySQL drops it at
     * disconnect, and no test row can get into a real table. Only the
     * connection that made it can see it, and `information_schema` does not
     * list it.
     *
     * @param DatabaseInterface $db Connection that the code under test also uses
     * @throws RuntimeException When the CREATE fails
     */
    public static function createTemporary(DatabaseInterface $db): void
    {
        $db->query('CREATE TEMPORARY TABLE ' . self::TABLE . ' ' . self::DEFINITION);
        if ($db->error()) {
            throw new RuntimeException('Could not create the temporary ' . self::TABLE . ' table: ' . $db->errorString());
        }
    }
}
