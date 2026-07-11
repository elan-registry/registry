<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DropCarUserTables extends AbstractMigration
{
    // The car_user junction table was a redundant mirror of cars.user_id. It
    // recorded car ownership as (userid, car_id) rows, but ownership is already
    // authoritative on cars.user_id. Because car_user had no FK constraint back
    // to cars or users, the two representations drifted over time — rows in
    // car_user pointed at cars whose cars.user_id said something different,
    // producing inconsistent owner data in reports and queries.
    //
    // Issue #1162 removes the junction entirely: every JOIN through car_user has
    // been rewritten to use cars.user_id directly. This migration drops the now-
    // unused car_user / car_user_hist tables and their audit triggers.
    //
    // up()   — drop the audit triggers, then car_user_hist and car_user
    // down() — recreate the tables and triggers from the original schema DDL for
    //          rollback safety
    //
    // up() + down() are used instead of change() because DROP TABLE / DROP
    // TRIGGER is not auto-reversible.
    public function up(): void
    {
        // Guard: verify ownership data integrity before dropping car_user.
        // These checks run before any DDL so a failure leaves the schema untouched
        // (DDL triggers an implicit commit in MySQL; guards must precede it).

        // 1. No car has a user_id referencing a non-existent user.
        $orphaned = $this->fetchRow(
            "SELECT COUNT(*) AS n FROM cars
             WHERE user_id IS NOT NULL
               AND user_id NOT IN (SELECT id FROM users)"
        );
        if ((int) ($orphaned['n'] ?? 0) > 0) {
            throw new \RuntimeException(
                "Cannot drop car_user: {$orphaned['n']} car(s) have user_id referencing a non-existent user. " .
                "Fix orphaned car ownership before running this migration."
            );
        }

        // 2. cars.user_id and car_user.userid agree for every car tracked in car_user.
        //    Detects any remaining drift between the two representations.
        $drifted = $this->fetchRow(
            "SELECT COUNT(*) AS n
             FROM car_user cu
             JOIN cars c ON cu.car_id = c.id
             WHERE c.user_id IS NULL OR c.user_id != cu.userid"
        );
        if ((int) ($drifted['n'] ?? 0) > 0) {
            throw new \RuntimeException(
                "Cannot drop car_user: {$drifted['n']} car(s) have drifted ownership " .
                "(cars.user_id ≠ car_user.userid). " .
                "Run the ownership reconciliation script before this migration."
            );
        }

        // Triggers must be dropped before their table.
        $this->execute("DROP TRIGGER IF EXISTS `car_user_delete`");
        $this->execute("DROP TRIGGER IF EXISTS `car_user_update`");
        $this->execute("DROP TRIGGER IF EXISTS `car_user_insert`");

        // Drop the history table before the main table.
        $this->execute("DROP TABLE IF EXISTS `car_user_hist`");
        $this->execute("DROP TABLE IF EXISTS `car_user`");
    }

    public function down(): void
    {
        // NOTE: down() recreates car_user and car_user_hist as empty tables — it is a
        // structural-only rollback. Any application code that reads from car_user will
        // see no cars for any owner until the data is restored. After reverting the
        // application code, run this backfill to restore data parity:
        //
        //   INSERT INTO car_user (userid, car_id)
        //     SELECT user_id, id FROM cars WHERE user_id IS NOT NULL;
        //
        // Never run this rollback on a live system without also reverting the
        // application code — car ownership queries will silently return empty sets.

        // Recreate car_user if absent.
        $result = $this->fetchAll(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'car_user'"
        );
        if ((int) ($result[0]['cnt'] ?? 0) === 0) {
            $this->execute(
                "CREATE TABLE `car_user` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `userid` int(11) NOT NULL,
                  `car_id` int(11) NOT NULL,
                  `mtime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_car_user_car_id` (`car_id`),
                  KEY `idx_car_user_userid` (`userid`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        // Recreate car_user_hist if absent.
        $result = $this->fetchAll(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'car_user_hist'"
        );
        if ((int) ($result[0]['cnt'] ?? 0) === 0) {
            $this->execute(
                "CREATE TABLE `car_user_hist` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `operation` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
                  `car_id` int(11) UNSIGNED NOT NULL,
                  `userid` int(11) DEFAULT NULL,
                  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_car_user_hist_car_id` (`car_id`),
                  KEY `idx_car_user_hist_userid` (`userid`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        // MySQL has no CREATE TRIGGER IF NOT EXISTS — drop-then-recreate is the
        // idempotent pattern.
        $this->execute("DROP TRIGGER IF EXISTS `car_user_insert`");
        $this->execute(
            "CREATE TRIGGER `car_user_insert` AFTER INSERT ON `car_user` FOR EACH ROW BEGIN
                INSERT INTO car_user_hist (operation, car_id, userid)
                VALUES ('INSERT', NEW.car_id, NEW.userid);
            END"
        );

        $this->execute("DROP TRIGGER IF EXISTS `car_user_update`");
        $this->execute(
            "CREATE TRIGGER `car_user_update` AFTER UPDATE ON `car_user` FOR EACH ROW BEGIN
                IF @disable_triggers IS NULL THEN
                    INSERT INTO car_user_hist (operation, car_id, userid)
                    VALUES ('UPDATE', OLD.car_id, OLD.userid);
                END IF;
            END"
        );

        $this->execute("DROP TRIGGER IF EXISTS `car_user_delete`");
        $this->execute(
            "CREATE TRIGGER `car_user_delete` AFTER DELETE ON `car_user` FOR EACH ROW BEGIN
                INSERT INTO car_user_hist (operation, car_id, userid)
                VALUES ('DELETE', OLD.car_id, OLD.userid);
            END"
        );
    }
}
