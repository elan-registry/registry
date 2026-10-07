<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Drops the `country` table. Dead since before this migration directory
 * existed: `usersc/join.php` SELECTed from it into `$countrylist`, and
 * nothing — no view, no template — ever read that variable. The baseline
 * migration (20260709000000_add_elanregistry_baseline) created the table
 * only for schema fidelity with the then-live database and flagged its
 * removal as #2304, out of scope at the time.
 *
 * `usersc/join.php`'s dead `$countrylist`/`$popularCountries` assembly is
 * removed in the same change that adds this migration. `country` had no
 * readers after that removal.
 *
 * Explicit up()/down(), matching the other schema migrations in this
 * directory. `down()` restores the table's structure, matching the baseline
 * migration's original `CREATE TABLE` (signed `id`, `utf8mb4_unicode_ci`).
 * It restores structure only, not rows: a database built from the baseline
 * had an empty table, but an older environment (e.g. production, which
 * predates the baseline) can hold rows that `up()` deletes and `down()`
 * cannot bring back.
 */
final class DropUnusedCountryTable extends AbstractMigration
{
    private const TABLE = 'country';

    public function up(): void
    {
        if ($this->hasTable(self::TABLE)) {
            $this->table(self::TABLE)->drop()->save();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable(self::TABLE)) {
            $table = $this->table(self::TABLE, [
                'id'          => false,
                'primary_key' => ['id'],
                'collation'   => 'utf8mb4_unicode_ci',
            ]);
            $table->addColumn('id', 'integer', ['identity' => true, 'signed' => true])
                ->addColumn('name', 'string', ['limit' => 100, 'null' => false, 'default' => ''])
                ->create();
        }
    }
}
