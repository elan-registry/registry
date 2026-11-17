<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Issue #2696: widen the car color columns from varchar(25) to varchar(50).
 *
 * CarValidator accepts a color of up to 50 characters. These columns hold that
 * value:
 *
 * - `cars.color`
 * - `cars_hist.color`, which the `cars` triggers fill by column name
 * - `car_transfer_requests.submitted_color`
 *
 * The triggers need no change. Each column keeps its charset, collation,
 * NULL and DEFAULT NULL. `car_transfer_requests.submitted_color` has no
 * explicit charset, so it keeps the table default.
 *
 * up() and down() are explicit because changeColumn() is not auto-reversible
 * (see database/migrations/README.md). This is DDL only. MySQL commits each
 * ALTER TABLE implicitly, so the migration is not atomic. Each step sets an
 * absolute column definition, so a failed run can run again.
 *
 * down() refuses to run when any of the three columns holds a value of more
 * than 25 characters, `cars_hist` audit rows included. It checks before the
 * first ALTER TABLE, because a non-strict server sql_mode would truncate the
 * value with only a warning. A rollback must not truncate member data.
 * Shorten or remove those values first.
 *
 * Roll back this migration together with the code, in maintenance mode. The
 * 50-character CarValidator would otherwise let a longer color reach the
 * narrowed columns, between the check and the ALTER or after the rollback.
 */
final class WidenCarColorColumns extends AbstractMigration
{
    public function up(): void
    {
        $this->setColorLimits(50);
    }

    public function down(): void
    {
        // Table and column names are literals, never user input. Phinx's
        // fetchRow() does not support parameter binding.
        foreach (['cars' => 'color', 'cars_hist' => 'color', 'car_transfer_requests' => 'submitted_color'] as $table => $column) {
            $row = $this->fetchRow("SELECT COUNT(*) AS n FROM `{$table}` WHERE CHAR_LENGTH(`{$column}`) > 25");
            if (!is_array($row) || !isset($row['n'])) {
                throw new RuntimeException("Cannot roll back: could not count long values in {$table}.{$column}");
            }
            if ((int) $row['n'] > 0) {
                throw new RuntimeException(
                    "Cannot roll back: {$row['n']} row(s) in {$table}.{$column} are longer than 25 characters"
                );
            }
        }

        $this->setColorLimits(25);
    }

    /**
     * Set the length of the three color columns.
     *
     * @param int $limit The varchar length
     */
    private function setColorLimits(int $limit): void
    {
        $carsColor = [
            'limit'     => $limit,
            'null'      => true,
            'default'   => null,
            'encoding'  => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];

        $this->table('cars')
            ->changeColumn('color', 'string', $carsColor)
            ->update();

        $this->table('cars_hist')
            ->changeColumn('color', 'string', $carsColor)
            ->update();

        $this->table('car_transfer_requests')
            ->changeColumn('submitted_color', 'string', [
                'limit'   => $limit,
                'null'    => true,
                'default' => null,
            ])
            ->update();
    }
}
