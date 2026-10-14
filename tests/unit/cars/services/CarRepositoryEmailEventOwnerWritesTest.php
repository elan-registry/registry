<?php

declare(strict_types=1);

namespace Tests\Unit\Cars\Services;

use DateTimeImmutable;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CarRepository methods that write and delete er_email_events
 * rows by user (#2358), over a recording DatabaseInterface double.
 *
 * The double shows the SQL and the bound values. Only a live database shows
 * what the SQL does, and the integration tests (CarRepositoryEmailEventsTest,
 * CarRepositoryEmailEventPruneTest) cover that.
 */
#[Group('fast')]
final class CarRepositoryEmailEventOwnerWritesTest extends TestCase
{
    /**
     * A DatabaseInterface double that records each query.
     *
     * @param list<array{sql: string, params: array<mixed>}> $queries Out: every query, in order
     * @param int|null $failOnQuery 1-based number of the query that reports an error
     * @param list<list<array<string, mixed>>> $resultsByQuery Rows that results() returns, by query number
     * @param int $count The value that count() returns
     * @param object|array<string, mixed> $firstRow The value that first() returns
     */
    private function makeRecordingDb(
        array &$queries,
        ?int $failOnQuery = null,
        array $resultsByQuery = [],
        int $count = 1,
        object|array $firstRow = [],
    ): DatabaseInterface {
        $db = $this->createStub(DatabaseInterface::class);
        $failed = false;
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($db, &$queries, $failOnQuery, &$failed) {
                $queries[] = ['sql' => $sql, 'params' => $params];
                $failed = $failOnQuery !== null && count($queries) === $failOnQuery;

                return $db;
            }
        );
        $db->method('error')->willReturnCallback(static function () use (&$failed): bool {
            return $failed;
        });
        $db->method('errorString')->willReturn('Deadlock found');
        $db->method('count')->willReturn($count);
        $db->method('first')->willReturn($firstRow);
        $db->method('results')->willReturnCallback(
            static function (bool $assoc = false) use (&$queries, $resultsByQuery): array {
                return $resultsByQuery[count($queries) - 1] ?? [];
            }
        );

        return $db;
    }

    // --- insertOwnerEmailEvent() ----------------------------------------

    /**
     * @return array<string, array{int}>
     */
    public static function nonPositiveUserIdProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'large negative' => [PHP_INT_MIN],
        ];
    }

    #[DataProvider('nonPositiveUserIdProvider')]
    public function testInsertOwnerEmailEventReturnsZeroAndIssuesNoQueryForANonPositiveUserId(int $userId): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries));

        $this->assertSame(
            0,
            $repo->insertOwnerEmailEvent($userId, 'a@example.com', 'delivered', null, 'm1', '2026-01-01 00:00:00')
        );
        $this->assertSame([], $queries);
    }

    public function testInsertOwnerEmailEventWritesOneRowWithNoCarAndTheEarliestTime(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, count: 1));

        $written = $repo->insertOwnerEmailEvent(
            42,
            '  Owner@Example.COM ',
            'hard_bounce',
            'mailbox full',
            'msg-1',
            '2026-01-01 00:00:00'
        );

        $this->assertSame(1, $written);
        $this->assertCount(1, $queries);
        $sql = $queries[0]['sql'];
        $this->assertStringContainsString('SELECT u.id, NULL,', $sql, 'The row carries the user and no car');
        $this->assertStringContainsString('occurred_at = LEAST(occurred_at, VALUES(occurred_at))', $sql);
        $this->assertStringContainsString("u.username != 'noowner'", $sql);
        $this->assertStringContainsString('WHERE u.id = ?', $sql);
        $this->assertSame(
            ['owner@example.com', 'hard_bounce', 'mailbox full', 'msg-1', '2026-01-01 00:00:00', 42],
            $queries[0]['params'],
            'The address is stored in the normalized form and the user ID is the last value'
        );
    }

    /** A user that does not exist or is `noowner` writes no row: the statement selects nothing, so count() is 0. */
    public function testInsertOwnerEmailEventReturnsTheRowCountWhenNoRowIsWritten(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, count: 0));

        $this->assertSame(
            0,
            $repo->insertOwnerEmailEvent(9, 'a@example.com', 'delivered', null, 'm1', '2026-01-01 00:00:00')
        );
    }

    public function testInsertOwnerEmailEventThrowsCarDatabaseExceptionWhenTheQueryFails(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, failOnQuery: 1));

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('insertOwnerEmailEvent failed for user=42 event=delivered: Deadlock found');

        $repo->insertOwnerEmailEvent(42, 'a@example.com', 'delivered', null, 'm1', '2026-01-01 00:00:00');
    }

    public function testInsertEmailEventStoresTheNormalizedAddressAndTheCar(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries));

        $repo->insertEmailEvent(15, ' Owner@Example.COM', 'sent', null, 'local:abc', '2026-01-01 00:00:00');

        $this->assertStringContainsString('SELECT c.user_id, c.id,', $queries[0]['sql'], 'A sent row carries its car');
        $this->assertSame(
            ['owner@example.com', 'sent', null, 'local:abc', '2026-01-01 00:00:00', 15],
            $queries[0]['params']
        );
    }

    // --- deleteEmailEventsForUser() / detachEmailEventsFromCar() ---------

    #[DataProvider('nonPositiveUserIdProvider')]
    public function testDeleteEmailEventsForUserIssuesNoDeleteForANonPositiveUserId(int $userId): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, count: 5));

        $this->assertSame(0, $repo->deleteEmailEventsForUser($userId));
        $this->assertSame([], $queries, 'A delete with a bad user ID could remove the wrong rows');
    }

    public function testDeleteEmailEventsForUserDeletesByUserIdAndReturnsTheCount(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, count: 4));

        $this->assertSame(4, $repo->deleteEmailEventsForUser(42));
        $this->assertSame('DELETE FROM er_email_events WHERE user_id = ?', $queries[0]['sql']);
        $this->assertSame([42], $queries[0]['params']);
    }

    public function testDeleteEmailEventsForUserThrowsWhenTheQueryFails(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, failOnQuery: 1));

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('deleteEmailEventsForUser failed for user=42');

        $repo->deleteEmailEventsForUser(42);
    }

    public function testDetachEmailEventsFromCarSetsCarIdToNullAndKeepsTheRows(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, count: 3));

        $this->assertSame(3, $repo->detachEmailEventsFromCar(15));
        $this->assertSame('UPDATE er_email_events SET car_id = NULL WHERE car_id = ?', $queries[0]['sql']);
        $this->assertSame([15], $queries[0]['params']);
    }

    // --- deleteSendinblueMessagesForUser() --------------------------------

    #[DataProvider('nonPositiveUserIdProvider')]
    public function testDeleteSendinblueMessagesForUserIssuesNoQueryForANonPositiveUserId(int $userId): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, count: 5));

        $this->assertSame(0, $repo->deleteSendinblueMessagesForUser($userId, ['a@example.com']));
        $this->assertSame([], $queries);
    }

    /**
     * NULL, '', white space and a repeated address are dropped, and a value
     * that is not a string is dropped. Two addresses are left, so the second
     * statement binds two values and the user ID.
     */
    public function testDeleteSendinblueMessagesForUserDropsBlankAndDuplicateAddresses(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, count: 2));

        $deleted = $repo->deleteSendinblueMessagesForUser(
            42,
            [null, '', "  \t", 'A@Example.com', ' a@example.com ', 'b@example.com', 17, ['x']]
        );

        $this->assertSame(4, $deleted, 'The count of both deletes is added');
        $this->assertCount(2, $queries);
        $this->assertSame([42], $queries[0]['params'], 'The first delete is by message ID');
        $this->assertSame(['a@example.com', 'b@example.com', 42], $queries[1]['params']);
        $this->assertSame(
            2,
            substr_count($queries[1]['sql'], 'LOWER(REGEXP_REPLACE(?,'),
            'One normalized placeholder for each kept address'
        );
        $this->assertStringContainsString('COLLATE utf8mb4_bin', $queries[1]['sql']);
    }

    /** An empty list must not produce `IN ()`, which is a syntax error. */
    public function testDeleteSendinblueMessagesForUserNeverRunsAnEmptyInList(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, count: 3));

        $deleted = $repo->deleteSendinblueMessagesForUser(42, [null, '', '   ', "\t\n"]);

        $this->assertSame(3, $deleted, 'Only the delete by message ID ran');
        $this->assertCount(1, $queries);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('IN ()', $query['sql']);
        }
    }

    public function testDeleteSendinblueMessagesForUserSkipsAddressesOfOtherUsers(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries));

        $repo->deleteSendinblueMessagesForUser(42, ['old@example.com']);

        $this->assertStringContainsString('NOT EXISTS', $queries[1]['sql']);
        $this->assertStringContainsString('u.id != ?', $queries[1]['sql']);
    }

    public function testDeleteSendinblueMessagesForUserNamesTheStepThatFailed(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, failOnQuery: 2));

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('failed to delete by address for user=42');

        $repo->deleteSendinblueMessagesForUser(42, ['a@example.com']);
    }

    // --- sendinblueMessagesTableExists() ----------------------------------

    /**
     * @return array<string, array{object, bool}>
     */
    public static function tableCountProvider(): array
    {
        return [
            'table present' => [(object) ['cnt' => '1'], true],
            'table absent' => [(object) ['cnt' => '0'], false],
            'no row' => [(object) [], false],
        ];
    }

    #[DataProvider('tableCountProvider')]
    public function testSendinblueMessagesTableExistsReadsTheInformationSchemaCount(object $row, bool $expected): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, firstRow: $row));

        $this->assertSame($expected, $repo->sendinblueMessagesTableExists());
        $this->assertSame(['plg_sendinblue_messages'], $queries[0]['params']);
    }

    public function testSendinblueMessagesTableExistsThrowsWhenTheQueryFails(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, failOnQuery: 1));

        $this->expectException(CarDatabaseException::class);

        $repo->sendinblueMessagesTableExists();
    }

    // --- lockProfileAndFindAddresses() ------------------------------------

    public function testLockProfileAndFindAddressesMergesTheThreeSourcesNormalizedAndUnique(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, resultsByQuery: [
            [['email_address_seen' => 'Seen@Example.com', 'email_bounced_address' => null, 'email_blocked_address' => '']],
            [['email' => 'seen@example.com'], ['email' => 'event@example.com']],
            [['email' => ' CAR@example.com '], ['email' => '']],
        ]));

        $addresses = $repo->lockProfileAndFindAddresses(42);

        $this->assertSame(['seen@example.com', 'event@example.com', 'car@example.com'], $addresses);
        $this->assertCount(3, $queries);
        $this->assertStringContainsString('FOR UPDATE', $queries[0]['sql'], 'The first read takes the profile lock');
        $this->assertStringContainsString('FROM profiles', $queries[0]['sql']);
        foreach ($queries as $query) {
            $this->assertSame([42], $query['params']);
        }
    }

    /** A user with no profile, no event and no car has no address: the result is an empty list. */
    public function testLockProfileAndFindAddressesReturnsAnEmptyListWhenThereIsNoAddress(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries));

        $this->assertSame([], $repo->lockProfileAndFindAddresses(42));
        $this->assertCount(3, $queries, 'All three sources are read even when the profile row is missing');
    }

    public function testLockProfileAndFindAddressesNamesTheSourceThatFailed(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, failOnQuery: 3));

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('failed to read cars for user=42');

        $repo->lockProfileAndFindAddresses(42);
    }

    // --- deleteEmailEventsOlderThan() -------------------------------------

    public function testDeleteEmailEventsOlderThanBindsTheTerminalCutoffThenTheShortCutoff(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, count: 2));

        $changed = $repo->deleteEmailEventsOlderThan(
            new DateTimeImmutable('2024-09-09 03:00:00'),
            new DateTimeImmutable('2026-06-11 03:00:00')
        );

        $this->assertSame(4, $changed, 'Deleted rows plus cleared addresses');
        $this->assertCount(2, $queries);

        $terminalCount = 6; // HARD_BOUNCE_EVENTS (4) plus SUPPRESSION_EVENTS (2)
        $deleteParams = $queries[0]['params'];
        $this->assertCount(2 * $terminalCount + 4, $deleteParams);
        $this->assertSame('2024-09-09 03:00:00', $deleteParams[$terminalCount], 'The terminal cutoff follows the first event list');
        $this->assertSame('2026-06-11 03:00:00', $deleteParams[2 * $terminalCount + 1], 'The short cutoff follows the second event list');
        $this->assertSame(['sent', 'sent'], array_slice($deleteParams, -2), 'The latest-sent subquery binds the event twice');

        $this->assertSame(['sent', '2026-06-11 03:00:00', 'sent', 'sent'], $queries[1]['params']);
        $this->assertStringContainsString("SET email = ''", $queries[1]['sql']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badCutoffOrderProvider(): array
    {
        return [
            'swapped' => ['2026-06-11 03:00:00', '2024-09-09 03:00:00'],
            'equal' => ['2026-06-11 03:00:00', '2026-06-11 03:00:00'],
        ];
    }

    /** Swapped cutoffs would delete terminal events after 90 days, so the method must refuse them. */
    #[DataProvider('badCutoffOrderProvider')]
    public function testDeleteEmailEventsOlderThanThrowsWhenTheTerminalCutoffIsNotBeforeTheShortCutoff(
        string $terminal,
        string $short
    ): void {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries));

        try {
            $repo->deleteEmailEventsOlderThan(new DateTimeImmutable($terminal), new DateTimeImmutable($short));
            $this->fail('A terminal cutoff that is not before the short cutoff must throw');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('must be before the short cutoff', $e->getMessage());
        }

        $this->assertSame([], $queries, 'Nothing may be deleted before the check');
    }

    public function testDeleteEmailEventsOlderThanThrowsWhenTheDeleteFails(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, failOnQuery: 1));

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('delete failed for cutoffs=');

        $repo->deleteEmailEventsOlderThan(
            new DateTimeImmutable('2024-09-09 03:00:00'),
            new DateTimeImmutable('2026-06-11 03:00:00')
        );
    }

    public function testDeleteEmailEventsOlderThanThrowsWhenTheAddressClearFails(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries, failOnQuery: 2));

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('address clear failed');

        $repo->deleteEmailEventsOlderThan(
            new DateTimeImmutable('2024-09-09 03:00:00'),
            new DateTimeImmutable('2026-06-11 03:00:00')
        );
    }

    // --- findLatestEmailEventsByCarIds(): malformed IDs -------------------

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function invalidCarIdsProvider(): array
    {
        return [
            'string element' => [[1, 'two']],
            'float element' => [[1, 2.5]],
            'null element' => [[1, null]],
            'bool element' => [[1, true]],
            'negative element' => [[1, -1]],
            'zero element' => [[1, 0]],
            'numeric string element' => [[1, '2']],
        ];
    }

    /** @param array<mixed> $carIds */
    #[DataProvider('invalidCarIdsProvider')]
    public function testFindLatestEmailEventsByCarIdsRejectsAMalformedIdBeforeAnyQuery(array $carIds): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries));

        try {
            $repo->findLatestEmailEventsByCarIds($carIds);
            $this->fail('A malformed car ID must throw');
        } catch (CarValidationException $e) {
            $this->assertStringContainsString('findLatestEmailEventsByCarIds', $e->getMessage());
        }

        $this->assertSame([], $queries);
    }

    /** The reader binds each id once, so a repeated id must not shift the placeholders. */
    public function testFindLatestEmailEventsByCarIdsBindsEachDistinctIdOnce(): void
    {
        $queries = [];
        $repo = new CarRepository($this->makeRecordingDb($queries));

        $repo->findLatestEmailEventsByCarIds([3, 3, 4]);

        $this->assertSame(['sent', 3, 4, 'sent'], $queries[0]['params']);
        $this->assertSame(4, substr_count($queries[0]['sql'], '?'), 'One placeholder for each bound value');
    }

    // ---- ownerIdsOf() -----------------------------------------------------------------

    public function testOwnerIdsOfReturnsDistinctOwnerIdsInFirstSeenOrder(): void
    {
        $cars = [
            (object) ['id' => 1, 'user_id' => 7],
            (object) ['id' => 2, 'user_id' => '3'],
            (object) ['id' => 3, 'user_id' => '7'],
        ];

        $this->assertSame([7, 3], CarRepository::ownerIdsOf($cars));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidOwnerRowProvider(): array
    {
        return [
            'array, not object'   => [['user_id' => 7]],
            'missing user_id'     => [(object) ['id' => 1]],
            'null user_id'        => [(object) ['user_id' => null]],
            'non-numeric user_id' => [(object) ['user_id' => 'abc']],
            'zero user_id'        => [(object) ['user_id' => 0]],
            'zero string user_id' => [(object) ['user_id' => '0']],
            'negative user_id'    => [(object) ['user_id' => -4]],
        ];
    }

    /** A bad row would otherwise cast to owner 0 and apply the event to no one. */
    #[DataProvider('invalidOwnerRowProvider')]
    public function testOwnerIdsOfThrowsForARowWithNoPositiveUserId(mixed $row): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('CarRepository::ownerIdsOf');

        CarRepository::ownerIdsOf([(object) ['user_id' => 5], $row]);
    }
}
