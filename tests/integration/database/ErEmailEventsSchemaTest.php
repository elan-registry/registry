<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Schema-assertion tests for the `er_email_events` table created by issue
 * #1887's migration `20260907141817_create_er_email_events_table`.
 *
 * `brevo_message_id NOT NULL DEFAULT ''` is correctness-critical, not a style
 * choice — MySQL treats every NULL as distinct in a unique index, so a
 * nullable column would silently defeat `UNIQUE (car_id, brevo_message_id,
 * event)` for exactly the rows that need dedup most (a locally-written 'sent'
 * row, or any inbound payload missing the field). This is asserted directly
 * against information_schema rather than just trusted from the migration
 * source.
 */
#[Group('integration')]
final class ErEmailEventsSchemaTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $tableExists = $this->db->query(
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'er_email_events'"
        )->first();

        if (!$tableExists) {
            $this->markTestSkipped('er_email_events table not yet available — run `composer migrate`');
        }
    }

    #[Group('fast')]
    public function testBrevoMessageIdIsNotNullDefaultEmptyString(): void
    {
        $column = $this->db->query(
            "SELECT IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'er_email_events' AND COLUMN_NAME = 'brevo_message_id'"
        )->first();

        $this->assertIsObject($column, 'er_email_events.brevo_message_id must exist');
        $this->assertSame('NO', $column->IS_NULLABLE, 'er_email_events.brevo_message_id must be NOT NULL');
        $this->assertSame(
            '',
            (string) $column->COLUMN_DEFAULT,
            'er_email_events.brevo_message_id must DEFAULT to an empty string, not NULL — '
                . 'a nullable/NULL-default column would silently defeat the unique index '
                . 'for exactly the rows that need dedup most'
        );
    }

    #[Group('fast')]
    public function testUniqueIndexOnCarIdBrevoMessageIdEventExistsExactly(): void
    {
        $rows = $this->db->query(
            "SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'er_email_events'
             ORDER BY INDEX_NAME, SEQ_IN_INDEX"
        )->results();

        $byIndex = [];
        foreach ($rows as $row) {
            $byIndex[$row->INDEX_NAME][(int) $row->SEQ_IN_INDEX] = [
                'column'     => $row->COLUMN_NAME,
                'non_unique' => (int) $row->NON_UNIQUE,
            ];
        }

        $matchingUniqueIndex = null;
        foreach ($byIndex as $indexName => $columns) {
            ksort($columns);
            $columnNames = array_values(array_map(fn ($c) => $c['column'], $columns));
            $allUnique = array_reduce($columns, fn ($carry, $c) => $carry && $c['non_unique'] === 0, true);

            if ($allUnique && $columnNames === ['car_id', 'brevo_message_id', 'event']) {
                $matchingUniqueIndex = $indexName;
                break;
            }
        }

        $this->assertNotNull(
            $matchingUniqueIndex,
            'er_email_events must have a UNIQUE index on exactly (car_id, brevo_message_id, event) in that '
                . 'column order — found indexes: ' . json_encode($byIndex)
        );
    }

    #[Group('fast')]
    public function testSecondaryIndexesExist(): void
    {
        $rows = $this->db->query(
            "SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'er_email_events'
             ORDER BY INDEX_NAME, SEQ_IN_INDEX"
        )->results();

        $byIndex = [];
        foreach ($rows as $row) {
            $byIndex[$row->INDEX_NAME][(int) $row->SEQ_IN_INDEX] = $row->COLUMN_NAME;
        }

        $indexColumnSets = array_map(function ($columns) {
            ksort($columns);
            return array_values($columns);
        }, $byIndex);

        $this->assertContains(
            ['car_id', 'occurred_at'],
            $indexColumnSets,
            'er_email_events must have an index on (car_id, occurred_at) — found: ' . json_encode($indexColumnSets)
        );
        $this->assertContains(
            ['email'],
            $indexColumnSets,
            'er_email_events must have an index on (email) — found: ' . json_encode($indexColumnSets)
        );
    }

    #[Group('fast')]
    public function testCarIdHasNoForeignKeyConstraint(): void
    {
        // This codebase has no FK constraints on car-adjacent tables (cars.user_id's
        // own FK was deliberately dropped — 20260719120000_drop_cars_user_id_fk.php).
        // er_email_events.car_id follows the same convention deliberately.
        $fkRows = $this->db->query(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'er_email_events'
               AND COLUMN_NAME = 'car_id' AND REFERENCED_TABLE_NAME IS NOT NULL"
        )->results();

        $this->assertCount(
            0,
            $fkRows,
            'er_email_events.car_id must have no FK constraint — this table follows the '
                . 'no-FK convention shared by cars-adjacent tables in this codebase'
        );
    }
}
