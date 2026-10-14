<?php

declare(strict_types=1);

use ElanRegistry\Migration\EmailEventRekeyQueries;
use Phinx\Migration\AbstractMigration;

/**
 * Issue #2358: key `er_email_events` by user, not by car.
 *
 * Before this migration, an owner with N cars had N rows for one event, and
 * a car delete or merge lost or moved the owner's events. After it, each
 * event is stored once for the user, and `car_id` is optional.
 *
 * up() runs three phases. MySQL commits each DDL statement implicitly, so no
 * phase is atomic with another. Each step is guarded, and a failed run can run
 * again:
 *
 * 1. DDL, guarded. Step (a): add `user_id INT NULL`.
 * 2. Data, in one transaction, only while step (e) is not done. Steps (b) to
 *    (d): set `user_id` and the normalized `email`, delete the rows with no
 *    user or with `noowner`, and collapse the duplicates.
 *    {@see EmailEventRekeyQueries} decides every write.
 * 3. DDL, guarded. Step (e): `user_id` NOT NULL and `car_id` NULL, in one
 *    statement. Step (f): add UNIQUE (user_id, brevo_message_id, event),
 *    INDEX (user_id, occurred_at) and INDEX (brevo_message_id), then drop
 *    UNIQUE (car_id, brevo_message_id, event). The `email` index and
 *    INDEX (car_id, occurred_at) stay.
 *
 * Phase 2 gives the same result when it runs again, so a re-run after a
 * failure in phase 3 changes no row. Before step (e) and before step (f), a
 * check stops the run with a count when a row would make the step fail.
 *
 * Run with the Brevo webhook and the cron jobs off. Output names event IDs
 * only, never an address.
 *
 * down() restores the schema only. It deletes the rows with `car_id` NULL,
 * makes `car_id` NOT NULL, restores UNIQUE (car_id, brevo_message_id, event)
 * and drops `user_id`. It does not restore the rows that up() deleted or
 * collapsed. The database backup is the only full rollback.
 */
final class KeyErEmailEventsByUser extends AbstractMigration
{
    private const TABLE = 'er_email_events';

    /** The default name that MySQL gave the old dedup key in 20260907141817. */
    private const OLD_UNIQUE_NAME = 'car_id';

    private const OLD_UNIQUE_COLUMNS = ['car_id', 'brevo_message_id', 'event'];

    private const NEW_UNIQUE_NAME = 'uniq_er_email_events_user_message_event';

    private const USER_OCCURRED_INDEX = 'idx_er_email_events_user_occurred';

    private const MESSAGE_ID_INDEX = 'idx_er_email_events_brevo_message_id';

    /** Upper limit of IDs in one IN() list. */
    private const ID_CHUNK = 1000;

    public function up(): void
    {
        if ($this->isDryRun()) {
            return;
        }

        $this->phaseOneAddUserId();

        if ($this->queries()->isRekeyed()) {
            $this->say('Phase 2 skipped: user_id is NOT NULL and car_id allows NULL, so the rows were keyed before.');
        } else {
            $this->phaseTwoRekeyRows();
        }

        $this->phaseThreeKeys();
    }

    public function down(): void
    {
        if ($this->isDryRun()) {
            return;
        }

        $this->execute('DELETE FROM er_email_events WHERE car_id IS NULL');

        // The new keys go before user_id. MySQL removes a dropped column from
        // a key, and the shorter UNIQUE key could then fail on duplicates.
        $table = $this->table(self::TABLE);
        foreach ([self::NEW_UNIQUE_NAME, self::USER_OCCURRED_INDEX, self::MESSAGE_ID_INDEX] as $name) {
            if ($table->hasIndexByName($name)) {
                $table->removeIndexByName($name);
            }
        }
        $table->update();

        if ($this->isNullable('car_id')) {
            $this->execute('ALTER TABLE er_email_events MODIFY car_id INT NOT NULL');
        }

        $table = $this->table(self::TABLE);
        if (!$table->hasIndex(self::OLD_UNIQUE_COLUMNS)) {
            $table->addIndex(self::OLD_UNIQUE_COLUMNS, ['unique' => true, 'name' => self::OLD_UNIQUE_NAME])->update();
        }

        $table = $this->table(self::TABLE);
        if ($table->hasColumn('user_id')) {
            $table->removeColumn('user_id')->update();
        }
    }

    /**
     * Phase 1, step (a): add `user_id INT NULL`.
     */
    private function phaseOneAddUserId(): void
    {
        $table = $this->table(self::TABLE);
        if ($table->hasColumn('user_id')) {
            $this->say('Phase 1 skipped: er_email_events.user_id exists.');

            return;
        }

        $table->addColumn('user_id', 'integer', ['null' => true, 'default' => null, 'after' => 'car_id'])->update();
        $this->say('Phase 1: added er_email_events.user_id.');
    }

    /**
     * Phase 2, steps (b) to (d), in one transaction.
     *
     * All decisions come from one read before the first write.
     */
    private function phaseTwoRekeyRows(): void
    {
        $queries = $this->queries();
        $assignments = $queries->assignments();
        $dropped = $queries->droppedIds();
        $groups = $queries->collapseGroups();

        $removed = [];
        foreach ($groups as $group) {
            foreach ($group['removed_ids'] as $id) {
                $removed[$id] = true;
            }
        }

        $adapter = $this->getAdapter();
        $adapter->beginTransaction();
        try {
            foreach ($assignments as $id => $assignment) {
                if (isset($removed[$id])) {
                    continue; // Step (d) deletes this row.
                }
                $this->execute(
                    'UPDATE er_email_events SET user_id = ?, email = ? WHERE id = ?',
                    [$assignment['user_id'], $assignment['email'], $id]
                );
            }

            $this->deleteIds($dropped);
            $this->deleteIds(array_keys($removed));

            foreach ($groups as $group) {
                $this->execute(
                    'UPDATE er_email_events SET occurred_at = ? WHERE id = ?',
                    [$group['occurred_at'], $group['kept_id']]
                );
            }

            $adapter->commitTransaction();
        } catch (\Throwable $e) {
            $adapter->rollbackTransaction();
            throw $e;
        }

        $this->say('Phase 2: matched by car owner, event ID(s): ' . self::idList($queries->ownerMatchedIds()));
        $this->say('Phase 2: matched by address, event ID(s): ' . self::idList($queries->addressMatchedIds()));
        $this->say('Phase 2: dropped (no user or noowner), event ID(s): ' . self::idList($dropped));
        $this->say('Phase 2: collapsed, ' . self::groupList($groups));
    }

    /**
     * Phase 3: step (e), then step (f).
     */
    private function phaseThreeKeys(): void
    {
        if (!$this->isNullable('user_id') && $this->isNullable('car_id')) {
            $this->say('Phase 3: step (e) skipped: user_id is NOT NULL and car_id allows NULL.');
        } else {
            $this->assertNoNullUserId();
            // One statement, so no state exists where both columns allow NULL
            // or neither does after a failure.
            $this->execute('ALTER TABLE er_email_events MODIFY user_id INT NOT NULL, MODIFY car_id INT NULL');
            $this->say('Phase 3: user_id is NOT NULL and car_id allows NULL.');
        }

        // The new keys come first, so the table never has no dedup key.
        $table = $this->table(self::TABLE);
        $changed = false;
        if (!$table->hasIndexByName(self::NEW_UNIQUE_NAME)) {
            $this->assertNoDuplicateUserKey();
            $table->addIndex(['user_id', 'brevo_message_id', 'event'], ['unique' => true, 'name' => self::NEW_UNIQUE_NAME]);
            $changed = true;
        }
        if (!$table->hasIndexByName(self::USER_OCCURRED_INDEX)) {
            $table->addIndex(['user_id', 'occurred_at'], ['name' => self::USER_OCCURRED_INDEX]);
            $changed = true;
        }
        if (!$table->hasIndexByName(self::MESSAGE_ID_INDEX)) {
            $table->addIndex(['brevo_message_id'], ['name' => self::MESSAGE_ID_INDEX]);
            $changed = true;
        }
        if ($changed) {
            $table->update();
        }

        $table = $this->table(self::TABLE);
        if ($table->hasIndex(self::OLD_UNIQUE_COLUMNS)) {
            $table->removeIndex(self::OLD_UNIQUE_COLUMNS)->update();
        }
        $this->say('Phase 3: keys by user are in place.');
    }

    /**
     * Stop before step (e) when a row still has no user.
     *
     * MODIFY ... NOT NULL in non-strict mode would write 0 into each such
     * row. In strict mode it fails with a message that names no row. This
     * check gives the count first.
     *
     * @throws RuntimeException If a row has `user_id` NULL
     */
    private function assertNoNullUserId(): void
    {
        $row = $this->fetchRow('SELECT COUNT(*) AS cnt FROM er_email_events WHERE user_id IS NULL');
        $count = is_array($row) ? (int) $row['cnt'] : 0;
        if ($count > 0) {
            throw new RuntimeException(sprintf(
                'Phase 3 stopped before step (e): %d er_email_events row(s) have user_id NULL. Phase 2 must set every user_id.',
                $count
            ));
        }
    }

    /**
     * Stop before step (f) when rows would break the new UNIQUE key.
     *
     * The ALTER would fail with a message that holds the duplicate values,
     * and a message ID can identify an owner. This check names event IDs only.
     *
     * @throws RuntimeException If two rows share user_id, brevo_message_id and event
     */
    private function assertNoDuplicateUserKey(): void
    {
        $rows = $this->fetchAll(
            'SELECT MIN(id) AS kept_id
               FROM er_email_events
              GROUP BY user_id, brevo_message_id, event
             HAVING COUNT(*) > 1
              ORDER BY kept_id'
        );
        if ($rows !== []) {
            $keptIds = array_map(static fn (array $row): int => (int) $row['kept_id'], array_slice($rows, 0, 5));
            throw new RuntimeException(sprintf(
                'Phase 3 stopped before step (f): %d group(s) of er_email_events rows share user_id, brevo_message_id'
                . ' and event. Kept (lowest) event ID of the first groups: %s.',
                count($rows),
                self::idList($keptIds)
            ));
        }
    }

    /**
     * Delete event rows by ID, in chunks.
     *
     * @param list<int> $ids
     */
    private function deleteIds(array $ids): void
    {
        foreach (array_chunk($ids, self::ID_CHUNK) as $chunk) {
            $this->execute(
                'DELETE FROM er_email_events WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                $chunk
            );
        }
    }

    /**
     * Tell whether a column of `er_email_events` allows NULL. A missing column reads as false.
     */
    private function isNullable(string $column): bool
    {
        $nullable = $this->table(self::TABLE)->getColumn($column)?->isNull();

        return $nullable === true;
    }

    /**
     * Tell whether Phinx runs with --dry-run, and say so when it does.
     *
     * In a dry run, PdoAdapter::execute() runs nothing, but query() and
     * fetchAll() still run. Phase 2 would then read a column that the dry run
     * did not add.
     *
     * @return bool True when this is a dry run
     */
    private function isDryRun(): bool
    {
        $input = $this->getInput();
        if ($input === null || !$input->hasOption('dry-run') || !(bool) $input->getOption('dry-run')) {
            return false;
        }

        $this->say('Dry run: this migration has no dry-run output. Run 29-Report-Email-Event-Rekey.php for the counts.');

        return true;
    }

    /**
     * A fresh reader on this migration's connection, so it sees the writes so far.
     */
    private function queries(): EmailEventRekeyQueries
    {
        return new EmailEventRekeyQueries($this->getAdapter()->getConnection());
    }

    private function say(string $message): void
    {
        if (isset($this->output)) {
            $this->output->writeln('<info>' . $message . '</info>');
        }
    }

    /**
     * @param array<int|string> $ids
     */
    private static function idList(array $ids): string
    {
        return $ids === [] ? 'none' : implode(', ', array_map('intval', $ids));
    }

    /**
     * @param list<array{kept_id: int, removed_ids: list<int>, occurred_at: string}> $groups
     */
    private static function groupList(array $groups): string
    {
        if ($groups === []) {
            return 'none';
        }

        return implode('; ', array_map(
            static fn (array $group): string => sprintf(
                'kept %d, removed %s',
                $group['kept_id'],
                self::idList($group['removed_ids'])
            ),
            $groups
        ));
    }
}
