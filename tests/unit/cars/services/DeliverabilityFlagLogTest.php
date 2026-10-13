<?php

declare(strict_types=1);

namespace Tests\Unit\Cars\Services;

use ElanRegistry\Car\DeliverabilityFlagLog;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * DeliverabilityFlagLog writes the audit row that CarRepository::latestFlagClearAt()
 * and recentFlagChanges() read back (#2101). No database: the logger is a closure.
 *
 * record() takes only scalar parameters, so a wrong-typed value is a PHP TypeError
 * by declaration. The structured data in this contract is the metadata array that
 * record() hands to the logger. Its shape is checked here and read back in
 * CarRepositoryFlagLogMetadataTest.
 */
#[Group('fast')]
final class DeliverabilityFlagLogTest extends TestCase
{
    /** @var list<array{int, string, string, array<string, int|string>}> */
    private array $calls = [];

    private function makeLog(): DeliverabilityFlagLog
    {
        $this->calls = [];

        return new DeliverabilityFlagLog(function (int $userId, string $logtype, string $lognote, array $metadata): int {
            $this->calls[] = [$userId, $logtype, $lognote, $metadata];

            return count($this->calls);
        });
    }

    public function testRecordWritesOneRowWhenValueChanges(): void
    {
        $log = $this->makeLog();

        $written = $log->record(7, 42, 'email_blocked', 0, 1, 'webhook');

        $this->assertTrue($written);
        $this->assertCount(1, $this->calls);
        [$userId, $logtype, $lognote, $metadata] = $this->calls[0];
        $this->assertSame(7, $userId);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, $logtype);
        $this->assertSame('Owner 42 email_blocked changed from 0 to 1 (source: webhook)', $lognote);
        $this->assertSame(
            ['owner_id' => 42, 'flag' => 'email_blocked', 'old' => 0, 'new' => 1, 'source' => 'webhook'],
            $metadata
        );
    }

    public function testRecordMetadataHoldsIntegerOwnerIdAndNoAddress(): void
    {
        $log = $this->makeLog();

        $log->record(0, 5, 'email_bounced', 1, 0, 'email_change');

        $metadata = $this->calls[0][3];
        $this->assertSame(5, $metadata['owner_id']);
        $this->assertIsInt($metadata['owner_id']);
        $this->assertIsInt($metadata['old']);
        $this->assertIsInt($metadata['new']);
        $this->assertSame(
            ['flag', 'new', 'old', 'owner_id', 'source'],
            $this->sortedKeys($metadata),
            'The metadata keys are a contract with CarRepository::recentFlagChanges().'
        );
        $this->assertStringNotContainsString('@', $this->calls[0][2]);
        $this->assertStringNotContainsString('@', (string) json_encode($metadata));
    }

    #[DataProvider('unchangedProvider')]
    public function testRecordWritesNothingWhenOldEqualsNew(int $value): void
    {
        $log = $this->makeLog();

        $this->assertFalse($log->record(1, 2, 'email_opted_out', $value, $value, 'owner'));
        $this->assertSame([], $this->calls);
    }

    /** @return array<string, array{int}> */
    public static function unchangedProvider(): array
    {
        return ['0 to 0' => [0], '1 to 1' => [1]];
    }

    public function testRecordAcceptsEveryFlagAndSource(): void
    {
        $log = $this->makeLog();

        foreach (DeliverabilityFlagLog::FLAGS as $flag) {
            foreach (DeliverabilityFlagLog::SOURCES as $source) {
                $this->assertTrue($log->record(0, 1, $flag, 0, 1, $source));
            }
        }

        $this->assertCount(count(DeliverabilityFlagLog::FLAGS) * count(DeliverabilityFlagLog::SOURCES), $this->calls);
    }

    #[DataProvider('badFlagProvider')]
    public function testRecordThrowsOnUnknownFlag(string $flag): void
    {
        $log = $this->makeLog();

        try {
            $log->record(1, 2, $flag, 0, 1, 'owner');
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown deliverability flag', $e->getMessage());
        }
        $this->assertSame([], $this->calls);
    }

    /** @return array<string, array{string}> */
    public static function badFlagProvider(): array
    {
        return [
            'unknown column' => ['email_unsubscribed'],
            'empty' => [''],
            'wrong case' => ['EMAIL_BLOCKED'],
            'padded' => [' email_blocked'],
        ];
    }

    #[DataProvider('badSourceProvider')]
    public function testRecordThrowsOnUnknownSource(string $source): void
    {
        $log = $this->makeLog();

        try {
            $log->record(1, 2, 'email_blocked', 0, 1, $source);
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown deliverability flag source', $e->getMessage());
        }
        $this->assertSame([], $this->calls);
    }

    /** @return array<string, array{string}> */
    public static function badSourceProvider(): array
    {
        return ['empty' => [''], 'unknown' => ['cron'], 'wrong case' => ['Admin']];
    }

    #[DataProvider('badOwnerIdProvider')]
    public function testRecordThrowsOnOwnerIdZeroOrNegative(int $ownerId): void
    {
        $log = $this->makeLog();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Owner ID must be more than 0');
        try {
            $log->record(1, $ownerId, 'email_blocked', 0, 1, 'owner');
        } finally {
            $this->assertSame([], $this->calls);
        }
    }

    /** @return array<string, array{int}> */
    public static function badOwnerIdProvider(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'int min' => [PHP_INT_MIN]];
    }

    public function testRecordThrowsOnNegativeActingUserId(): void
    {
        $log = $this->makeLog();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Acting user ID must be 0 or more');
        $log->record(-1, 2, 'email_blocked', 0, 1, 'owner');
    }

    public function testRecordAcceptsActingUserIdZeroForSystemChanges(): void
    {
        $log = $this->makeLog();

        $this->assertTrue($log->record(0, 2, 'email_blocked', 0, 1, 'webhook'));
        $this->assertSame(0, $this->calls[0][0]);
    }

    public function testRecordThrowsWhenTheLogInsertFails(): void
    {
        // logger() returns $db->lastId(), which is 0 after a failed insert.
        $log = new DeliverabilityFlagLog(static fn (int $userId, string $logtype, string $lognote, array $metadata): int => 0);

        try {
            $log->record(3, 42, 'email_bounced', 1, 0, 'email_change');
            $this->fail('CarDatabaseException expected');
        } catch (CarDatabaseException $e) {
            $this->assertStringContainsString('owner=42', $e->getMessage());
            $this->assertStringContainsString('flag=email_bounced', $e->getMessage());
            $this->assertStringContainsString('source=email_change', $e->getMessage());
            $this->assertStringNotContainsString('@', $e->getMessage());
        }
    }

    #[DataProvider('badValueProvider')]
    public function testRecordThrowsWhenOldOrNewIsNotZeroOrOne(int $old, int $new): void
    {
        $log = $this->makeLog();

        try {
            $log->record(1, 2, 'email_blocked', $old, $new, 'owner');
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Flag values must be 0 or 1', $e->getMessage());
        }
        $this->assertSame([], $this->calls);
    }

    /** @return array<string, array{int, int}> */
    public static function badValueProvider(): array
    {
        return [
            'new is 2' => [0, 2],
            'old is 2' => [2, 1],
            'new is -1' => [1, -1],
            'both 2 (equal values must not skip the check)' => [2, 2],
            'both -1' => [-1, -1],
        ];
    }

    public function testAssertValidFlagAndSourceAcceptEveryConstant(): void
    {
        foreach (DeliverabilityFlagLog::FLAGS as $flag) {
            DeliverabilityFlagLog::assertValidFlag($flag);
        }
        foreach (DeliverabilityFlagLog::SOURCES as $source) {
            DeliverabilityFlagLog::assertValidSource($source);
        }
        $this->assertSame('email_opted_out,email_blocked,email_bounced', implode(',', DeliverabilityFlagLog::FLAGS));
        $this->assertSame(
            'owner,admin,webhook,reconciliation,import,email_change',
            implode(',', DeliverabilityFlagLog::SOURCES)
        );
    }

    /**
     * @param array<string, mixed> $array
     * @return list<string>
     */
    private function sortedKeys(array $array): array
    {
        $keys = array_keys($array);
        sort($keys);

        return $keys;
    }
}
