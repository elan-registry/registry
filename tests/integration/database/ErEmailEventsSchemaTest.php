<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * #1887, #2358: er_email_events schema.
 *
 * brevo_message_id must be NOT NULL: MySQL treats each NULL as distinct in a
 * unique index, so a nullable column would defeat the dedup key. Since #2358
 * the table is keyed by user: `user_id` is NOT NULL, `car_id` allows NULL, and
 * the dedup key is (user_id, brevo_message_id, event).
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
    public function testUniqueIndexOnUserIdBrevoMessageIdEventExistsExactly(): void
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

            if ($allUnique && $columnNames === ['user_id', 'brevo_message_id', 'event']) {
                $matchingUniqueIndex = $indexName;
                break;
            }
        }

        $this->assertNotNull(
            $matchingUniqueIndex,
            'er_email_events must have a UNIQUE index on exactly (user_id, brevo_message_id, event) in that '
                . 'column order — found indexes: ' . json_encode($byIndex)
        );
    }

    #[Group('fast')]
    public function testNoUniqueIndexKeepsTheOldCarKey(): void
    {
        $rows = $this->db->query(
            "SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'er_email_events' AND NON_UNIQUE = 0
             ORDER BY INDEX_NAME, SEQ_IN_INDEX"
        )->results();

        $byIndex = [];
        foreach ($rows as $row) {
            $byIndex[$row->INDEX_NAME][(int) $row->SEQ_IN_INDEX] = $row->COLUMN_NAME;
        }
        foreach ($byIndex as $indexName => $columns) {
            ksort($columns);
            $this->assertNotSame(
                ['car_id', 'brevo_message_id', 'event'],
                array_values($columns),
                "Index {$indexName} is the old per-car key. Two cars of one owner could not share a message ID if it stayed."
            );
        }
    }

    #[Group('fast')]
    public function testUserIdIsNotNullAndCarIdAllowsNull(): void
    {
        $columns = [];
        foreach ($this->db->query(
            "SELECT COLUMN_NAME, IS_NULLABLE, DATA_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'er_email_events'
               AND COLUMN_NAME IN ('user_id', 'car_id')"
        )->results() as $row) {
            $columns[$row->COLUMN_NAME] = $row;
        }

        $this->assertArrayHasKey('user_id', $columns, 'er_email_events.user_id must exist');
        $this->assertSame('NO', $columns['user_id']->IS_NULLABLE, 'user_id must be NOT NULL');
        $this->assertSame('int', $columns['user_id']->DATA_TYPE);
        $this->assertArrayHasKey('car_id', $columns);
        $this->assertSame('YES', $columns['car_id']->IS_NULLABLE, 'car_id must allow NULL: a car delete sets it to NULL');
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
        $this->assertContains(
            ['user_id', 'occurred_at'],
            $indexColumnSets,
            'er_email_events must have an index on (user_id, occurred_at) — found: ' . json_encode($indexColumnSets)
        );
        $this->assertContains(
            ['brevo_message_id'],
            $indexColumnSets,
            'er_email_events must have an index on (brevo_message_id) — found: ' . json_encode($indexColumnSets)
        );
    }

    #[Group('fast')]
    public function testUserIdAndCarIdHaveNoForeignKeyConstraint(): void
    {
        // Convention: no FKs on car-adjacent tables (20260719120000_drop_cars_user_id_fk.php).
        $fkRows = $this->db->query(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'er_email_events'
               AND COLUMN_NAME IN ('car_id', 'user_id') AND REFERENCED_TABLE_NAME IS NOT NULL"
        )->results();

        $this->assertCount(
            0,
            $fkRows,
            'er_email_events.car_id and user_id must have no FK constraint — this table follows the '
                . 'no-FK convention shared by cars-adjacent tables in this codebase'
        );
    }
}
