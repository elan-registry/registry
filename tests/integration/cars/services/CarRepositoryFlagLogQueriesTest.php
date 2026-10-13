<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\DeliverabilityFlagLog;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2101: CarRepository::latestFlagClearAt() and recentFlagChanges() against the
 * real `logs` table.
 *
 * The rows come from DeliverabilityFlagLog::record(), the production writer,
 * so the writer and the readers are tested as a pair. Owners 12 and 123 are
 * the prefix pair from the plan: a LIKE or lognote match on "12" would also
 * find "123". The owners need no `users` row, because the readers only read
 * `logs`.
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarRepositoryFlagLogQueriesTest extends IntegrationTestCase
{
    private const OWNER_SHORT = 12;
    private const OWNER_LONG = 123;
    private const OWNER_PREFIX = 1;

    private CarRepository $repo;

    /** @var list<int> logs.id of every row this test wrote */
    private array $logIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->repo = new CarRepository($this->db);
        $this->logIds = [];

        // A row that an earlier failed run leaked would make "no row" tests
        // pass or fail for the wrong reason.
        $this->deleteFlagLogRowsOf([self::OWNER_PREFIX, self::OWNER_SHORT, self::OWNER_LONG]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            foreach ($this->logIds as $id) {
                $this->db->query('DELETE FROM logs WHERE id = ?', [$id]);
            }
        }
        parent::tearDown();
    }

    /**
     * @param list<int> $ownerIds
     */
    private function deleteFlagLogRowsOf(array $ownerIds): void
    {
        foreach ($ownerIds as $ownerId) {
            $this->db->query(
                "DELETE FROM logs
                  WHERE logtype = ?
                    AND JSON_VALID(CONVERT(metadata USING utf8mb4))
                    AND JSON_EXTRACT(CONVERT(metadata USING utf8mb4), '$.owner_id') = CAST(? AS UNSIGNED)",
                [LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, (string) $ownerId]
            );
        }
    }

    /**
     * Write one row through DeliverabilityFlagLog::record() and pin its logdate.
     *
     * @param array<string, string> $extraMetadata Merged into the metadata (for `cleared_at`)
     * @return int The logs.id of the row
     */
    private function recordRow(
        int $ownerId,
        string $flag,
        int $old,
        int $new,
        string $source,
        string $logdate,
        int $actingUserId = 5,
        array $extraMetadata = []
    ): int {
        $writtenId = 0;
        $flagLog = new DeliverabilityFlagLog(
            static function (int $userId, string $logtype, string $lognote, array $metadata) use (&$writtenId, $extraMetadata): int {
                $writtenId = (int) logger($userId, $logtype, $lognote, array_merge($metadata, $extraMetadata));

                return $writtenId;
            }
        );

        $this->assertTrue($flagLog->record($actingUserId, $ownerId, $flag, $old, $new, $source), 'Test setup: record() must write');
        $this->assertGreaterThan(0, $writtenId, 'Test setup: logger() must return the new row id');
        $this->logIds[] = $writtenId;
        $this->setLogdate($writtenId, $logdate);

        return $writtenId;
    }

    private function setLogdate(int $logId, string $logdate): void
    {
        $result = $this->db->query('UPDATE logs SET logdate = ? WHERE id = ?', [$logdate, $logId]);
        $this->assertFalse($result->error(), 'Test setup: logdate update failed: ' . $result->errorString());
    }

    /**
     * Insert a row with hand-written metadata, to test rows the writer never makes.
     */
    private function insertRawRow(?string $metadata, string $logdate): int
    {
        $result = $this->db->insert('logs', [
            'user_id' => 0,
            'logdate' => $logdate,
            'logtype' => LogCategories::LOG_CATEGORY_EMAIL_BOUNCED,
            'lognote' => 'raw fixture row',
            'ip' => '127.0.0.1',
            'metadata' => $metadata,
        ]);
        $this->assertTrue((bool) $result, 'Test setup: raw log insert failed: ' . $this->db->errorString());
        $id = (int) $this->db->lastId();
        $this->logIds[] = $id;

        return $id;
    }

    // ---- latestFlagClearAt --------------------------------------------------------------

    public function testLatestFlagClearAtReturnsEachOwnersOwnTimeForPrefixOwnerIds(): void
    {
        $this->recordRow(self::OWNER_SHORT, 'email_bounced', 1, 0, 'admin', '2026-03-01 10:00:00');
        $this->recordRow(self::OWNER_LONG, 'email_bounced', 1, 0, 'admin', '2026-04-02 11:00:00');

        $this->assertSame(
            '2026-03-01 10:00:00',
            $this->repo->latestFlagClearAt(self::OWNER_SHORT, 'email_bounced', 'admin')
        );
        $this->assertSame(
            '2026-04-02 11:00:00',
            $this->repo->latestFlagClearAt(self::OWNER_LONG, 'email_bounced', 'admin')
        );
    }

    public function testLatestFlagClearAtDoesNotMatchAnOwnerWhoseIdOnlyStartsWithTheArgument(): void
    {
        // Only owner 123 has a row. A lognote or LIKE match on "12" or "1" would find it.
        $this->recordRow(self::OWNER_LONG, 'email_bounced', 1, 0, 'admin', '2026-04-02 11:00:00');

        $this->assertNull($this->repo->latestFlagClearAt(self::OWNER_SHORT, 'email_bounced', 'admin'));
        $this->assertNull($this->repo->latestFlagClearAt(self::OWNER_PREFIX, 'email_bounced', 'admin'));
        $this->assertSame(
            '2026-04-02 11:00:00',
            $this->repo->latestFlagClearAt(self::OWNER_LONG, 'email_bounced', 'admin'),
            'Control: the row must exist and be found for its own owner'
        );
    }

    public function testLatestFlagClearAtDoesNotMatchAnOwnerWhoseIdIsALongerNumber(): void
    {
        // Only owner 12 has a row. Owner 123 must not see it.
        $this->recordRow(self::OWNER_SHORT, 'email_bounced', 1, 0, 'admin', '2026-03-01 10:00:00');

        $this->assertNull($this->repo->latestFlagClearAt(self::OWNER_LONG, 'email_bounced', 'admin'));
    }

    public function testLatestFlagClearAtReturnsNullWhenNoRowExists(): void
    {
        $this->assertNull($this->repo->latestFlagClearAt(self::OWNER_SHORT, 'email_blocked', 'admin'));
    }

    public function testLatestFlagClearAtDoesNotReturnARowWithAnotherSource(): void
    {
        $this->recordRow(self::OWNER_SHORT, 'email_bounced', 1, 0, 'email_change', '2026-05-05 05:05:05');

        $this->assertNull(
            $this->repo->latestFlagClearAt(self::OWNER_SHORT, 'email_bounced', 'admin'),
            'A clear by source email_change must not count as an admin clear'
        );
        $this->assertSame(
            '2026-05-05 05:05:05',
            $this->repo->latestFlagClearAt(self::OWNER_SHORT, 'email_bounced', 'email_change'),
            'Control: the same row must be found for its own source'
        );
    }

    public function testLatestFlagClearAtDoesNotReturnARowWithAnotherFlag(): void
    {
        $this->recordRow(self::OWNER_SHORT, 'email_blocked', 1, 0, 'admin', '2026-05-05 05:05:05');

        $this->assertNull($this->repo->latestFlagClearAt(self::OWNER_SHORT, 'email_bounced', 'admin'));
    }

    public function testLatestFlagClearAtIgnoresRowsThatSetTheFlag(): void
    {
        $this->recordRow(self::OWNER_SHORT, 'email_bounced', 0, 1, 'admin', '2026-06-06 06:06:06');

        $this->assertNull(
            $this->repo->latestFlagClearAt(self::OWNER_SHORT, 'email_bounced', 'admin'),
            'A row with new = 1 is a set, not a clear'
        );
    }

    public function testLatestFlagClearAtReturnsTheNewestClear(): void
    {
        $this->recordRow(self::OWNER_SHORT, 'email_bounced', 1, 0, 'admin', '2026-01-01 00:00:00');
        $this->recordRow(self::OWNER_SHORT, 'email_bounced', 1, 0, 'admin', '2026-02-02 00:00:00');
        $this->recordRow(self::OWNER_SHORT, 'email_bounced', 1, 0, 'admin', '2026-01-15 00:00:00');

        $this->assertSame('2026-02-02 00:00:00', $this->repo->latestFlagClearAt(self::OWNER_SHORT, 'email_bounced', 'admin'));
    }

    public function testLatestFlagClearAtPrefersMetadataClearedAtOverLogdate(): void
    {
        $this->recordRow(
            self::OWNER_SHORT,
            'email_blocked',
            1,
            0,
            'admin',
            '2026-07-07 07:07:07',
            5,
            ['cleared_at' => '2026-07-01 01:02:03']
        );

        $this->assertSame(
            '2026-07-01 01:02:03',
            $this->repo->latestFlagClearAt(self::OWNER_SHORT, 'email_blocked', 'admin')
        );
    }

    public function testLatestFlagClearAtFallsBackToLogdateWhenClearedAtIsMalformed(): void
    {
        $this->recordRow(
            self::OWNER_SHORT,
            'email_blocked',
            1,
            0,
            'admin',
            '2026-07-07 07:07:07',
            5,
            ['cleared_at' => 'not a date']
        );

        $this->assertSame(
            '2026-07-07 07:07:07',
            $this->repo->latestFlagClearAt(self::OWNER_SHORT, 'email_blocked', 'admin')
        );
    }

    public function testLatestFlagClearAtBreaksALogdateTieByHigherId(): void
    {
        $first = $this->recordRow(
            self::OWNER_SHORT,
            'email_blocked',
            1,
            0,
            'admin',
            '2026-08-08 08:08:08',
            5,
            ['cleared_at' => '2026-08-01 00:00:00']
        );
        $second = $this->recordRow(
            self::OWNER_SHORT,
            'email_blocked',
            1,
            0,
            'admin',
            '2026-08-08 08:08:08',
            5,
            ['cleared_at' => '2026-08-02 00:00:00']
        );
        $this->assertGreaterThan($first, $second, 'Test setup: the second row must have the higher id');

        $this->assertSame(
            '2026-08-02 00:00:00',
            $this->repo->latestFlagClearAt(self::OWNER_SHORT, 'email_blocked', 'admin'),
            'On a tie in logdate the row with the higher id is the later one'
        );
    }

    public function testLatestFlagClearAtDoesNotMatchAStringOwnerId(): void
    {
        $this->insertRawRow(
            '{"owner_id":"12","flag":"email_bounced","old":1,"new":0,"source":"admin"}',
            '2026-09-09 09:09:09'
        );

        $this->assertNull(
            $this->repo->latestFlagClearAt(self::OWNER_SHORT, 'email_bounced', 'admin'),
            'owner_id is an integer in the contract. The string "12" is not owner 12'
        );
    }

    public function testLatestFlagClearAtSkipsRowsWithNullOrInvalidMetadata(): void
    {
        $this->insertRawRow(null, '2026-09-09 09:09:09');
        $this->insertRawRow('this is not json', '2026-09-09 09:09:10');
        $this->recordRow(self::OWNER_SHORT, 'email_bounced', 1, 0, 'admin', '2026-09-01 00:00:00');

        $this->assertSame(
            '2026-09-01 00:00:00',
            $this->repo->latestFlagClearAt(self::OWNER_SHORT, 'email_bounced', 'admin'),
            'A row with NULL or invalid metadata must not raise an error or hide the valid row'
        );
    }

    public function testLatestFlagClearAtReadsTheLogsMetadataBlobColumn(): void
    {
        // Precondition for the whole class: logs.metadata is a BLOB, which is
        // why the readers CONVERT it. A plain JSON_EXTRACT raises error 3144.
        $type = $this->db->query(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logs' AND COLUMN_NAME = 'metadata'"
        )->first();
        $this->assertIsObject($type);
        $this->assertContains(strtolower((string) $type->DATA_TYPE), ['blob', 'mediumblob', 'longblob']);

        $this->recordRow(self::OWNER_SHORT, 'email_opted_out', 1, 0, 'owner', '2026-10-10 10:10:10');
        $this->assertSame('2026-10-10 10:10:10', $this->repo->latestFlagClearAt(self::OWNER_SHORT, 'email_opted_out', 'owner'));
    }

    public function testLatestFlagClearAtRejectsAnUnknownFlag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->latestFlagClearAt(self::OWNER_SHORT, 'email_unknown', 'admin');
    }

    public function testLatestFlagClearAtRejectsAnUnknownSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->latestFlagClearAt(self::OWNER_SHORT, 'email_bounced', 'cron');
    }

    public function testLatestFlagClearAtRejectsAnOwnerIdBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->latestFlagClearAt(0, 'email_bounced', 'admin');
    }

    // ---- recentFlagChanges --------------------------------------------------------------

    public function testRecentFlagChangesReturnsOnlyTheOwnersOwnRows(): void
    {
        $this->recordRow(self::OWNER_SHORT, 'email_bounced', 0, 1, 'admin', '2026-03-01 10:00:00', 41);
        $this->recordRow(self::OWNER_LONG, 'email_blocked', 0, 1, 'webhook', '2026-04-02 11:00:00', 0);

        $short = $this->repo->recentFlagChanges(self::OWNER_SHORT, 10);
        $long = $this->repo->recentFlagChanges(self::OWNER_LONG, 10);

        $this->assertCount(1, $short);
        $this->assertSame('email_bounced', $short[0]['flag']);
        $this->assertCount(1, $long);
        $this->assertSame('email_blocked', $long[0]['flag']);
        $this->assertSame([], $this->repo->recentFlagChanges(self::OWNER_PREFIX, 10));
    }

    public function testRecentFlagChangesReturnsDateFlagOldNewSourceAndActingUserOnly(): void
    {
        $this->recordRow(self::OWNER_SHORT, 'email_bounced', 0, 1, 'admin', '2026-03-01 10:00:00', 41);

        $rows = $this->repo->recentFlagChanges(self::OWNER_SHORT, 10);

        $this->assertSame(
            [[
                'date' => '2026-03-01 10:00:00',
                'flag' => 'email_bounced',
                'old' => 0,
                'new' => 1,
                'source' => 'admin',
                'acting_user_id' => 41,
            ]],
            $rows
        );
        $this->assertStringNotContainsString('@', json_encode($rows, JSON_THROW_ON_ERROR), 'The result holds no address');
    }

    public function testRecentFlagChangesSortsNewestFirstAndBreaksATieById(): void
    {
        $this->recordRow(self::OWNER_SHORT, 'email_opted_out', 0, 1, 'owner', '2026-01-01 00:00:00');
        $tieFirst = $this->recordRow(self::OWNER_SHORT, 'email_blocked', 0, 1, 'webhook', '2026-02-02 00:00:00');
        $tieSecond = $this->recordRow(self::OWNER_SHORT, 'email_bounced', 0, 1, 'webhook', '2026-02-02 00:00:00');
        $this->assertGreaterThan($tieFirst, $tieSecond, 'Test setup: the second tie row must have the higher id');

        $flags = array_column($this->repo->recentFlagChanges(self::OWNER_SHORT, 10), 'flag');

        $this->assertSame(['email_bounced', 'email_blocked', 'email_opted_out'], $flags);
    }

    public function testRecentFlagChangesAppliesAndClampsTheLimit(): void
    {
        $this->recordRow(self::OWNER_SHORT, 'email_opted_out', 0, 1, 'owner', '2026-01-01 00:00:00');
        $this->recordRow(self::OWNER_SHORT, 'email_opted_out', 1, 0, 'owner', '2026-01-02 00:00:00');
        $this->recordRow(self::OWNER_SHORT, 'email_opted_out', 0, 1, 'owner', '2026-01-03 00:00:00');

        $this->assertCount(2, $this->repo->recentFlagChanges(self::OWNER_SHORT, 2));
        $this->assertCount(1, $this->repo->recentFlagChanges(self::OWNER_SHORT, 0), 'A limit of 0 clamps to 1');
        $this->assertCount(1, $this->repo->recentFlagChanges(self::OWNER_SHORT, -5), 'A negative limit clamps to 1');
        $this->assertCount(3, $this->repo->recentFlagChanges(self::OWNER_SHORT, 1000000), 'A huge limit clamps to 100 and returns all 3');
    }

    public function testRecentFlagChangesSkipsMalformedRowsAndStringOwnerIds(): void
    {
        $this->insertRawRow(null, '2026-09-09 09:09:09');
        $this->insertRawRow('{broken', '2026-09-09 09:09:10');
        $this->insertRawRow('{"owner_id":"12","flag":"email_bounced","old":0,"new":1,"source":"admin"}', '2026-09-09 09:09:11');
        $this->insertRawRow('{"owner_id":12,"flag":"email_bounced","old":0,"new":1,"source":"cron"}', '2026-09-09 09:09:12');
        $this->recordRow(self::OWNER_SHORT, 'email_bounced', 0, 1, 'admin', '2026-09-01 00:00:00');

        $rows = $this->repo->recentFlagChanges(self::OWNER_SHORT, 10);

        $this->assertCount(1, $rows, 'Only the one valid row is returned');
        $this->assertSame('2026-09-01 00:00:00', $rows[0]['date']);
    }

    public function testRecentFlagChangesRejectsAnOwnerIdBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->recentFlagChanges(-1, 10);
    }
}
