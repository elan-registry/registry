<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationSandbox.php';

use Phinx\Migration\AbstractMigration;
use PHPUnit\Framework\TestCase;

/**
 * Base class for a migration test that runs against its own throwaway schema (#2167).
 *
 * Each subclass gets one sandbox, migrated to targetVersion() before its first
 * test and dropped after its last. A subclass that needs another start version
 * is a separate class. Tests that change sandbox state run in one method or in a
 * #[Depends] chain, so their order is fixed.
 *
 * This class does not extend IntegrationTestCase: that class binds to the shared
 * schema and skips when the database is absent. Here a missing database fails.
 *
 * The class also takes a fingerprint of the shared schema's columns and triggers
 * before and after the class, and fails when they differ. This detects column or
 * trigger changes on the shared schema.
 *
 * Each subclass must declare #[Group('integration')] and #[Group('migration')]
 * itself. PHPUnit reads group attributes from the concrete class only, so an
 * attribute here has no effect.
 */
abstract class MigrationSandboxTestCase extends TestCase
{
    private static ?MigrationSandbox $sandbox = null;

    private static ?string $sharedFingerprint = null;

    /** Stale schemas come from an earlier, killed run, so one sweep per process is enough. */
    private static bool $swept = false;

    /**
     * The last migration version to apply before the tests run. Null applies all.
     */
    abstract protected static function targetVersion(): ?int;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$sharedFingerprint = MigrationSandbox::fingerprint(self::sharedSchemaName());

        $sandbox = new MigrationSandbox();
        if (!self::$swept) {
            $sandbox->sweepStale();
            self::$swept = true;
        }
        self::$sandbox = $sandbox->create();
        try {
            $sandbox->migrateTo(static::targetVersion());
        } catch (\Throwable $e) {
            // PHPUnit does not call tearDownAfterClass() when setUpBeforeClass() throws,
            // so the sandbox must be dropped here or it stays until the next sweep.
            self::$sandbox = null;
            self::$sharedFingerprint = null;
            try {
                $sandbox->drop();
            } catch (\Throwable $dropError) {
                throw new \RuntimeException(
                    $e->getMessage() . ' The drop after that also failed: ' . $dropError->getMessage(),
                    0,
                    $e
                );
            }
            throw $e;
        }
    }

    public static function tearDownAfterClass(): void
    {
        try {
            self::$sandbox?->drop();
        } finally {
            self::$sandbox = null;
            $before = self::$sharedFingerprint;
            self::$sharedFingerprint = null;
            parent::tearDownAfterClass();

            if ($before !== null && $before !== MigrationSandbox::fingerprint(self::sharedSchemaName())) {
                throw new \RuntimeException(
                    'The shared schema ' . self::sharedSchemaName() . ' changed its columns or triggers'
                    . ' during ' . static::class . '. A sandbox test must run DDL on the sandbox only.'
                );
            }
        }
    }

    protected function sandbox(): MigrationSandbox
    {
        if (self::$sandbox === null) {
            throw new \LogicException('The sandbox does not exist. setUpBeforeClass() did not run or failed.');
        }

        return self::$sandbox;
    }

    /**
     * Load a migration file and bind it to the sandbox.
     */
    protected function migration(string $file): AbstractMigration
    {
        return $this->sandbox()->migration($file);
    }

    /**
     * Run up() of a migration file on the sandbox.
     */
    protected function runUp(string $file): void
    {
        $migration = $this->migration($file);
        // Phinx declares none of change(), up() and down() on the base class, so a
        // migration may define only change(). runUp() refuses such a migration.
        if (!method_exists($migration, 'up')) {
            throw new \LogicException($file . ' has no up() method.');
        }
        $migration->up();
    }

    /**
     * Run down() of a migration file on the sandbox.
     */
    protected function runDown(string $file): void
    {
        $migration = $this->migration($file);
        if (!method_exists($migration, 'down')) {
            throw new \LogicException($file . ' has no down() method.');
        }
        $migration->down();
    }

    protected function pdo(): \PDO
    {
        return $this->sandbox()->pdo();
    }

    /**
     * One column's information_schema row in the sandbox, or null if the column does not exist.
     *
     * Impure: up() and down() change the result between two calls with the same arguments.
     *
     * @phpstan-impure
     *
     * @return array{COLUMN_TYPE: string, IS_NULLABLE: string, COLUMN_DEFAULT: ?string, COLUMN_COMMENT: string}|null
     */
    protected function columnInfo(string $table, string $column): ?array
    {
        $statement = $this->pdo()->prepare(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = ?
                AND COLUMN_NAME  = ?
              LIMIT 1'
        );
        $statement->execute([$table, $column]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        /** @var array{COLUMN_TYPE: string, IS_NULLABLE: string, COLUMN_DEFAULT: ?string, COLUMN_COMMENT: string}|false $row */
        return $row === false ? null : $row;
    }

    /**
     * The first column of the first row of a query on the sandbox, as an int.
     *
     * @param list<mixed> $params
     */
    protected function scalar(string $sql, array $params = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    private static function sharedSchemaName(): string
    {
        return MigrationSandbox::env('DB_NAME');
    }
}
