<?php

declare(strict_types=1);

use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Db\Adapter\ProxyAdapter;
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\Manager;
use Phinx\Migration\MigrationInterface;
use Phinx\Util\Util;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A throwaway MySQL schema for migration tests (#2167).
 *
 * The schema starts empty, gets the vendored UserSpice structure, then Phinx
 * migrates it to a target version. Tests then run a migration's up()/down()
 * against it, so no test runs DDL on the shared integration schema.
 *
 * Every CREATE and DROP goes through a name guard. The guard accepts only
 * `elanregi_migtest_<unixtime>_<rand>` names and never the shared DB_NAME.
 * The `elanregi_` prefix is what the container user's grant covers.
 *
 * This class has no PHPUnit dependency, so the harness self-tests can call it
 * directly.
 */
final class MigrationSandbox
{
    /** Repo-relative path of the grant that lets the container user CREATE an elanregi_* schema. */
    public const GRANT_FILE = 'docker/mysql-init/01-grant-all-elanregi-schemas.sql';

    /** MySQL ER_DBACCESS_DENIED_ERROR: the user has no privilege on the schema. */
    private const MYSQL_ACCESS_DENIED_TO_SCHEMA = 1044;

    public const NAME_PREFIX = 'elanregi_migtest_';

    /**
     * Group 1 is the unix time at creation; sweepStale() reads it.
     * D stops `$` from matching before a trailing newline, so "name\n" is refused.
     */
    public const NAME_PATTERN = '/^elanregi_migtest_(\d+)_[a-z0-9]+$/D';

    private const VENDOR_SQL = 'database/vendor/userspice-6.1.7-base.sql';

    private const MIGRATIONS_DIR = 'database/migrations';

    private const DEFAULT_MAX_AGE_SECONDS = 3600;

    /** @var \Closure(?string): \PDO */
    private \Closure $pdoFactory;

    private ?string $name = null;

    private ?\PDO $sandboxPdo = null;

    /**
     * @param (callable(?string): \PDO)|null $pdoFactory Returns a connection to the named
     *        schema, or a server connection without a schema for null. Null uses
     *        defaultPdoFactory(). A self-test injects one that fails.
     */
    public function __construct(?callable $pdoFactory = null)
    {
        $this->pdoFactory = $pdoFactory === null
            ? self::defaultPdoFactory()
            : \Closure::fromCallable($pdoFactory);
    }

    /**
     * The PDO factory that connects with the DB_* values of the integration environment.
     *
     * DB_HOST can hold `host:port`, the form users/classes/DB.php reads.
     *
     * @return \Closure(?string): \PDO
     */
    public static function defaultPdoFactory(): \Closure
    {
        return static function (?string $dbName): \PDO {
            $host = self::env('DB_HOST');
            $port = self::env('DB_PORT') !== '' ? self::env('DB_PORT') : '3306';

            if (str_contains($host, ':')) {
                [$host, $port] = explode(':', $host, 2);
            }

            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            if ($dbName !== null) {
                $dsn .= ";dbname={$dbName}";
            }

            return new \PDO($dsn, self::env('DB_USER'), self::env('DB_PASS'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        };
    }

    /**
     * Make a new sandbox schema and load the vendored UserSpice structure into it.
     *
     * @throws \RuntimeException When the schema cannot be made or loaded. This is a
     *         failure, not a skip: a missing grant must be visible.
     */
    public function create(): self
    {
        if ($this->name !== null) {
            throw new \LogicException("Sandbox {$this->name} already exists. Call drop() first.");
        }

        $name = self::NAME_PREFIX . time() . '_' . bin2hex(random_bytes(4));
        $this->assertSandboxName($name);

        // Outside the try: a connection error has its own message, and the grant hint would mislead.
        $server = $this->connect(null);
        try {
            $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\PDOException $e) {
            $hint = ($e->errorInfo[1] ?? null) === self::MYSQL_ACCESS_DENIED_TO_SCHEMA
                ? ' The DB user needs CREATE and DROP on elanregi_% schemas. Check that the grant in '
                    . self::GRANT_FILE . ' was applied (it runs only on a fresh db volume).'
                : '';
            throw new \RuntimeException(
                "Could not create migration sandbox schema {$name}: {$e->getMessage()}.{$hint}",
                0,
                $e
            );
        }

        $this->name = $name;

        try {
            $this->loadVendorSchema();
        } catch (\Throwable $e) {
            try {
                $this->drop();
            } catch (\Throwable $dropError) {
                // Keep the load error as the cause. The drop error alone hides why create() failed.
                throw new \RuntimeException(
                    "Could not load the vendor schema into sandbox {$name}: {$e->getMessage()}. "
                    . "The drop after that also failed: {$dropError->getMessage()}",
                    0,
                    $e
                );
            }
            throw new \RuntimeException(
                "Could not load the vendor schema into sandbox {$name}: {$e->getMessage()}",
                0,
                $e
            );
        }

        return $this;
    }

    /**
     * The sandbox schema name.
     */
    public function name(): string
    {
        if ($this->name === null) {
            throw new \LogicException('The sandbox does not exist. Call create() first.');
        }

        return $this->name;
    }

    /**
     * Run Phinx migrations on the sandbox, in this process.
     *
     * @param int|null $version The last migration version to apply. Null applies all.
     *
     * @throws \RuntimeException When the version matches no migration file, when Phinx fails,
     *         or when the sandbox phinxlog does not end at the target version.
     */
    public function migrateTo(?int $version): void
    {
        $config = self::phinxConfig();
        $config['paths'] = [
            'migrations' => self::repoPath(self::MIGRATIONS_DIR),
            'seeds' => self::repoPath('database/seeds'),
        ];
        $config['environments']['default']['name'] = $this->name();

        $output = new BufferedOutput();
        $manager = new Manager(new Config($config), new ArrayInput([]), $output);

        // Phinx only writes a warning for an unknown version and returns, so the
        // sandbox would silently stay at the vendor schema.
        $known = array_keys($manager->getMigrations('default'));
        if ($version !== null && !in_array($version, $known, true)) {
            throw new \RuntimeException(
                "Migration version {$version} matches no file in " . self::MIGRATIONS_DIR . '.'
            );
        }
        $expected = $version ?? ($known === [] ? null : max($known));

        try {
            $manager->migrate('default', $version);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Phinx migrate to " . ($version ?? 'latest') . " failed on sandbox {$this->name}: "
                . $e->getMessage() . "\nPhinx output:\n" . $output->fetch(),
                0,
                $e
            );
        }

        $statement = $this->pdo()->query('SELECT MAX(version) FROM phinxlog');
        $actual = $statement === false ? false : $statement->fetchColumn();
        $actual = $actual === false || $actual === null ? null : (int) $actual;
        if ($actual !== $expected) {
            throw new \RuntimeException(
                "Phinx migrate on sandbox {$this->name} ended at version " . ($actual ?? 'none')
                . ', expected ' . ($expected ?? 'none') . ".\nPhinx output:\n" . $output->fetch()
            );
        }
    }

    /**
     * Load one migration file and bind it to the sandbox. The caller passes it to run().
     *
     * @param string $file The migration file name, for example `20260916000000_seed_x.php`.
     */
    public function migration(string $file): AbstractMigration
    {
        $path = self::repoPath(self::MIGRATIONS_DIR . '/' . basename($file));
        if (!is_file($path)) {
            throw new \InvalidArgumentException("Migration file not found: {$path}");
        }

        // require_once resolves the real path, so a file that Phinx loaded is not declared twice.
        require_once $path;

        $class = Util::mapFileNameToClassName(basename($path));
        if (!class_exists($class) || !is_subclass_of($class, AbstractMigration::class)) {
            throw new \UnexpectedValueException("{$path} does not define migration class {$class}");
        }

        return $this->bind(new $class('default', Util::getVersionFromFileName(basename($path))));
    }

    /**
     * Give a migration an adapter on the sandbox connection.
     */
    public function bind(AbstractMigration $migration): AbstractMigration
    {
        $adapter = new MysqlAdapter(['name' => $this->name()]);
        $adapter->setConnection($this->pdo());
        $migration->setAdapter($adapter);

        return $migration;
    }

    /**
     * Run one direction of a bound migration, with the same method dispatch as Phinx
     * Environment::executeMigration(). A migration with change() runs change() and
     * ignores up() and down(), as in Phinx, so the test runs the code that
     * `composer migrate` runs.
     *
     * These Phinx steps are left out:
     * - The phinxlog row, so a test can run the same direction twice.
     * - preFlightCheck(): it only writes a warning, and the migration has no output.
     * - The START TRANSACTION around the migration. Phinx never rolls it back: after a
     *   failure, the server discards the transaction that is still open when the process
     *   ends. Here no outer transaction exists, so a failed migration can keep rows that
     *   Phinx would discard.
     * - The shouldExecute() skip in Manager::executeMigration(). No migration overrides it.
     *
     * @param 'up'|'down' $direction
     *
     * @throws \LogicException When the migration has neither change() nor the direction's method.
     * @throws \RuntimeException From postFlightCheck(), when a table builder was not saved.
     */
    public function run(AbstractMigration $migration, string $direction): void
    {
        $migration->setMigratingUp($direction === MigrationInterface::UP);

        if (method_exists($migration, MigrationInterface::INIT)) {
            $migration->{MigrationInterface::INIT}();
        }

        if (method_exists($migration, MigrationInterface::CHANGE)) {
            if ($direction === MigrationInterface::DOWN) {
                $adapter = $migration->getAdapter();
                $proxy = AdapterFactory::instance()->getWrapper('proxy', $adapter);
                if (!$proxy instanceof ProxyAdapter) {
                    throw new \UnexpectedValueException('The Phinx proxy wrapper is not a ProxyAdapter.');
                }
                $migration->setAdapter($proxy);
                $migration->{MigrationInterface::CHANGE}();
                $proxy->executeInvertedCommands();
                $migration->setAdapter($adapter);
            } else {
                $migration->{MigrationInterface::CHANGE}();
            }
        } elseif (method_exists($migration, $direction)) {
            $migration->{$direction}();
        } else {
            // Phinx declares none of change(), up() and down() on the base class.
            throw new \LogicException(get_class($migration) . " has no change() or {$direction}() method.");
        }

        $migration->postFlightCheck();
    }

    /**
     * A connection to the sandbox schema, for assertions.
     */
    public function pdo(): \PDO
    {
        if ($this->sandboxPdo === null) {
            $this->sandboxPdo = $this->connect($this->name());
        }

        return $this->sandboxPdo;
    }

    /**
     * Drop the sandbox schema. Safe to call more than once.
     */
    public function drop(): void
    {
        if ($this->name === null) {
            return;
        }

        $this->sandboxPdo = null;
        $this->dropSchema($this->name);
        $this->name = null;
    }

    /**
     * Drop one sandbox schema by name, after the name guard.
     *
     * @throws \InvalidArgumentException When the name is not a sandbox name.
     */
    public function dropSchema(string $schema): void
    {
        $this->assertSandboxName($schema);
        $this->connect(null)->exec("DROP DATABASE IF EXISTS `{$schema}`");
    }

    /**
     * Drop each sandbox schema that a killed run left behind.
     *
     * The age comes from the name, because information_schema has no schema creation time.
     *
     * @return list<string> The dropped schema names.
     */
    public function sweepStale(int $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS): array
    {
        $statement = $this->connect(null)->prepare(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ?'
        );
        $statement->execute([str_replace('_', '\\_', self::NAME_PREFIX) . '%']);

        $cutoff = time() - $maxAgeSeconds;
        $dropped = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $schema) {
            $schema = (string) $schema;
            if (preg_match(self::NAME_PATTERN, $schema, $match) !== 1 || (int) $match[1] >= $cutoff) {
                continue;
            }
            $this->dropSchema($schema);
            $dropped[] = $schema;
        }

        return $dropped;
    }

    /**
     * The name guard for every CREATE and DROP.
     *
     * @throws \InvalidArgumentException When the name does not match the sandbox pattern,
     *         or is the shared DB_NAME.
     */
    public function assertSandboxName(string $schema): void
    {
        $sharedName = strtolower(trim(self::env('DB_NAME')));
        if ($sharedName !== '' && strtolower(trim($schema)) === $sharedName) {
            throw new \InvalidArgumentException(
                "Refusing DDL on {$schema}: it is the shared DB_NAME, not a migration sandbox."
            );
        }

        if (preg_match(self::NAME_PATTERN, $schema) !== 1) {
            throw new \InvalidArgumentException(
                "Refusing DDL on {$schema}: the name does not match " . self::NAME_PATTERN . '.'
            );
        }
    }

    /**
     * The trigger names on one sandbox table, sorted.
     *
     * @return list<string>
     */
    public function triggerNames(string $table): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?
              ORDER BY TRIGGER_NAME'
        );
        $statement->execute([$table]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * A hash of one schema's column and trigger definitions.
     *
     * @throws \RuntimeException When the schema name is empty or the schema has no columns.
     *         An empty hash input would make two calls match and hide a change.
     */
    public static function fingerprint(string $schema): string
    {
        if ($schema === '') {
            throw new \RuntimeException('Cannot fingerprint a schema with an empty name.');
        }

        $pdo = (new self())->connect(null);

        $columns = $pdo->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE,
                    COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ?
              ORDER BY TABLE_NAME, ORDINAL_POSITION'
        );
        $columns->execute([$schema]);
        $columnRows = $columns->fetchAll(\PDO::FETCH_NUM);
        if ($columnRows === []) {
            throw new \RuntimeException("Cannot fingerprint schema {$schema}: it has no columns.");
        }

        $triggers = $pdo->prepare(
            'SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = ?
              ORDER BY TRIGGER_NAME'
        );
        $triggers->execute([$schema]);

        return hash('sha256', json_encode([
            $columnRows,
            $triggers->fetchAll(\PDO::FETCH_NUM),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Call the PDO factory. An injected factory can return anything and can leave
     * errors silent, so every call checks the type and forces exceptions.
     *
     * @throws \UnexpectedValueException When the factory does not return a PDO.
     */
    private function connect(?string $db): \PDO
    {
        $pdo = ($this->pdoFactory)($db);
        if (!$pdo instanceof \PDO) {
            throw new \UnexpectedValueException(
                'The PDO factory returned ' . get_debug_type($pdo) . ', not a PDO.'
            );
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /**
     * Load the vendored UserSpice structure, as scripts/provision-schema.sh does.
     *
     * The file is a mysqldump with no DELIMITER blocks, so one multi-statement
     * query loads it. nextRowset() makes an error in a later statement throw.
     * A separate connection keeps the dump's session SET statements out of pdo().
     */
    private function loadVendorSchema(): void
    {
        $sql = file_get_contents(self::repoPath(self::VENDOR_SQL));
        if ($sql === false) {
            throw new \RuntimeException('Could not read ' . self::VENDOR_SQL);
        }

        $statement = $this->connect($this->name())->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('The vendor schema query returned no statement.');
        }
        while ($statement->nextRowset()) {
            // Each step surfaces the error of the next statement, if any.
        }
    }

    /**
     * The array that phinx.php returns. phinx.php calls exit(1) when DB_NAME, DB_USER or
     * DB_PASS is missing. That ends the whole PHPUnit process, with one line on stderr and
     * no test result. The integration bootstrap sets them before this runs.
     *
     * @return array<string, mixed>
     */
    private static function phinxConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require self::repoPath('phinx.php');

        return $config;
    }

    private static function repoPath(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    /**
     * One environment value, from $_ENV first, then getenv(). Empty when not set.
     */
    public static function env(string $key): string
    {
        $value = $_ENV[$key] ?? getenv($key);

        return is_string($value) ? $value : '';
    }
}
