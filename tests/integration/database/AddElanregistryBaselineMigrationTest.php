<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Migration 20260709000000_add_elanregistry_baseline: its ElanRegistry tables
 * and two of their indexes exist. CarsYearSmallintMigrationTest covers the car
 * audit triggers.
 */
#[Group('integration')]
#[Group('migration')]
final class AddElanregistryBaselineMigrationTest extends IntegrationTestCase
{
    /**
     * The tables the migration creates (phinxlog excluded) that still exist.
     * Match only the migration's CREATE TABLE statements: #1679 removed
     * `notifications` (a settings column) and the plg_db_explainer_* tables
     * (plugin-owned). `country` is left out because migration
     * 20261007005444_drop_unused_country_table drops it.
     */
    private const REGISTRY_TABLES = [
        'car_models',
        'car_transfer_requests',
        'cars',
        'cars_hist',
        'deleted_accounts_archive',
        'elan_factory_info',
        'fix_script_runs',
        'plg_sendinblue',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // Skip, not fail, when the migration is not applied.
        $exists = $this->db->query(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'car_models'"
        )->first();

        if (!$exists || (int) $exists->cnt === 0) {
            $this->markTestSkipped(
                'Migration 20260709000000 has not been applied — car_models does not exist. ' .
                'Run: composer migrate'
            );
        }
    }

    #[Group('integration')]
    #[Group('migration')]
    public function test_allRegistryTablesExist(): void
    {
        $placeholders = "'" . implode("','", self::REGISTRY_TABLES) . "'";
        $rows = $this->db->query(
            "SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ({$placeholders})"
        )->results();

        $found = array_map(static fn(object $r): string => $r->TABLE_NAME, $rows);

        foreach (self::REGISTRY_TABLES as $table) {
            $this->assertContains(
                $table,
                $found,
                "Table `{$table}` must exist after the baseline migration"
            );
        }
    }

    /**
     * car_models.model_value backs CarModelsSeed's idempotent INSERT IGNORE —
     * without the UNIQUE index, re-running the seed would duplicate rows
     * instead of silently skipping them.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function test_carModelsTable_hasUniqueModelValueIndex(): void
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'car_models'
               AND INDEX_NAME = 'model_value'
               AND NON_UNIQUE = 0"
        )->first();

        $this->assertGreaterThan(
            0,
            (int) $row->cnt,
            'car_models.model_value must have a UNIQUE index'
        );
    }

    /**
     * cars_hist.car_id must exist and be indexed — Car audit-trail lookups
     * (e.g. CarRepository history queries) filter on it.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function test_carsHistTable_hasIndexedCarIdColumn(): void
    {
        $column = $this->db->query(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cars_hist'
               AND COLUMN_NAME = 'car_id'"
        )->first();
        $this->assertNotNull($column, 'Column cars_hist.car_id must exist');

        $index = $this->db->query(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cars_hist'
               AND COLUMN_NAME = 'car_id'"
        )->first();
        $this->assertGreaterThan(0, (int) $index->cnt, 'cars_hist.car_id must be indexed');
    }
}
