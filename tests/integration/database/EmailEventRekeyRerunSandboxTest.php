<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailEventRekeySandboxTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Re-run of migration 20261013000000 after a failure at each phase (#2358).
 *
 * MySQL commits each DDL statement, so a failure leaves the schema between two
 * steps. Each test stands the sandbox in such a state, runs up() and expects the
 * result of one clean run. The migration deletes rows, so each test seeds the
 * fixture again first.
 */
#[Group('integration')]
#[Group('migration')]
final class EmailEventRekeyRerunSandboxTest extends EmailEventRekeySandboxTestCase
{
    public function testRerunAfterAFailureAfterPhaseOne(): void
    {
        $this->resetFixture();
        $this->callPrivate($this->migration(self::MIGRATION), 'phaseOneAddUserId');
        $this->assertSame(20, count($this->eventRows()), 'Phase 1 changes no row');

        $output = $this->upWithOutput();

        $this->assertStringContainsString('Phase 1 skipped', $output);
        $this->assertFinishedLikeACleanRun();
    }

    public function testRerunAfterAFailureAfterPhaseTwoWritesTheSameRows(): void
    {
        $this->resetFixture();
        $migration = $this->migration(self::MIGRATION);
        $this->callPrivate($migration, 'phaseOneAddUserId');
        $this->callPrivate($migration, 'phaseTwoRekeyRows');
        $afterPhaseTwo = $this->eventRows();
        $this->assertSame(\EmailEventRekeyMigrationFixture::KEPT_IDS, array_keys($afterPhaseTwo));

        $output = $this->upWithOutput();

        $this->assertStringNotContainsString('Phase 2 skipped', $output, 'Step (e) is not done, so phase 2 runs again');
        $this->assertStringContainsString('Phase 2: dropped (no user or noowner), event ID(s): none', $output);
        $this->assertSame($afterPhaseTwo, $this->eventRows(), 'The second phase 2 must change no row');
        $this->assertFinishedLikeACleanRun();
    }

    public function testRerunAfterAFailureAfterStepEKeepsARowWithCarIdNull(): void
    {
        $this->resetFixture();
        $migration = $this->migration(self::MIGRATION);
        $this->callPrivate($migration, 'phaseOneAddUserId');
        $this->callPrivate($migration, 'phaseTwoRekeyRows');
        $this->pdo()->exec('ALTER TABLE er_email_events MODIFY user_id INT NOT NULL, MODIFY car_id INT NULL');
        // A webhook row for an owner. Its address is on no users row, so phase 2 would
        // drop it as a row with no user, if phase 2 ran again.
        $this->pdo()->exec(
            "INSERT INTO er_email_events (id, user_id, car_id, email, event, brevo_message_id, occurred_at)
             VALUES (100, 201, NULL, 'no-such-user@example.test', 'delivered', 'msg-owner-level', '2026-04-01 00:00:00')"
        );

        $output = $this->upWithOutput();

        $this->assertStringContainsString('Phase 2 skipped', $output);
        $this->assertContains(100, $this->eventIds(), 'Phase 2 must not run on a table that is keyed by user');
        $this->assertNull($this->eventRows()[100]['car_id']);
        $this->assertNotNull($this->uniqueIndexOn(['user_id', 'brevo_message_id', 'event']));
        $this->assertNull($this->uniqueIndexOn(['car_id', 'brevo_message_id', 'event']));
    }

    public function testRerunWithTheOldKeyDroppedAndTheNewKeyMissing(): void
    {
        $this->resetFixture();
        $migration = $this->migration(self::MIGRATION);
        $this->callPrivate($migration, 'phaseOneAddUserId');
        $this->callPrivate($migration, 'phaseTwoRekeyRows');
        $this->pdo()->exec('ALTER TABLE er_email_events MODIFY user_id INT NOT NULL, MODIFY car_id INT NULL');
        $this->pdo()->exec('ALTER TABLE er_email_events DROP INDEX car_id');
        $this->assertNull($this->uniqueIndexOn(['car_id', 'brevo_message_id', 'event']), 'Test setup: the old key is gone');
        $this->assertNull($this->uniqueIndexOn(['user_id', 'brevo_message_id', 'event']), 'Test setup: the new key is missing');

        $this->upWithOutput();

        $this->assertNotNull($this->uniqueIndexOn(['user_id', 'brevo_message_id', 'event']));
        $this->assertTrue($this->hasIndexOn(['user_id', 'occurred_at']));
        $this->assertTrue($this->hasIndexOn(['brevo_message_id']));
        $this->assertSame(\EmailEventRekeyMigrationFixture::KEPT_IDS, $this->eventIds());
    }

    public function testRerunWhenFullyMigratedChangesNothing(): void
    {
        $this->resetFixture();
        $this->upWithOutput();
        $rows = $this->eventRows();
        $indexes = $this->indexes();

        $output = $this->upWithOutput();

        $this->assertStringContainsString('Phase 1 skipped', $output);
        $this->assertStringContainsString('Phase 2 skipped', $output);
        $this->assertStringContainsString('Phase 3: step (e) skipped', $output);
        $this->assertSame($rows, $this->eventRows());
        $this->assertSame($indexes, $this->indexes());
    }

    public function testStepEStopsWithACountWhenARowHasNoUser(): void
    {
        $this->resetFixture();
        $migration = $this->migration(self::MIGRATION);
        $this->callPrivate($migration, 'phaseOneAddUserId');
        $this->callPrivate($migration, 'phaseTwoRekeyRows');
        // A row that phase 2 did not key, as an event written during the run could leave.
        $this->pdo()->exec('UPDATE er_email_events SET user_id = NULL WHERE id IN (4, 7)');

        try {
            $this->callPrivate($migration, 'phaseThreeKeys');
            $this->fail('Step (e) must not run while a row has user_id NULL');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'Phase 3 stopped before step (e): 2 er_email_events row(s) have user_id NULL. Phase 2 must set every user_id.',
                $e->getMessage()
            );
        }

        $user = $this->columnInfo('er_email_events', 'user_id');
        $this->assertNotNull($user);
        $this->assertSame('YES', $user['IS_NULLABLE'], 'The guard runs before the ALTER');
    }

    public function testStepFStopsWithTheKeptIdsWhenRowsWouldBreakTheNewUniqueKey(): void
    {
        $this->resetFixture();
        $migration = $this->migration(self::MIGRATION);
        $this->callPrivate($migration, 'phaseOneAddUserId');
        $this->callPrivate($migration, 'phaseTwoRekeyRows');
        $this->pdo()->exec('ALTER TABLE er_email_events MODIFY user_id INT NOT NULL, MODIFY car_id INT NULL');
        // Two cars, so the old key (car_id, brevo_message_id, event) lets both rows in.
        $this->pdo()->exec(
            "INSERT INTO er_email_events (id, user_id, car_id, email, event, brevo_message_id, occurred_at)
             VALUES (101, 207, 2070, 'jose@example.test', 'delivered', 'msg-dup', '2026-04-01 00:00:00'),
                    (102, 207, 2071, 'jose@example.test', 'delivered', 'msg-dup', '2026-04-01 00:00:01')"
        );

        try {
            $this->callPrivate($migration, 'phaseThreeKeys');
            $this->fail('Step (f) must not run while two rows share the new key');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'Phase 3 stopped before step (f): 1 group(s) of er_email_events rows share user_id, brevo_message_id'
                . ' and event. Kept (lowest) event ID of the first groups: 101.',
                $e->getMessage()
            );
            $this->assertStringNotContainsString('@', $e->getMessage());
        }

        $this->assertNull($this->uniqueIndexOn(['user_id', 'brevo_message_id', 'event']));
        $this->assertNotNull($this->uniqueIndexOn(['car_id', 'brevo_message_id', 'event']), 'The old key stays');
    }

    /**
     * Phase 2 runs in one transaction. A failure after its first writes must
     * leave every row as the fixture wrote it.
     */
    public function testAFailureInPhaseTwoRollsBackEveryWriteOfThePhase(): void
    {
        $this->resetFixture();
        $migration = $this->migration(self::MIGRATION);
        $this->callPrivate($migration, 'phaseOneAddUserId');
        $before = $this->eventRows();
        foreach ($before as $row) {
            $this->assertNull($row['user_id'], 'Test setup: phase 1 leaves user_id NULL');
        }
        // The UPDATEs of step (b) run first. The first DELETE of step (c) then fails.
        $this->pdo()->exec(
            "CREATE TRIGGER test_rekey_block_delete BEFORE DELETE ON er_email_events
             FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'delete blocked by test'"
        );
        try {
            try {
                $this->callPrivate($migration, 'phaseTwoRekeyRows');
                $this->fail('The blocked DELETE must fail phase 2');
            } catch (\PDOException $e) {
                $this->assertStringContainsString('delete blocked by test', $e->getMessage());
            }
        } finally {
            $this->pdo()->exec('DROP TRIGGER IF EXISTS test_rekey_block_delete');
        }

        $this->assertSame($before, $this->eventRows(), 'The rollback must undo the UPDATEs of step (b)');

        $this->upWithOutput();

        $this->assertFinishedLikeACleanRun();
    }

    /**
     * The rows and keys that one clean run gives.
     */
    private function assertFinishedLikeACleanRun(): void
    {
        $this->assertSame(\EmailEventRekeyMigrationFixture::KEPT_IDS, $this->eventIds());
        $users = [];
        foreach ($this->eventRows() as $id => $row) {
            $users[$id] = (int) $row['user_id'];
        }
        $this->assertSame(\EmailEventRekeyMigrationFixture::USER_AFTER, $users);
        $this->assertSame('2026-03-01 09:00:00', $this->eventRows()[1]['occurred_at']);

        $user = $this->columnInfo('er_email_events', 'user_id');
        $car = $this->columnInfo('er_email_events', 'car_id');
        $this->assertNotNull($user);
        $this->assertNotNull($car);
        $this->assertSame('NO', $user['IS_NULLABLE']);
        $this->assertSame('YES', $car['IS_NULLABLE']);
        $this->assertNotNull($this->uniqueIndexOn(['user_id', 'brevo_message_id', 'event']));
        $this->assertNull($this->uniqueIndexOn(['car_id', 'brevo_message_id', 'event']));
        $this->assertTrue($this->hasIndexOn(['user_id', 'occurred_at']));
        $this->assertTrue($this->hasIndexOn(['brevo_message_id']));
    }
}
