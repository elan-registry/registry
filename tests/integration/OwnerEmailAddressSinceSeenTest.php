<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\OwnerUpdateException;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\RecordingPassThroughDatabase;

/**
 * #2356: Owner::update() locks the profile row first, then sets
 * `email_address_since` and `email_address_seen` when the normalized address
 * changes. It clears no flag. The email_act == 0 path of user_settings.php
 * and the admin owner edit both reach this method, through
 * updateProfileAndSync() and update().
 *
 * @see https://github.com/elan-registry/registry/issues/2356
 */
#[Group('integration')]
#[Group('owner')]
final class OwnerEmailAddressSinceSeenTest extends IntegrationTestCase
{
    private const ADDRESS_A = 'a-owner@example.com';
    private const ADDRESS_B = 'b-owner@example.com';
    private const OLD_SINCE = '2020-01-02 03:04:05';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /** An owner whose users.email and email_address_seen are address A. */
    private function createOwnerAtA(): int
    {
        $userId = $this->createTestUser(['email' => self::ADDRESS_A], true);
        $this->setEmailAddressSinceSeen($userId, self::ADDRESS_A, self::OLD_SINCE);

        return $userId;
    }

    private function userEmail(int $userId): string
    {
        $row = $this->db->query('SELECT email FROM users WHERE id = ?', [$userId])->first();
        $this->assertIsObject($row);

        return (string) $row->email;
    }

    /** Whether a real SQL predicate says the flag applies, from a fresh query. */
    private function predicateSaysApplies(int $userId, string $flag): bool
    {
        $predicate = CarRepository::emailFlagActiveSql($flag);
        $result = $this->db->query(
            "SELECT COALESCE(({$predicate}), 0) AS active FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.id = ?",
            [$userId]
        );
        $this->assertFalse($result->error(), $result->errorString());

        return (int) $result->first()->active === 1;
    }

    // ---- Owner::update() sets since and seen ----------------------------------------------------

    public function testUpdateSetsSinceAndSeenWhenTheNormalizedAddressChanges(): void
    {
        $userId = $this->createOwnerAtA();

        $owner = new Owner($userId);
        $owner->update(['id' => $userId, 'email' => 'B-Owner@Example.com']);

        $this->assertSame('B-Owner@Example.com', $this->userEmail($userId), 'users.email keeps the case as entered');
        $flags = $this->getOwnerEmailFlags($userId);
        $this->assertSame(self::ADDRESS_B, $flags['email_address_seen'], 'seen holds the normalized address');
        $this->assertNotSame(self::OLD_SINCE, $flags['email_address_since'], 'since holds the time of the change');
        $this->assertGreaterThanOrEqual(
            time() - 60,
            strtotime((string) $flags['email_address_since']),
            'since is the time of this change'
        );
    }

    public function testUpdateChangesNeitherSinceNorSeenForACaseOnlyDifference(): void
    {
        $userId = $this->createOwnerAtA();

        (new Owner($userId))->update(['id' => $userId, 'email' => 'A-OWNER@EXAMPLE.COM']);

        $this->assertSame('A-OWNER@EXAMPLE.COM', $this->userEmail($userId), 'Test setup: users.email changed in case');
        $flags = $this->getOwnerEmailFlags($userId);
        $this->assertSame(self::ADDRESS_A, $flags['email_address_seen']);
        $this->assertSame(self::OLD_SINCE, $flags['email_address_since']);
    }

    public function testUpdateOfAnotherFieldTouchesNeitherSinceNorSeen(): void
    {
        $userId = $this->createOwnerAtA();

        (new Owner($userId))->update(['id' => $userId, 'fname' => 'Renamed']);

        $flags = $this->getOwnerEmailFlags($userId);
        $this->assertSame(self::ADDRESS_A, $flags['email_address_seen']);
        $this->assertSame(self::OLD_SINCE, $flags['email_address_since']);
    }

    /** The admin owner edit (process-owner-update.php) sends the unchanged email with the other fields. */
    public function testAdminEditThatSendsTheSameEmailChangesNeitherSinceNorSeen(): void
    {
        $userId = $this->createOwnerAtA();

        (new Owner($userId))->update(['id' => $userId, 'fname' => 'Renamed', 'email' => self::ADDRESS_A, 'city' => 'Portland']);

        $flags = $this->getOwnerEmailFlags($userId);
        $this->assertSame(self::ADDRESS_A, $flags['email_address_seen']);
        $this->assertSame(self::OLD_SINCE, $flags['email_address_since']);
    }

    public function testUpdateOfAnOwnerWithNoProfileRowStillWritesTheEmailAndDoesNotThrow(): void
    {
        $userId = $this->createTestUser(['email' => self::ADDRESS_A]);

        (new Owner($userId))->update(['id' => $userId, 'email' => self::ADDRESS_B]);

        $this->assertSame(self::ADDRESS_B, $this->userEmail($userId));
        $count = $this->db->query('SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?', [$userId])->first()->cnt;
        $this->assertSame(0, (int) $count, 'update() creates no profile row');
    }

    // ---- lock order and rollback ----------------------------------------------------------------

    public function testUpdateLocksTheProfileRowFirstThenWritesUsersThenProfile(): void
    {
        $userId = $this->createOwnerAtA();
        $db = new RecordingPassThroughDatabase($this->db);
        $owner = new Owner($userId, $db);
        $db->resetEvents();

        $owner->update(['id' => $userId, 'email' => self::ADDRESS_B]);

        $committedAt = array_search('commit', $db->events, true);
        $this->assertNotFalse($committedAt, 'The update commits');
        $this->assertSame(
            [
                'begin',
                'query: SELECT email_address_seen FROM profiles WHERE user_id = ? FOR UPDATE',
                'update: users [email]',
                'update: profiles [email_address_since,email_address_seen]',
                'commit',
            ],
            array_slice($db->events, 0, $committedAt + 1),
            'The lock is the first statement after BEGIN. It comes before every write.'
        );
    }

    public function testUpdateLocksTheProfileRowEvenWhenTheEmailDoesNotChange(): void
    {
        $userId = $this->createOwnerAtA();
        $db = new RecordingPassThroughDatabase($this->db);
        $owner = new Owner($userId, $db);
        $db->resetEvents();

        $owner->update(['id' => $userId, 'fname' => 'Renamed']);

        $this->assertSame('begin', $db->events[0]);
        $this->assertSame('query: SELECT email_address_seen FROM profiles WHERE user_id = ? FOR UPDATE', $db->events[1]);
        $this->assertSame('update: users [fname]', $db->events[2]);
    }

    public function testAFailedProfileWriteRollsBackTheEmailAndLeavesSinceAndSeen(): void
    {
        $userId = $this->createOwnerAtA();
        $db = new RecordingPassThroughDatabase($this->db, 'profiles');
        $owner = new Owner($userId, $db);
        $db->resetEvents();

        $thrown = null;
        try {
            $owner->update(['id' => $userId, 'email' => self::ADDRESS_B]);
        } catch (OwnerUpdateException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'A failed profile write must reach the caller');
        $this->assertContains('rollback', $db->events);
        $this->assertNotContains('commit', $db->events);
        $this->assertSame(self::ADDRESS_A, $this->userEmail($userId), 'The users.email write is rolled back');
        $flags = $this->getOwnerEmailFlags($userId);
        $this->assertSame(self::ADDRESS_A, $flags['email_address_seen']);
        $this->assertSame(self::OLD_SINCE, $flags['email_address_since']);
        $this->assertFalse($this->db->inTransaction(), 'No transaction stays open');
    }

    // ---- the email_act == 0 path: no clear, readers ignore the old flags -------------------------

    public function testDirectEmailChangeLeavesTheFlagsInTheRowAndBothReadersIgnoreThem(): void
    {
        $userId = $this->createOwnerAtA();
        $this->createTestCar($userId, ['email' => self::ADDRESS_A]);
        $this->setOwnerBounced($userId, self::ADDRESS_A, '2026-05-06 07:08:09');
        $this->setOwnerBlocked($userId, self::ADDRESS_A, '2026-04-05 06:07:08', 'spam');
        $this->setOwnerOptedOut($userId, true, '2026-03-04 05:06:07');
        $before = $this->getOwnerEmailFlags($userId);

        (new Owner($userId))->updateProfileAndSync(['email' => self::ADDRESS_B]);

        $after = $this->getOwnerEmailFlags($userId);
        foreach (
            [
                'email_bounced', 'email_bounced_address', 'email_bounced_at', 'email_bounce_cleared_at',
                'email_blocked', 'email_blocked_address', 'email_blocked_at', 'email_blocked_cause',
                'email_opted_out', 'email_opted_out_at',
            ] as $column
        ) {
            $this->assertSame($before[$column], $after[$column], "{$column} stays in the profile row");
        }
        $this->assertSame(self::ADDRESS_B, $after['email_address_seen']);
        $this->assertSame(self::OLD_SINCE, $before['email_address_since'], 'Test setup: since starts at the old time');
        $this->assertNotSame(self::OLD_SINCE, $after['email_address_since'], 'since holds the time of the change');

        $effective = (new CarRepository($this->db))->effectiveEmailFlags($userId);
        $this->assertFalse($effective->bounced, 'The method ignores the bounce for A');
        $this->assertFalse($effective->blocked, 'The method ignores the block for A');
        $this->assertTrue($effective->optedOut, 'The opt-out stays');
        $this->assertFalse($this->predicateSaysApplies($userId, 'email_bounced'), 'The predicate ignores the bounce for A');
        $this->assertFalse($this->predicateSaysApplies($userId, 'email_blocked'), 'The predicate ignores the block for A');
        $this->assertSame([], (new CarRepository($this->db))->recentFlagChanges($userId, 100), 'No clear, so no log row');
    }

    /** A to B to A through the direct path: nothing cleared, so the bounce for A applies again. */
    public function testChangeFromAToBAndBackToAMakesTheBounceForAApplyAgain(): void
    {
        $userId = $this->createOwnerAtA();
        $this->setOwnerBounced($userId, self::ADDRESS_A, '2026-05-06 07:08:09');
        $repo = new CarRepository($this->db);
        $this->assertTrue($repo->effectiveEmailFlags($userId)->bounced, 'Test setup: applies for A');

        (new Owner($userId))->update(['id' => $userId, 'email' => self::ADDRESS_B]);
        $this->assertFalse($repo->effectiveEmailFlags($userId)->bounced, 'Does not apply for B');
        $this->assertFalse($this->predicateSaysApplies($userId, 'email_bounced'));

        (new Owner($userId))->update(['id' => $userId, 'email' => self::ADDRESS_A]);
        $effective = $repo->effectiveEmailFlags($userId);
        $this->assertTrue($effective->bounced, 'Applies again for A');
        $this->assertSame(self::ADDRESS_A, $effective->bouncedAddress);
        $this->assertSame('2026-05-06 07:08:09', $effective->bouncedAt);
        $this->assertTrue($this->predicateSaysApplies($userId, 'email_bounced'));
        $this->assertSame(self::ADDRESS_A, $this->getOwnerEmailFlags($userId)['email_address_seen']);
    }

    public function testUpdateKeepsTheOptOut(): void
    {
        $userId = $this->createOwnerAtA();
        $this->setOwnerOptedOut($userId, true, '2026-03-04 05:06:07');

        (new Owner($userId))->update(['id' => $userId, 'email' => self::ADDRESS_B]);

        $flags = $this->getOwnerEmailFlags($userId);
        $this->assertSame('1', (string) $flags['email_opted_out']);
        $this->assertSame('2026-03-04 05:06:07', $flags['email_opted_out_at']);
    }

    // ---- the verifySuccess hook -----------------------------------------------------------------

    public function testTheVerifySuccessHookSetsSinceAndSeenForAConfirmedChangeAndNotForAReVerify(): void
    {
        $userId = $this->createOwnerAtA();
        $this->setUserEmailRaw($userId, self::ADDRESS_B);

        $this->runVerifySuccessHook($userId);

        $first = $this->getOwnerEmailFlags($userId);
        $this->assertSame(self::ADDRESS_B, $first['email_address_seen']);
        $this->assertNotSame(self::OLD_SINCE, $first['email_address_since']);

        $this->setEmailAddressSinceSeen($userId, self::ADDRESS_B, self::OLD_SINCE);
        $this->runVerifySuccessHook($userId);

        $second = $this->getOwnerEmailFlags($userId);
        $this->assertSame(self::ADDRESS_B, $second['email_address_seen']);
        $this->assertSame(self::OLD_SINCE, $second['email_address_since'], 'A re-verify of the same address changes neither');
    }

    private function runVerifySuccessHook(int $userId): void
    {
        $GLOBALS['verify'] = new class ($userId) {
            public function __construct(private int $userId)
            {
            }

            public function exists(): bool
            {
                return true;
            }

            public function data(): object
            {
                return (object) ['id' => $this->userId];
            }
        };
        unset($GLOBALS['__elanregistry_verify_email_synced_' . $userId]);

        try {
            (static function (): void {
                include __DIR__ . '/../../usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php';
            })();
        } finally {
            unset($GLOBALS['verify'], $GLOBALS['__elanregistry_verify_email_synced_' . $userId]);
        }
    }
}
