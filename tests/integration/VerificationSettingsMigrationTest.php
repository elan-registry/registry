<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies migration 20260907120000_add_er_verification_settings.php actually
 * created `er_verification_settings` with the expected schema and seeded the
 * default row (#1926).
 *
 * This checks the real, already-applied state of the integration test
 * database rather than running the migration itself — phinx migrations are
 * applied out-of-band via `composer migrate` / `vendor/bin/phinx migrate`
 * against the dedicated test schema, same as every other table this suite
 * relies on.
 */
#[Group('integration')]
final class VerificationSettingsMigrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    public function testTableExists(): void
    {
        $result = $this->db->query("SHOW TABLES LIKE 'er_verification_settings'");
        $this->assertFalse($result->error(), 'SHOW TABLES query must succeed: ' . $result->errorString());
        $this->assertSame(1, $result->count(), 'er_verification_settings table must exist');
    }

    public function testTableHasExpectedColumns(): void
    {
        $result = $this->db->query('SHOW COLUMNS FROM er_verification_settings');
        $this->assertFalse($result->error(), 'SHOW COLUMNS query must succeed: ' . $result->errorString());

        $columns = [];
        foreach ($result->results() as $row) {
            $columns[$row->Field] = $row;
        }

        $this->assertArrayHasKey('id', $columns);
        $this->assertArrayHasKey('enabled', $columns);

        $this->assertSame('PRI', $columns['id']->Key, 'id must be the primary key');
        $this->assertSame('NO', $columns['enabled']->Null, 'enabled must be NOT NULL');
        $this->assertSame('0', $columns['enabled']->Default, 'enabled must default to 0');
    }

    public function testSeededDefaultRowExists(): void
    {
        $row = $this->db->query('SELECT id, enabled FROM er_verification_settings WHERE id = 1')->first();

        $this->assertIsObject($row, 'Seeded row id=1 must exist');
        $this->assertSame(1, (int) $row->id);
        $this->assertSame(0, (int) $row->enabled, 'Verification must ship disabled by default');
    }

    public function testOnlyOneRowExists(): void
    {
        // Not schema-enforced (per the migration's own docblock), but the
        // application invariant is a single row — confirm nothing in this
        // environment's history has ever violated it.
        $count = $this->db->query('SELECT COUNT(*) AS cnt FROM er_verification_settings')->first();

        $this->assertSame(1, (int) $count->cnt, 'er_verification_settings must hold exactly one row');
    }
}
