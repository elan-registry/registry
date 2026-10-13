<?php

declare(strict_types=1);

namespace Tests\Unit\Cars\Services;

use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * CarRepository::latestFlagClearAt() and recentFlagChanges() read the flag-change
 * rows that DeliverabilityFlagLog::record() writes (#2101).
 *
 * The database is a stub. These tests check the argument checks, the LIMIT clamp
 * and the PHP-side decoding of `logs.metadata`. The SQL JSON match itself (the
 * integer owner_id compare, the BLOB conversion) needs a live database and is
 * not tested here.
 *
 * The structured input is the decoded metadata. A present value of the wrong
 * type must make the row skipped, never a TypeError.
 */
#[Group('fast')]
final class CarRepositoryFlagLogMetadataTest extends TestCase
{
    private const OWNER = 12;

    /** @var list<array{string, array<int, mixed>}> */
    private array $queries = [];

    /**
     * @param list<object> $rows
     */
    private function makeRepo(array $rows, bool $error = false): CarRepository
    {
        $this->queries = [];
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnCallback(function (string $sql, array $params = []) use ($db) {
            $this->queries[] = [$sql, $params];

            return $db;
        });
        $db->method('error')->willReturn($error);
        $db->method('errorString')->willReturn('mock failure');
        $db->method('results')->willReturn($rows);

        return new CarRepository($db);
    }

    /**
     * @param array<string, mixed>|string|null $metadata Array is JSON-encoded, string is used as is
     */
    private static function row(array|string|null $metadata, string $logdate = '2026-05-01 10:00:00', int|string $userId = 3): object
    {
        return (object) [
            'id' => 1,
            'user_id' => $userId,
            'logdate' => $logdate,
            'metadata_json' => is_array($metadata) ? json_encode($metadata) : $metadata,
        ];
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function meta(array $override = []): array
    {
        return array_merge(
            ['owner_id' => self::OWNER, 'flag' => 'email_blocked', 'old' => 1, 'new' => 0, 'source' => 'admin'],
            $override
        );
    }

    // --- argument checks ---------------------------------------------------

    public function testLatestFlagClearAtThrowsOnUnknownFlagBeforeQuerying(): void
    {
        $repo = $this->makeRepo([]);

        try {
            $repo->latestFlagClearAt(self::OWNER, 'email_unsubscribed', 'admin');
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown deliverability flag', $e->getMessage());
        }
        $this->assertSame([], $this->queries);
    }

    public function testLatestFlagClearAtThrowsOnUnknownSourceBeforeQuerying(): void
    {
        $repo = $this->makeRepo([]);

        try {
            $repo->latestFlagClearAt(self::OWNER, 'email_blocked', 'cron');
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown deliverability flag source', $e->getMessage());
        }
        $this->assertSame([], $this->queries);
    }

    #[DataProvider('badOwnerIdProvider')]
    public function testLatestFlagClearAtThrowsOnOwnerIdZeroOrNegative(int $ownerId): void
    {
        $repo = $this->makeRepo([]);

        try {
            $repo->latestFlagClearAt($ownerId, 'email_blocked', 'admin');
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('owner ID must be more than 0', $e->getMessage());
        }
        $this->assertSame([], $this->queries);
    }

    #[DataProvider('badOwnerIdProvider')]
    public function testRecentFlagChangesThrowsOnOwnerIdZeroOrNegative(int $ownerId): void
    {
        $repo = $this->makeRepo([]);

        try {
            $repo->recentFlagChanges($ownerId, 10);
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('owner ID must be more than 0', $e->getMessage());
        }
        $this->assertSame([], $this->queries);
    }

    /** @return array<string, array{int}> */
    public static function badOwnerIdProvider(): array
    {
        return ['zero' => [0], 'negative' => [-5]];
    }

    // --- limit clamp ---------------------------------------------------------

    #[DataProvider('limitProvider')]
    public function testRecentFlagChangesClampsLimitToOneThroughOneHundred(int $limit, int $expectedSqlLimit): void
    {
        $repo = $this->makeRepo([]);

        $repo->recentFlagChanges(self::OWNER, $limit);

        $this->assertCount(1, $this->queries);
        $this->assertStringContainsString('LIMIT ' . $expectedSqlLimit, $this->queries[0][0]);
        $this->assertSame(1, preg_match_all('/LIMIT \d+/', $this->queries[0][0]));
    }

    /** @return array<string, array{int, int}> */
    public static function limitProvider(): array
    {
        return [
            'zero' => [0, 1],
            'negative' => [-7, 1],
            'int min' => [PHP_INT_MIN, 1],
            'one' => [1, 1],
            'inside the range' => [25, 25],
            'maximum' => [100, 100],
            'just over' => [101, 100],
            'huge' => [PHP_INT_MAX, 100],
        ];
    }

    public function testLatestFlagClearAtReadsUpToOneHundredRows(): void
    {
        $repo = $this->makeRepo([]);

        $repo->latestFlagClearAt(self::OWNER, 'email_blocked', 'admin');

        $this->assertStringContainsString('LIMIT 100', $this->queries[0][0]);
    }

    public function testQueryBindsLogCategoryAndOwnerAsString(): void
    {
        $repo = $this->makeRepo([]);

        $repo->recentFlagChanges(self::OWNER, 5);

        $this->assertSame([LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, '12'], $this->queries[0][1]);
    }

    public function testLatestFlagClearAtBindsFlagAndSourceAfterOwner(): void
    {
        $repo = $this->makeRepo([]);

        $repo->latestFlagClearAt(self::OWNER, 'email_bounced', 'email_change');

        $this->assertSame(
            [LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, '12', 'email_bounced', 'email_change'],
            $this->queries[0][1]
        );
    }

    public function testQueryErrorThrowsCarDatabaseException(): void
    {
        $repo = $this->makeRepo([], true);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('CarRepository::recentFlagChanges failed for owner=12: mock failure');
        $repo->recentFlagChanges(self::OWNER, 5);
    }

    // --- decoding ------------------------------------------------------------

    public function testRecentFlagChangesDecodesAValidRow(): void
    {
        $repo = $this->makeRepo([self::row(self::meta(), '2026-05-01 10:00:00', '3')]);

        $this->assertSame(
            [[
                'date' => '2026-05-01 10:00:00',
                'flag' => 'email_blocked',
                'old' => 1,
                'new' => 0,
                'source' => 'admin',
                'acting_user_id' => 3,
            ]],
            $repo->recentFlagChanges(self::OWNER, 10)
        );
    }

    public function testRecentFlagChangesKeepsQueryOrder(): void
    {
        $repo = $this->makeRepo([
            self::row(self::meta(['flag' => 'email_bounced']), '2026-05-03 00:00:00'),
            self::row(self::meta(['flag' => 'email_opted_out']), '2026-05-02 00:00:00'),
        ]);

        $flags = array_column($repo->recentFlagChanges(self::OWNER, 10), 'flag');

        $this->assertSame(['email_bounced', 'email_opted_out'], $flags);
    }

    /**
     * @param array<string, mixed>|string|null $metadata
     */
    #[DataProvider('skippedMetadataProvider')]
    public function testRecentFlagChangesSkipsMalformedMetadata(array|string|null $metadata): void
    {
        $repo = $this->makeRepo([self::row($metadata)]);

        $this->assertSame([], $repo->recentFlagChanges(self::OWNER, 10));
    }

    /**
     * Includes the present-but-wrong-typed values: each one must skip the row,
     * not raise a TypeError.
     *
     * @return array<string, array{array<string, mixed>|string|null}>
     */
    public static function skippedMetadataProvider(): array
    {
        return [
            'NULL metadata' => [null],
            'empty string' => [''],
            'not JSON' => ['not json {'],
            'JSON string scalar' => ['"abc"'],
            'JSON number scalar' => ['5'],
            'JSON null' => ['null'],
            'owner_id is a string' => [self::meta(['owner_id' => '12'])],
            'owner_id is a float' => [self::meta(['owner_id' => 12.5])],
            'owner_id is an array' => [self::meta(['owner_id' => [12]])],
            'owner_id is null' => [self::meta(['owner_id' => null])],
            'another owner' => [self::meta(['owner_id' => 13])],
            'owner_id missing' => [array_diff_key(self::meta(), ['owner_id' => 1])],
            'flag is an array' => [self::meta(['flag' => ['email_blocked']])],
            'flag is an int' => [self::meta(['flag' => 1])],
            'flag is unknown' => [self::meta(['flag' => 'email_unsubscribed'])],
            'source is an array' => [self::meta(['source' => ['admin']])],
            'source is an int' => [self::meta(['source' => 3])],
            'source is unknown' => [self::meta(['source' => 'cron'])],
            'old is a string' => [self::meta(['old' => '1'])],
            'old is a bool' => [self::meta(['old' => true])],
            'old is an array' => [self::meta(['old' => [1]])],
            'old is 2' => [self::meta(['old' => 2])],
            'new is a string' => [self::meta(['new' => '0'])],
            'new is null' => [self::meta(['new' => null])],
            // json_encode() writes 0.0 as 0, so the float is written as raw JSON.
            'new is a float' => ['{"owner_id":12,"flag":"email_blocked","old":1,"new":0.0,"source":"admin"}'],
            'new is -1' => [self::meta(['new' => -1])],
            'new missing' => [array_diff_key(self::meta(), ['new' => 1])],
        ];
    }

    public function testRecentFlagChangesSkipsRowWhenMetadataColumnIsNotAString(): void
    {
        $repo = $this->makeRepo([
            (object) ['id' => 1, 'user_id' => 3, 'logdate' => '2026-05-01 10:00:00', 'metadata_json' => 42],
            (object) ['id' => 2, 'user_id' => 3, 'logdate' => '2026-05-01 10:00:00', 'metadata_json' => ['a' => 1]],
            (object) ['id' => 3, 'user_id' => 3, 'logdate' => '2026-05-01 10:00:00'],
        ]);

        $this->assertSame([], $repo->recentFlagChanges(self::OWNER, 10));
    }

    public function testRecentFlagChangesKeepsValidRowsAroundSkippedRows(): void
    {
        $repo = $this->makeRepo([
            self::row('broken'),
            self::row(self::meta(['flag' => 'email_bounced']), '2026-05-02 00:00:00'),
            self::row(self::meta(['owner_id' => '12'])),
            self::row(self::meta(['flag' => 'email_opted_out']), '2026-05-01 00:00:00'),
        ]);

        $flags = array_column($repo->recentFlagChanges(self::OWNER, 10), 'flag');

        $this->assertSame(['email_bounced', 'email_opted_out'], $flags);
    }

    public function testRecentFlagChangesUsesMetadataClearedAtWhenValid(): void
    {
        $repo = $this->makeRepo([
            self::row(self::meta(['cleared_at' => '2026-04-01 08:09:10']), '2026-05-01 10:00:00'),
        ]);

        $this->assertSame('2026-04-01 08:09:10', $repo->recentFlagChanges(self::OWNER, 10)[0]['date']);
    }

    /**
     * @param mixed $clearedAt
     */
    #[DataProvider('badClearedAtProvider')]
    public function testRecentFlagChangesFallsBackToLogdateWhenClearedAtIsInvalid(mixed $clearedAt): void
    {
        $repo = $this->makeRepo([
            self::row(self::meta(['cleared_at' => $clearedAt]), '2026-05-01 10:00:00'),
        ]);

        $rows = $repo->recentFlagChanges(self::OWNER, 10);

        $this->assertCount(1, $rows, 'A bad cleared_at must not drop the row.');
        $this->assertSame('2026-05-01 10:00:00', $rows[0]['date']);
    }

    /** @return array<string, array{mixed}> */
    public static function badClearedAtProvider(): array
    {
        return [
            'array' => [['2026-04-01 08:09:10']],
            'int' => [1775000000],
            'bool' => [true],
            'null' => [null],
            'empty string' => [''],
            'not a date' => ['yesterday'],
            'impossible date' => ['2026-02-30 12:00:00'],
        ];
    }

    // --- latestFlagClearAt ----------------------------------------------------

    public function testLatestFlagClearAtReturnsNullWithoutRows(): void
    {
        $repo = $this->makeRepo([]);

        $this->assertNull($repo->latestFlagClearAt(self::OWNER, 'email_blocked', 'admin'));
    }

    public function testLatestFlagClearAtSkipsMalformedRowsAndReturnsFirstValidDate(): void
    {
        $repo = $this->makeRepo([
            self::row(null, '2026-05-05 00:00:00'),
            self::row(self::meta(['owner_id' => '12']), '2026-05-04 00:00:00'),
            self::row(self::meta(['new' => '0']), '2026-05-03 00:00:00'),
            self::row(self::meta(), '2026-05-02 00:00:00'),
            self::row(self::meta(), '2026-05-01 00:00:00'),
        ]);

        $this->assertSame('2026-05-02 00:00:00', $repo->latestFlagClearAt(self::OWNER, 'email_blocked', 'admin'));
    }

    public function testLatestFlagClearAtReturnsNullWhenEveryRowIsMalformed(): void
    {
        $repo = $this->makeRepo([self::row(null), self::row('{'), self::row(self::meta(['old' => 'x']))]);

        $this->assertNull($repo->latestFlagClearAt(self::OWNER, 'email_blocked', 'admin'));
    }
}
