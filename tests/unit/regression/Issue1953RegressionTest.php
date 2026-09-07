<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;

/**
 * Regression test for Issue #1953: verification freshness test still falls
 * back to cars.mtime.
 *
 * Root cause: #1155 (v2.30.0) shipped `findVerificationEligible()` with
 * `COALESCE(owner_last_updated, mtime)` in its staleness check. `cars.mtime`
 * is `ON UPDATE CURRENT_TIMESTAMP`, so MySQL bumps it on any UPDATE that
 * changes a value — including an owner-profile sync unrelated to the car's
 * data being confirmed (Owner::syncOwnerFieldsToCars()). That silently reset
 * every such car's verification clock. The fix makes `cars.owner_last_updated`
 * NOT NULL (removing the need for a fallback) and drops mtime from the
 * freshness definition entirely — see CarRepository::freshnessSql().
 *
 * This test pins the three properties the plan calls out as must-never-
 * silently-revert (Test Plan, "Preventive measures" / regression list):
 *  - findVerificationEligible() no longer references
 *    COALESCE(owner_last_updated, mtime).
 *  - cars.owner_last_updated no longer accepts NULL (schema-level, pinned
 *    here via the migration's declared column definition rather than a live
 *    DB — a live-DB assertion of this belongs in the integration suite).
 *  - cars.mtime keeps its ON UPDATE CURRENT_TIMESTAMP clause across the
 *    TIMESTAMP -> DATETIME conversion (also pinned at the source/migration
 *    level; a live-DB assertion belongs in the integration suite).
 *
 * @issue 1953
 * @link https://github.com/elan-registry/registry/issues/1953
 * @description owner_last_updated is NOT NULL and the COALESCE-to-mtime
 *   fallback is gone from the freshness/staleness SQL.
 * @category regression
 */
#[Group('regression')]
#[Group('fast')]
final class Issue1953RegressionTest extends RegressionTestCase
{
    private const CAR_REPOSITORY_PATH = __DIR__ . '/../../../usersc/classes/Car/CarRepository.php';

    private const MIGRATION_PATH = __DIR__
        . '/../../../database/migrations/20260905172137_convert_car_timestamps_to_datetime.php';

    private function carRepositorySource(): string
    {
        $source = file_get_contents(self::CAR_REPOSITORY_PATH);
        $this->assertIsString($source, 'CarRepository.php must be readable');
        return $source;
    }

    private function migrationSource(): string
    {
        $source = file_get_contents(self::MIGRATION_PATH);
        $this->assertIsString(
            $source,
            'The #1953 migration (20260905172137_convert_car_timestamps_to_datetime.php) must be readable. '
                . 'If it has been renamed, this test needs updating alongside it — Phinx filenames are part of '
                . 'phinxlog tracking and must never be renamed casually.'
        );
        return $source;
    }

    /**
     * Slice out the body of up() from a migration source string, i.e.
     * everything between "public function up()" and "public function down()".
     * Both callers need this isolation so a "DATETIME NULL" or similar string
     * found only in down()'s restoration logic can't be mistaken for the same
     * string appearing in up().
     */
    private function upFunctionBody(string $source): string
    {
        $upStart = strpos($source, 'public function up()');
        $downStart = strpos($source, 'public function down()');
        $this->assertNotFalse($upStart, 'Could not locate up() in the migration');
        $this->assertNotFalse($downStart, 'Could not locate down() in the migration');
        return substr($source, $upStart, $downStart - $upStart);
    }

    /**
     * The defect: COALESCE(owner_last_updated, mtime) reintroduced silently by
     * a future edit to findVerificationEligible() or freshnessSql()/
     * stalenessSql(). Neither COALESCE nor a bare reference to mtime as a
     * staleness fallback should ever appear again in CarRepository.php.
     */
    public function testFindVerificationEligibleNoLongerReferencesCoalesceOwnerLastUpdatedMtime(): void
    {
        $source = $this->carRepositorySource();

        $this->assertStringNotContainsString(
            'COALESCE(owner_last_updated, mtime)',
            $source,
            'The COALESCE(owner_last_updated, mtime) fallback must not be reintroduced into '
                . 'CarRepository.php — mtime is ON UPDATE CURRENT_TIMESTAMP and gets bumped by '
                . 'unrelated owner-profile syncs, which silently resets a car\'s verification clock '
                . '(the exact defect #1953 fixes). See freshnessSql().'
        );

        // Belt-and-suspenders: freshnessSql() itself must not reference mtime
        // as part of its boolean expression. (CarRepository.php's docblocks
        // legitimately mention COALESCE by name as a documented anti-pattern
        // — "must NOT be used" — so a blanket COALESCE-anywhere-in-file
        // assertion would be a false positive; the check above already pins
        // the actual runtime-SQL string.)
        $freshnessSqlPos = strpos($source, 'function freshnessSql');
        $this->assertNotFalse($freshnessSqlPos, 'Could not locate freshnessSql() in CarRepository.php');
        $nextMethodPos = strpos($source, 'function stalenessSql', $freshnessSqlPos);
        $this->assertNotFalse($nextMethodPos, 'Could not locate the end of freshnessSql() (next method: stalenessSql)');
        $freshnessSqlBody = substr($source, $freshnessSqlPos, $nextMethodPos - $freshnessSqlPos);

        $this->assertStringNotContainsString(
            'mtime',
            $freshnessSqlBody,
            'freshnessSql() must not reference cars.mtime — only last_verified and owner_last_updated'
        );
    }

    /**
     * Schema pin: cars.owner_last_updated must be declared NOT NULL in the
     * migration that performs the conversion. This is a source-level
     * assertion over the migration file rather than a live-DB
     * information_schema check (that belongs in
     * tests/integration/database/CarVerificationTimestampMigrationTest.php,
     * which this repository's other agent owns) — this unit-level pin still
     * catches a future hand-edit of the migration file that silently drops
     * NOT NULL before it would ever reach a database.
     */
    public function testOwnerLastUpdatedNoLongerAcceptsNullValue(): void
    {
        $source = $this->migrationSource();

        $this->assertMatchesRegularExpression(
            '/MODIFY COLUMN `owner_last_updated`\s*\'?\s*\.?\s*\'?DATETIME NOT NULL/',
            $source,
            'The migration must declare cars.owner_last_updated as DATETIME NOT NULL — '
                . 'reverting to nullable reopens the #1873/#1953 hole where a NULL owner_last_updated '
                . 'silently falls back to a fallback expression'
        );

        // The up() direction must not declare owner_last_updated as nullable
        // anywhere (i.e. the down()-only "DATETIME NULL" restoration for this
        // column must not leak into up()).
        $upBody = $this->upFunctionBody($source);

        $this->assertStringNotContainsString(
            'owner_last_updated` DATETIME NULL',
            $upBody,
            'up() must not declare owner_last_updated as nullable'
        );
    }

    /**
     * Schema pin: cars.mtime must keep both DEFAULT CURRENT_TIMESTAMP and
     * ON UPDATE CURRENT_TIMESTAMP after the TIMESTAMP -> DATETIME conversion.
     * ON UPDATE CURRENT_TIMESTAMP is legal on DATETIME but is silently lost
     * if a column definition omits it — the exact hazard called out in the
     * migration's own comments. As with the previous test, this is a
     * source-level pin; the live-DB EXTRA assertion belongs in
     * tests/integration/database/CarVerificationTimestampMigrationTest.php.
     */
    public function testCarsMtimeOnUpdateClausePreservedAfterTimestampToDatetimeConversion(): void
    {
        $source = $this->migrationSource();
        $upBody = $this->upFunctionBody($source);

        $mtimePos = strpos($upBody, "MODIFY COLUMN `mtime`");
        $this->assertNotFalse($mtimePos, 'Could not locate the cars.mtime MODIFY COLUMN statement in up()');

        // Statements in this migration are built as a PHP string concatenated
        // across a couple of lines and passed to $this->execute(); capture a
        // generous window after the MODIFY COLUMN keyword to include the full
        // concatenated definition.
        $mtimeStatement = substr($upBody, $mtimePos, 300);

        $this->assertStringContainsString(
            'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            $mtimeStatement,
            'cars.mtime must remain DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP '
                . 'after the conversion — losing ON UPDATE here would silently stop mtime from tracking edits'
        );
    }
}
