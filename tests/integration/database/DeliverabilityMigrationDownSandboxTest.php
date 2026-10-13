<?php

declare(strict_types=1);

require_once __DIR__ . '/DeliverabilityMigrationSandboxTestCase.php';

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * up(), down() and up() of migration 20261012000000 on the fixture (#2101).
 */
#[Group('integration')]
#[Group('migration')]
final class DeliverabilityMigrationDownSandboxTest extends DeliverabilityMigrationSandboxTestCase
{
    protected static function targetVersion(): int
    {
        return self::VERSION_BEFORE;
    }

    /** @var array<string, list<array<string, mixed>>> */
    private static array $schemaBefore = [];

    /** @var array<string, string> */
    private static array $triggersBefore = [];

    /** @var list<array<string, mixed>> */
    private static array $profilesAfterUp = [];

    /** @var array<int|string, string> */
    private static array $carMtimes = [];

    private static int $historyRows = 0;

    public function testUpFromTheFixture(): void
    {
        foreach (['profiles', 'cars', 'cars_hist'] as $table) {
            self::$schemaBefore[$table] = $this->showColumns($table);
        }
        self::$triggersBefore = $this->carsTriggerBodies();
        $this->assertCount(3, self::$triggersBefore);

        $this->upWithOutput();

        self::$profilesAfterUp = $this->profileSnapshot();
        self::$carMtimes = $this->carMtimes();
        self::$historyRows = $this->scalar('SELECT COUNT(*) FROM cars_hist');
        $this->assertCount(17, self::$carMtimes, 'The fixture has 17 cars');
        $this->assertSame(\DeliverabilityMigrationFixture::MTIME, self::$carMtimes[1010]);
    }

    #[Depends('testUpFromTheFixture')]
    public function testDownRestoresTheSchemaTheTriggersAndTheCopiedValues(): void
    {
        $this->runDown(self::MIGRATION);

        foreach (['profiles', 'cars', 'cars_hist'] as $table) {
            $this->assertSame(self::$schemaBefore[$table], $this->showColumns($table), "{$table} must have its old columns, in the old order");
        }
        $this->assertNull($this->columnInfo('profiles', 'email_opted_out'), 'The rename must be reversed');
        $this->assertNotNull($this->columnInfo('profiles', 'email_suppressed'));
        $this->assertFalse($this->hasIndex('profiles', 'uniq_profiles_user_id'), 'The key must be gone');
        $this->assertSame(self::$triggersBefore, $this->carsTriggerBodies(), 'The triggers must be the old ones');

        // Copy-back: the profile flags go to every car of the owner. Dropped flags stay lost.
        $this->assertSame([1010, 1090, 1091, 1140, 1150, 1160], $this->carIds('email_bounced = 1'));
        $this->assertSame(
            ['bounce.current@example.test', 'noprofile109@example.test', 'noprofile109@example.test',
                'event114@example.test', 'hist115@example.test', 'nomatch116@example.test'],
            $this->carBouncedAddresses()
        );
        // Opted out (104, 106) or blocked (103, 104, 105, 111) owners. Car 1070 belongs to 108, who has no flag.
        $this->assertSame([1030, 1040, 1050, 1060, 1110], $this->carIds('email_suppressed = 1'));

        $this->assertSame(self::$carMtimes, $this->carMtimes(), 'down() must not change cars.mtime');
        $this->assertSame(self::$historyRows, $this->scalar('SELECT COUNT(*) FROM cars_hist'), 'down() must not write cars_hist rows');
        $this->assertSame(1, $this->scalar('SELECT @disable_triggers IS NULL'), 'down() must reset @disable_triggers to NULL');
    }

    #[Depends('testDownRestoresTheSchemaTheTriggersAndTheCopiedValues')]
    public function testUpAgainGivesTheSameProfilesRowsAsTheFirstUp(): void
    {
        $this->upWithOutput();

        $this->assertSame(self::$profilesAfterUp, $this->profileSnapshot());
        $this->assertNull($this->columnInfo('cars', 'email_bounced'));
        $this->assertTrue($this->hasIndex('profiles', 'uniq_profiles_user_id'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function showColumns(string $table): array
    {
        $statement = $this->pdo()->query("SHOW COLUMNS FROM `{$table}`");
        $this->assertNotFalse($statement);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @return array<int|string, string>
     */
    private function carMtimes(): array
    {
        $statement = $this->pdo()->query('SELECT id, mtime FROM cars ORDER BY id');
        $this->assertNotFalse($statement);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    /**
     * @return list<int>
     */
    private function carIds(string $where): array
    {
        $statement = $this->pdo()->query("SELECT id FROM cars WHERE {$where} ORDER BY id");
        $this->assertNotFalse($statement);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return list<string>
     */
    private function carBouncedAddresses(): array
    {
        $statement = $this->pdo()->query('SELECT email_bounced_address FROM cars WHERE email_bounced = 1 ORDER BY id');
        $this->assertNotFalse($statement);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }
}
