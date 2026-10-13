<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\DeliverabilityFlagLog;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\RecordingPassThroughDatabase;

/**
 * #2356: CarVerificationManager::clearFlagsForConfirmedEmailChange() against
 * real SQL. The verifySuccess hook is the only caller. The call clears
 * `email_bounced` and `email_blocked` only when the normalized `users.email`
 * differs from `email_address_seen`, and keeps `email_opted_out`.
 *
 * A confirmed change is simulated the way users/verify.php leaves the data:
 * `users.email` holds the new address and `email_address_seen` holds the old
 * one.
 *
 * @see https://github.com/elan-registry/registry/issues/2356
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarVerificationManagerConfirmedEmailChangeTest extends IntegrationTestCase
{
    private const ADDRESS_A = 'a-owner@example.com';
    private const ADDRESS_B = 'b-owner@example.com';
    private const OLD_SINCE = '2020-01-02 03:04:05';

    private CarRepository $repo;
    private CarVerificationManager $manager;

    /** @var list<int> Owners whose EmailBounced log rows tearDown deletes */
    private array $loggedOwnerIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->repo = new CarRepository($this->db);
        $this->manager = new CarVerificationManager($this->repo);
        $this->loggedOwnerIds = [];
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            foreach ($this->loggedOwnerIds as $ownerId) {
                $this->db->query(
                    'DELETE FROM logs WHERE logtype = ? AND user_id = ?',
                    [LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, $ownerId]
                );
            }
        }
        parent::tearDown();
    }

    /**
     * An owner after a confirmed change: users.email is B, seen is A.
     */
    private function createOwnerWithConfirmedChange(): int
    {
        $ownerId = $this->createTestUser(['email' => self::ADDRESS_B], true);
        $this->loggedOwnerIds[] = $ownerId;
        $this->setEmailAddressSinceSeen($ownerId, self::ADDRESS_A, self::OLD_SINCE);

        return $ownerId;
    }

    /**
     * @return list<array<string, mixed>> Flag-change metadata, oldest first
     */
    private function flagLogMetadata(int $ownerId): array
    {
        $rows = $this->db->query(
            "SELECT CONVERT(metadata USING utf8mb4) AS metadata_json
               FROM logs
              WHERE logtype = ?
                AND JSON_VALID(CONVERT(metadata USING utf8mb4))
                AND JSON_EXTRACT(CONVERT(metadata USING utf8mb4), '$.owner_id') = CAST(? AS UNSIGNED)
              ORDER BY id",
            [LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, (string) $ownerId]
        )->results();

        return array_map(
            static fn (object $r): array => json_decode((string) $r->metadata_json, true, 512, JSON_THROW_ON_ERROR),
            $rows
        );
    }

    /**
     * @return list<string> Anomaly log notes for the owner, oldest first
     */
    private function anomalyNotes(int $ownerId): array
    {
        $rows = $this->db->query(
            "SELECT lognote FROM logs WHERE logtype = ? AND user_id = ? AND lognote LIKE '%no recorded address%' ORDER BY id",
            [LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, $ownerId]
        )->results();

        return array_map(static fn (object $r): string => (string) $r->lognote, $rows);
    }

    private function logRow(int $ownerId, string $flag): array
    {
        return [
            'owner_id' => $ownerId,
            'flag'     => $flag,
            'old'      => 1,
            'new'      => 0,
            'source'   => DeliverabilityFlagLog::SOURCE_EMAIL_CHANGE,
        ];
    }

    // ---- clears ---------------------------------------------------------------------------------

    public function testConfirmedChangeClearsTheBounceAndSetsTheClearTime(): void
    {
        $ownerId = $this->createOwnerWithConfirmedChange();
        $this->setOwnerBounced($ownerId, self::ADDRESS_A, '2026-05-06 07:08:09');

        $changed = $this->manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);

        $this->assertTrue($changed);
        $flags = $this->getOwnerEmailFlags($ownerId);
        $this->assertSame('0', (string) $flags['email_bounced']);
        $this->assertNull($flags['email_bounced_address']);
        $this->assertNull($flags['email_bounced_at']);
        $this->assertNotNull($flags['email_bounce_cleared_at']);
        $this->assertGreaterThanOrEqual(time() - 60, strtotime((string) $flags['email_bounce_cleared_at']));
        $this->assertSame([$this->logRow($ownerId, 'email_bounced')], $this->flagLogMetadata($ownerId));
    }

    public function testConfirmedChangeClearsTheBlockWithAddressDateAndCauseAndLogsEmailChange(): void
    {
        $ownerId = $this->createOwnerWithConfirmedChange();
        $this->setOwnerBlocked($ownerId, self::ADDRESS_A, '2026-04-05 06:07:08', 'spam');

        $changed = $this->manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);

        $this->assertTrue($changed);
        $flags = $this->getOwnerEmailFlags($ownerId);
        $this->assertSame('0', (string) $flags['email_blocked']);
        $this->assertNull($flags['email_blocked_address']);
        $this->assertNull($flags['email_blocked_at']);
        $this->assertNull($flags['email_blocked_cause']);
        $this->assertSame([$this->logRow($ownerId, 'email_blocked')], $this->flagLogMetadata($ownerId));
    }

    public function testBothFlagsClearAndWriteTwoLogRowsWhileTheOptOutStays(): void
    {
        $ownerId = $this->createOwnerWithConfirmedChange();
        $this->setOwnerBounced($ownerId, self::ADDRESS_A, '2026-05-06 07:08:09');
        $this->setOwnerBlocked($ownerId, self::ADDRESS_A, '2026-04-05 06:07:08', 'unsubscribed');
        $this->setOwnerOptedOut($ownerId, true, '2026-03-04 05:06:07');

        $changed = $this->manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);

        $this->assertTrue($changed);
        $flags = $this->getOwnerEmailFlags($ownerId);
        $this->assertSame('1', (string) $flags['email_opted_out'], 'The opt-out is kept');
        $this->assertSame('2026-03-04 05:06:07', $flags['email_opted_out_at']);
        $this->assertSame(self::ADDRESS_B, $flags['email_address_seen']);
        $this->assertNotSame(self::OLD_SINCE, $flags['email_address_since']);
        $this->assertSame(
            [$this->logRow($ownerId, 'email_bounced'), $this->logRow($ownerId, 'email_blocked')],
            $this->flagLogMetadata($ownerId),
            'One row per flag that went from 1 to 0, and none for the opt-out'
        );
        $this->assertFalse($this->db->inTransaction(), 'The method commits its own transaction');
    }

    public function testActingUserOfTheLogRowIsTheCaller(): void
    {
        $ownerId = $this->createOwnerWithConfirmedChange();
        $this->setOwnerBounced($ownerId, self::ADDRESS_A);

        $this->manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);

        $changes = $this->repo->recentFlagChanges($ownerId, 10);
        $this->assertCount(1, $changes);
        $this->assertSame($ownerId, $changes[0]['acting_user_id']);
        $this->assertSame('email_change', $changes[0]['source']);
    }

    public function testOptOutAloneChangesNoFlagButStillRecordsTheNewAddress(): void
    {
        $ownerId = $this->createOwnerWithConfirmedChange();
        $this->setOwnerOptedOut($ownerId);

        $changed = $this->manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);

        $this->assertFalse($changed, 'No flag went from 1 to 0');
        $flags = $this->getOwnerEmailFlags($ownerId);
        $this->assertSame('1', (string) $flags['email_opted_out']);
        $this->assertSame(self::ADDRESS_B, $flags['email_address_seen']);
        $this->assertSame([], $this->flagLogMetadata($ownerId));
    }

    // ---- no change ------------------------------------------------------------------------------

    public function testReVerifyOfTheRecordedAddressClearsNothing(): void
    {
        $ownerId = $this->createTestUser(['email' => self::ADDRESS_B], true);
        $this->loggedOwnerIds[] = $ownerId;
        $this->setEmailAddressSinceSeen($ownerId, self::ADDRESS_B, self::OLD_SINCE);
        $this->setOwnerBounced($ownerId, self::ADDRESS_A, '2026-05-06 07:08:09');
        $this->setOwnerBlocked($ownerId, 'no-match@example.com', '2026-04-05 06:07:08', 'spam');
        $before = $this->getOwnerEmailFlags($ownerId);

        $changed = $this->manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);

        $this->assertFalse($changed);
        $this->assertSame($before, $this->getOwnerEmailFlags($ownerId), 'Flags for another address stay. since and seen stay.');
        $this->assertSame([], $this->flagLogMetadata($ownerId));
        $this->assertFalse($this->db->inTransaction());
    }

    public function testMixedCaseAndSpacesOfTheRecordedAddressIsNoChange(): void
    {
        $ownerId = $this->createTestUser(['email' => '  A-Owner@Example.COM' . "\t"], true);
        $this->loggedOwnerIds[] = $ownerId;
        $this->setEmailAddressSinceSeen($ownerId, self::ADDRESS_A, self::OLD_SINCE);
        $this->setOwnerBounced($ownerId, self::ADDRESS_B);
        $before = $this->getOwnerEmailFlags($ownerId);

        $changed = $this->manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);

        $this->assertFalse($changed);
        $this->assertSame($before, $this->getOwnerEmailFlags($ownerId));
        $this->assertSame([], $this->flagLogMetadata($ownerId));
    }

    /**
     * A to B through Owner::update() sets seen to B. A re-verify of B then
     * clears nothing, so the stored flag for A stays and applies again after a
     * change back to A.
     */
    public function testChangeFromAToBThenReVerifyOfBKeepsTheFlagForAAndItAppliesAgainBackAtA(): void
    {
        $ownerId = $this->createTestUser(['email' => self::ADDRESS_A], true);
        $this->loggedOwnerIds[] = $ownerId;
        $this->setEmailAddressSinceSeen($ownerId, self::ADDRESS_A, self::OLD_SINCE);
        $this->setOwnerBounced($ownerId, self::ADDRESS_A, '2026-05-06 07:08:09');

        (new Owner($ownerId))->update(['id' => $ownerId, 'email' => self::ADDRESS_B]);
        $this->assertSame(self::ADDRESS_B, $this->getOwnerEmailFlags($ownerId)['email_address_seen'], 'Test setup: update() sets seen');
        $this->assertFalse($this->repo->effectiveEmailFlags($ownerId)->bounced, 'Test setup: the flag for A does not apply at B');

        $changed = $this->manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);

        $this->assertFalse($changed, 'The re-verify of B is no change');
        $flags = $this->getOwnerEmailFlags($ownerId);
        $this->assertSame('1', (string) $flags['email_bounced'], 'The stored flag for A stays');
        $this->assertSame(self::ADDRESS_A, $flags['email_bounced_address']);
        $this->assertSame([], $this->flagLogMetadata($ownerId));

        (new Owner($ownerId))->update(['id' => $ownerId, 'email' => self::ADDRESS_A]);
        $this->assertTrue($this->repo->effectiveEmailFlags($ownerId)->bounced, 'The flag for A applies again');
    }

    /**
     * The upstream admin user form changes users.email and runs no hook. So
     * an edit there leaves seen at A. The first verifySuccess of B counts as a change. The
     * second clears nothing.
     */
    public function testAdminFormEditLeavesSeenAtAAndTheFirstVerifyOfBClearsAndTheSecondDoesNot(): void
    {
        $ownerId = $this->createTestUser(['email' => self::ADDRESS_A], true);
        $this->loggedOwnerIds[] = $ownerId;
        $this->setEmailAddressSinceSeen($ownerId, self::ADDRESS_A, self::OLD_SINCE);
        $this->setOwnerBounced($ownerId, self::ADDRESS_A, '2026-05-06 07:08:09');
        $this->setOwnerBlocked($ownerId, self::ADDRESS_A, '2026-04-05 06:07:08', 'blocklist');
        $this->setUserEmailRaw($ownerId, self::ADDRESS_B);
        $this->assertSame(self::ADDRESS_A, $this->getOwnerEmailFlags($ownerId)['email_address_seen'], 'Test setup: seen still holds A');

        $first = $this->manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);

        $this->assertTrue($first, 'The first verify of B counts as a change');
        $afterFirst = $this->getOwnerEmailFlags($ownerId);
        $this->assertSame('0', (string) $afterFirst['email_bounced']);
        $this->assertSame('0', (string) $afterFirst['email_blocked']);
        $this->assertSame(self::ADDRESS_B, $afterFirst['email_address_seen']);
        $this->assertNotSame(self::OLD_SINCE, $afterFirst['email_address_since']);
        $this->assertCount(2, $this->flagLogMetadata($ownerId));

        // The flags are set again for B, and a second verify of B clears nothing.
        $this->setOwnerBounced($ownerId, self::ADDRESS_B, '2026-06-07 08:09:10');
        $stateBeforeSecond = $this->getOwnerEmailFlags($ownerId);

        $second = $this->manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);

        $this->assertFalse($second, 'The second verify of B is no change');
        $this->assertSame($stateBeforeSecond, $this->getOwnerEmailFlags($ownerId));
        $this->assertCount(2, $this->flagLogMetadata($ownerId), 'No new log row');
    }

    public function testOwnerWithNoProfileRowReturnsFalseWritesNothingAndCommits(): void
    {
        $ownerId = $this->createTestUser(['email' => self::ADDRESS_B]);
        $this->loggedOwnerIds[] = $ownerId;

        $changed = $this->manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);

        $this->assertFalse($changed);
        $this->assertSame([], $this->flagLogMetadata($ownerId));
        $this->assertFalse($this->db->inTransaction(), 'The transaction ends');
        $count = $this->db->query('SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?', [$ownerId])->first()->cnt;
        $this->assertSame(0, (int) $count, 'No profile row is created');
    }

    // ---- no-address anomaly ---------------------------------------------------------------------

    /** @return array<string, array{0: ?string}> */
    public static function missingAddressProvider(): array
    {
        return ['NULL address' => [null], 'empty address' => ['']];
    }

    #[DataProvider('missingAddressProvider')]
    public function testAnomalyIsLoggedForABounceAndABlockClearedWithNoAddress(?string $stored): void
    {
        $ownerId = $this->createOwnerWithConfirmedChange();
        $this->setOwnerBounced($ownerId, 'placeholder@example.com');
        $this->setOwnerBlocked($ownerId, 'placeholder@example.com', null, 'spam');
        $this->db->query(
            'UPDATE profiles SET email_bounced_address = ?, email_blocked_address = ? WHERE user_id = ?',
            [$stored, $stored, $ownerId]
        );

        $this->manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);

        $notes = $this->anomalyNotes($ownerId);
        $this->assertCount(2, $notes, 'One anomaly row per flag');
        $this->assertStringContainsString('email_bounced=1', $notes[0]);
        $this->assertStringContainsString('email_blocked=1', $notes[1]);
        foreach ($notes as $note) {
            $this->assertStringContainsString("user {$ownerId}", $note);
            $this->assertStringNotContainsString('@', $note, 'The note holds the user ID and the flag name, not an address');
        }
        $this->assertCount(2, $this->flagLogMetadata($ownerId), 'The flag-change rows are written as well');
    }

    public function testAnomalyIsLoggedForTheBlockAlone(): void
    {
        $ownerId = $this->createOwnerWithConfirmedChange();
        $this->setOwnerBlocked($ownerId, 'placeholder@example.com', null, 'spam');
        $this->db->query('UPDATE profiles SET email_blocked_address = NULL WHERE user_id = ?', [$ownerId]);

        $this->manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);

        $notes = $this->anomalyNotes($ownerId);
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('email_blocked=1', $notes[0]);
    }

    public function testNoAnomalyIsLoggedForAFlagWithAnAddressOrWhenNothingChanges(): void
    {
        $withAddress = $this->createOwnerWithConfirmedChange();
        $this->setOwnerBounced($withAddress, self::ADDRESS_A);
        $this->setOwnerBlocked($withAddress, self::ADDRESS_A);
        $this->manager->clearFlagsForConfirmedEmailChange($withAddress, $withAddress);
        $this->assertSame([], $this->anomalyNotes($withAddress), 'A flag with an address is no anomaly');

        $noChange = $this->createTestUser(['email' => self::ADDRESS_B], true);
        $this->loggedOwnerIds[] = $noChange;
        $this->setOwnerBounced($noChange, 'placeholder@example.com');
        $this->db->query('UPDATE profiles SET email_bounced_address = NULL WHERE user_id = ?', [$noChange]);
        $this->manager->clearFlagsForConfirmedEmailChange($noChange, $noChange);
        $this->assertSame([], $this->anomalyNotes($noChange), 'Nothing was cleared, so nothing is logged');
        $this->assertSame('1', (string) $this->getOwnerEmailFlags($noChange)['email_bounced']);
    }

    // ---- lock order and rollback ----------------------------------------------------------------

    public function testTheProfileRowIsLockedBeforeAnyWriteInTheTransaction(): void
    {
        $ownerId = $this->createOwnerWithConfirmedChange();
        $this->setOwnerBounced($ownerId, self::ADDRESS_A);
        $this->setOwnerBlocked($ownerId, self::ADDRESS_A);
        $db = new RecordingPassThroughDatabase($this->db);
        $manager = new CarVerificationManager(new CarRepository($db));

        $manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);

        $events = $db->events;
        $this->assertSame('begin', $events[0]);
        $this->assertStringStartsWith('query: SELECT ', $events[1]);
        $this->assertStringContainsString(
            'FROM profiles p STRAIGHT_JOIN users u ON u.id = p.user_id',
            $events[1],
            'STRAIGHT_JOIN locks profiles before users, the same order as Owner::update()'
        );
        $this->assertStringEndsWith('FOR UPDATE OF p, u', $events[1], 'The profile and users row lock is the first statement after BEGIN');
        $writes = array_values(array_filter($events, static fn (string $e): bool => str_starts_with($e, 'query: UPDATE profiles')));
        $this->assertCount(3, $writes, 'Bounce clear, block clear, and since and seen');
        $this->assertStringContainsString('email_bounced = 0', $writes[0]);
        $this->assertStringContainsString('email_blocked = 0', $writes[1]);
        $this->assertStringContainsString('email_address_since = ?, email_address_seen = ?', $writes[2]);
        $this->assertSame('commit', end($events), 'The transaction ends with a commit');
        $this->assertSame(1, count(array_filter($events, static fn (string $e): bool => $e === 'begin')));
    }

    public function testNoChangeStillTakesTheLockAndWritesNothing(): void
    {
        $ownerId = $this->createTestUser(['email' => self::ADDRESS_B], true);
        $this->loggedOwnerIds[] = $ownerId;
        $db = new RecordingPassThroughDatabase($this->db);
        $manager = new CarVerificationManager(new CarRepository($db));

        $manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);

        $this->assertSame('begin', $db->events[0]);
        $this->assertStringEndsWith('FOR UPDATE OF p, u', $db->events[1]);
        $this->assertSame(
            [],
            array_values(array_filter($db->events, static fn (string $e): bool => str_starts_with($e, 'query: UPDATE'))),
            'A re-verify writes nothing except the lock query'
        );
        $this->assertSame('commit', end($db->events));
    }

    public function testAFailedWriteRollsBackEveryChange(): void
    {
        $ownerId = $this->createOwnerWithConfirmedChange();
        $this->setOwnerBounced($ownerId, self::ADDRESS_A, '2026-05-06 07:08:09');
        $this->setOwnerBlocked($ownerId, self::ADDRESS_A, '2026-04-05 06:07:08', 'spam');
        $before = $this->getOwnerEmailFlags($ownerId);
        $db = new RecordingPassThroughDatabase($this->db, null, 'email_address_since');
        $manager = new CarVerificationManager(new CarRepository($db));

        $thrown = null;
        try {
            $manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);
        } catch (CarDatabaseException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'A failed write reaches the caller (the hook logs it)');
        $this->assertContains('rollback', $db->events);
        $this->assertNotContains('commit', $db->events);
        $this->assertSame($before, $this->getOwnerEmailFlags($ownerId), 'The bounce and block clears are rolled back');
        $this->assertSame([], $this->flagLogMetadata($ownerId));
        $this->assertSame([], $this->anomalyNotes($ownerId));
        $this->assertFalse($this->db->inTransaction());
    }

    public function testAFailedLogRowRollsBackTheClear(): void
    {
        $ownerId = $this->createOwnerWithConfirmedChange();
        $this->setOwnerBounced($ownerId, self::ADDRESS_A, '2026-05-06 07:08:09');
        $before = $this->getOwnerEmailFlags($ownerId);
        $failingLog = new DeliverabilityFlagLog(static fn (int $u, string $t, string $n, array $m): int => 0);
        $manager = new CarVerificationManager($this->repo, $failingLog);

        $this->expectException(CarDatabaseException::class);
        try {
            $manager->clearFlagsForConfirmedEmailChange($ownerId, $ownerId);
        } finally {
            $this->assertSame($before, $this->getOwnerEmailFlags($ownerId), 'The clear is rolled back with the log row');
            $this->assertFalse($this->db->inTransaction());
        }
    }
}
