<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailEventRekeySandboxTestCase.php';

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * up(), down() and up() of migration 20261013000000 on the fixture (#2358).
 *
 * down() restores the schema only. It does not restore the rows that up()
 * deleted or collapsed, and it deletes the rows with `car_id` NULL.
 */
#[Group('integration')]
#[Group('migration')]
final class EmailEventRekeyDownSandboxTest extends EmailEventRekeySandboxTestCase
{
    /** @var array<string, array{unique: bool, columns: list<string>}> */
    private static array $indexesBefore = [];

    /** @var array<int, array<string, mixed>> */
    private static array $rowsAfterFirstUp = [];

    public function testUpFromTheFixtureWithAnOwnerLevelRow(): void
    {
        self::$indexesBefore = $this->indexes();
        $this->assertNull($this->columnInfo('er_email_events', 'user_id'));

        $this->upWithOutput();
        // The webhook writes this kind of row after the migration: user_id set, car_id NULL.
        $this->pdo()->exec(
            "INSERT INTO er_email_events (id, user_id, car_id, email, event, brevo_message_id, occurred_at)
             VALUES (100, 201, NULL, 'three.cars@example.test', 'delivered', 'msg-owner-level', '2026-04-01 00:00:00')"
        );

        $this->assertSame(array_merge(\EmailEventRekeyMigrationFixture::KEPT_IDS, [100]), $this->eventIds());
    }

    #[Depends('testUpFromTheFixtureWithAnOwnerLevelRow')]
    public function testDownRestoresTheOldKeysAndColumns(): void
    {
        $this->runDown(self::MIGRATION);

        $this->assertNull($this->columnInfo('er_email_events', 'user_id'), 'user_id must be gone');
        $car = $this->columnInfo('er_email_events', 'car_id');
        $this->assertNotNull($car);
        $this->assertSame('NO', $car['IS_NULLABLE'], 'car_id must be NOT NULL again');
        $this->assertSame(self::$indexesBefore, $this->indexes(), 'The table must have its old keys, with the old names');
        $this->assertNotNull($this->uniqueIndexOn(['car_id', 'brevo_message_id', 'event']));
    }

    #[Depends('testDownRestoresTheOldKeysAndColumns')]
    public function testDownDeletesTheRowsWithCarIdNullAndKeepsTheOthers(): void
    {
        $this->assertSame(\EmailEventRekeyMigrationFixture::KEPT_IDS, $this->eventIds());
        self::$rowsAfterFirstUp = $this->eventRows();
        foreach (self::$rowsAfterFirstUp as $row) {
            $this->assertArrayNotHasKey('user_id', $row);
            $this->assertNotNull($row['car_id']);
        }
    }

    #[Depends('testDownDeletesTheRowsWithCarIdNullAndKeepsTheOthers')]
    public function testDownDoesNotBringBackTheRowsThatUpDeleted(): void
    {
        foreach (array_merge(
            \EmailEventRekeyMigrationFixture::DROPPED_IDS,
            [2, 3, 14]
        ) as $id) {
            $this->assertArrayNotHasKey($id, self::$rowsAfterFirstUp, "Event {$id} was deleted by up() and stays deleted");
        }
    }

    #[Depends('testDownDeletesTheRowsWithCarIdNullAndKeepsTheOthers')]
    public function testUpAgainGivesTheSameRowsForTheOwnerWithThreeCars(): void
    {
        $this->upWithOutput();

        $this->assertSame(\EmailEventRekeyMigrationFixture::KEPT_IDS, $this->eventIds());
        $rows = $this->eventRows();
        $this->assertSame(201, (int) $rows[1]['user_id']);
        $this->assertSame('2026-03-01 09:00:00', $rows[1]['occurred_at']);
        $this->assertNotNull($this->uniqueIndexOn(['user_id', 'brevo_message_id', 'event']));
        $this->assertNull($this->uniqueIndexOn(['car_id', 'brevo_message_id', 'event']));
    }
}
