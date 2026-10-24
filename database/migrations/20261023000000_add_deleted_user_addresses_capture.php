<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Issue #2419: keep the addresses of a deleted `users` row for the
 * account-deletion privacy step.
 *
 * Upstream `deleteUsers()` deletes the `users` row before it includes
 * `usersc/scripts/after_user_deletion.php`. So the privacy step cannot read
 * `users.email` or `users.email_new`. The trigger `users_delete_capture_addresses`
 * copies the two addresses of each deleted row into `er_deleted_user_addresses`.
 * It does this for every caller of a delete on `users`.
 *
 * The privacy step reads the row through
 * `CarRepository::lockProfileAndFindAddresses()` and deletes it in the same
 * transaction. A row stays only when the step did not commit. The rerun
 * script (`30-Rerun-Deletion-Privacy-Step.php`) then has the addresses.
 *
 * Column types and collation match `users.id` (signed INT), `users.email`
 * (VARCHAR(155) utf8mb4_unicode_ci) and `users.email_new`. `email_new` is
 * NULL when no email change was pending. `users.email` is NOT NULL, but
 * `email` is nullable here too, so that an odd source row can never make the
 * trigger insert, and with it the users delete, fail.
 *
 * The trigger is AFTER DELETE, so it records only a row that MySQL removed.
 * It ignores `@disable_triggers`: a deletion must never skip the capture.
 * `ON DUPLICATE KEY UPDATE` replaces an earlier holding row for the same ID.
 *
 * NOT ATOMIC: MySQL commits each DDL statement implicitly. A failed run can
 * run again: the table step is guarded with `hasTable()`, and the trigger step
 * uses `DROP TRIGGER IF EXISTS` then `CREATE TRIGGER`.
 * {@see assertTriggerPresent()} makes a missing trigger fail the migration.
 *
 * down() drops the trigger, then the table. A holding row that is still in the
 * table is lost.
 */
final class AddDeletedUserAddressesCapture extends AbstractMigration
{
    private const TABLE = 'er_deleted_user_addresses';

    private const TRIGGER = 'users_delete_capture_addresses';

    public function up(): void
    {
        if (!$this->hasTable(self::TABLE)) {
            $this->table(self::TABLE, [
                'id' => false,
                'primary_key' => ['user_id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci',
            ])
                ->addColumn('user_id', 'integer', ['null' => false])
                ->addColumn('email', 'string', ['limit' => 155, 'null' => true, 'default' => null])
                ->addColumn('email_new', 'string', ['limit' => 155, 'null' => true, 'default' => null])
                ->addColumn('deleted_at', 'datetime', ['null' => false])
                ->create();
        }

        $this->createTrigger();

        // A dry run prints the SQL and runs none of it, so the trigger cannot exist.
        if (!$this->isDryRun()) {
            $this->assertTriggerPresent();
        }
    }

    public function down(): void
    {
        $this->execute('DROP TRIGGER IF EXISTS `' . self::TRIGGER . '`');

        if ($this->hasTable(self::TABLE)) {
            $this->table(self::TABLE)->drop()->save();
        }
    }

    /**
     * Drop and create the capture trigger on `users`.
     */
    private function createTrigger(): void
    {
        $trustFunctionCreatorsSet = $this->enableTrustFunctionCreators();

        // A thrown CREATE TRIGGER must not leave log_bin_trust_function_creators
        // relaxed server-wide.
        try {
            $this->execute('DROP TRIGGER IF EXISTS `' . self::TRIGGER . '`');
            // No @disable_triggers check, unlike the cars_update audit trigger: a deletion
            // must never skip the capture, or the privacy step loses the addresses.
            $this->execute(
                'CREATE TRIGGER `' . self::TRIGGER . '` AFTER DELETE ON `users` FOR EACH ROW BEGIN
                     INSERT INTO `' . self::TABLE . '` (user_id, email, email_new, deleted_at)
                     VALUES (OLD.id, OLD.email, OLD.email_new, NOW())
                     ON DUPLICATE KEY UPDATE
                         email = OLD.email,
                         email_new = OLD.email_new,
                         deleted_at = NOW();
                 END'
            );
        } finally {
            $this->resetTrustFunctionCreators($trustFunctionCreatorsSet);
        }
    }

    /**
     * Make sure the capture trigger exists after the create.
     *
     * {@see enableTrustFunctionCreators()} continues when it cannot set the
     * variable. Without this check, a missing trigger would be found only when
     * a deletion keeps personal data.
     *
     * @throws \RuntimeException If the trigger is not present on `users`
     */
    private function assertTriggerPresent(): void
    {
        $rows = $this->fetchAll(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS '
            . "WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'users' "
            . "AND TRIGGER_NAME = '" . self::TRIGGER . "'"
        );

        if ($rows === []) {
            throw new \RuntimeException(
                'Trigger ' . self::TRIGGER . ' is not present on `users` after the create. '
                . 'A deleted user\'s addresses are not captured, so the account-deletion privacy step '
                . 'cannot delete plugin mail rows for users.email or users.email_new. Give the migration '
                . 'user the TRIGGER privilege. If binary logging is on, also set '
                . 'log_bin_trust_function_creators=1 or give the user SUPER or SYSTEM_VARIABLES_ADMIN. '
                . 'Then run `composer migrate` again.'
            );
        }
    }

    /**
     * Tell whether Phinx runs with `--dry-run`.
     */
    private function isDryRun(): bool
    {
        $input = $this->getInput();

        return $input !== null && $input->hasOption('dry-run') && (bool) $input->getOption('dry-run');
    }

    /**
     * CREATE TRIGGER needs SUPER or log_bin_trust_function_creators=1 when
     * binary logging is on. Try to set it globally. If the migration user
     * cannot, continue: the variable can already be set (common on managed
     * hosts). Copied from 20260905172137 so this migration does not depend on
     * another migration class.
     *
     * @return bool True if this call set the variable (and must reset it).
     */
    private function enableTrustFunctionCreators(): bool
    {
        try {
            $this->execute('SET GLOBAL log_bin_trust_function_creators = 1');
            return true;
        } catch (\RuntimeException $e) {
            if (isset($this->output)) {
                $this->output->writeln(
                    '<comment>Warning: Could not SET GLOBAL log_bin_trust_function_creators=1 '
                    . '— continuing. If CREATE TRIGGER fails below, set this variable in my.cnf.</comment>'
                );
            }
            return false;
        }
    }

    /**
     * Reset log_bin_trust_function_creators if this run set it.
     *
     * @throws \RuntimeException If the reset fails. The flag is then relaxed
     *                           server-wide, so the deploy must fail visibly.
     */
    private function resetTrustFunctionCreators(bool $wasSet): void
    {
        if (!$wasSet) {
            return;
        }
        try {
            $this->execute('SET GLOBAL log_bin_trust_function_creators = 0');
        } catch (\RuntimeException $e) {
            if (isset($this->output)) {
                $this->output->writeln(
                    '<error>Could not reset log_bin_trust_function_creators=0: '
                    . $e->getMessage()
                    . ' — log_bin_trust_function_creators is left relaxed (=1) server-wide. '
                    . 'Reset it manually in MySQL or my.cnf.</error>'
                );
            }
            throw $e;
        }
    }
}
