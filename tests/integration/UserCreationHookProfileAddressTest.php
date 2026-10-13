<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2101: usersc/scripts/during_user_creation.php records when the owner's
 * address became known (`email_address_since`) and which address it was
 * (`email_address_seen`) on the new profile row.
 *
 * usersc/join.php sends the sign-up mail BEFORE it includes this script, so
 * `email_address_since` must be the user's `join_date`, not the time of the
 * insert. The script is included in process, as join.php includes it, with
 * `email_act = 1` so that it does not try to log the user in.
 */
#[Group('integration')]
final class UserCreationHookProfileAddressTest extends IntegrationTestCase
{
    private ?int $originalEmailAct = null;
    private bool $seededEmailRow = false;

    /** @var list<int> */
    private array $eventCarIds = [];

    /** @var list<int> */
    private array $hookedUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->eventCarIds = [];
        $this->hookedUserIds = [];

        // email_act = 1 makes the script skip its automatic login.
        $exists = (int) $this->db->query('SELECT COUNT(*) AS cnt FROM email WHERE id = 1')->first()->cnt;
        if ($exists === 0) {
            $this->db->query('INSERT INTO email (id, email_act) VALUES (1, 1)');
            $this->seededEmailRow = true;
        } else {
            $this->originalEmailAct = (int) $this->db->query('SELECT email_act FROM email WHERE id = 1')->first()->email_act;
            $this->db->query('UPDATE email SET email_act = 1 WHERE id = 1');
        }
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            if ($this->seededEmailRow) {
                $this->db->query('DELETE FROM email WHERE id = 1');
            } elseif ($this->originalEmailAct !== null) {
                $this->db->query('UPDATE email SET email_act = ? WHERE id = 1', [$this->originalEmailAct]);
            }
            foreach ($this->eventCarIds as $carId) {
                $this->db->query('DELETE FROM er_email_events WHERE car_id = ?', [$carId]);
            }
            foreach ($this->hookedUserIds as $userId) {
                $this->db->query('DELETE FROM logs WHERE lognote LIKE ?', ["New user {$userId}: no coordinates%"]);
                $this->db->query(
                    "DELETE FROM logs
                      WHERE logtype = 'EmailBounced'
                        AND JSON_VALID(CONVERT(metadata USING utf8mb4))
                        AND JSON_EXTRACT(CONVERT(metadata USING utf8mb4), '$.owner_id') = CAST(? AS UNSIGNED)",
                    [(string) $userId]
                );
            }
        }
        parent::tearDown();
    }

    /**
     * Run the hook the way join.php runs it: in process, after the users row exists.
     */
    private function runUserCreationHook(int $theNewId): void
    {
        $this->hookedUserIds[] = $theNewId;
        $db = DB::getInstance();
        $hookPath = dirname(__DIR__, 2) . '/usersc/scripts/during_user_creation.php';
        $this->assertFileExists($hookPath);

        include $hookPath;
    }

    /**
     * @return object{user_id: int|string, bio: string, email_address_since: ?string, email_address_seen: ?string}
     */
    private function onlyProfileOf(int $userId): object
    {
        $rows = $this->db->query(
            'SELECT user_id, bio, email_address_since, email_address_seen FROM profiles WHERE user_id = ?',
            [$userId]
        )->results();
        $this->assertCount(1, $rows, 'The hook must leave exactly one profile row');

        return $rows[0];
    }

    public function testHookSetsAddressSinceToJoinDateAndSeenToTheNormalizedAddress(): void
    {
        $userId = $this->createTestUser(['email' => '  New-Owner@Example.COM', 'join_date' => '2026-01-02 03:04:05']);

        $this->runUserCreationHook($userId);

        $profile = $this->onlyProfileOf($userId);
        $this->assertSame(
            '2026-01-02 03:04:05',
            $profile->email_address_since,
            'since is join_date: the sign-up mail went out before this script ran'
        );
        $this->assertSame('new-owner@example.com', $profile->email_address_seen);
        $this->assertStringStartsWith('automatically added - ', $profile->bio);
    }

    public function testHookDoesNotChangeAProfileThatAlreadyExists(): void
    {
        $userId = $this->createTestUser(['email' => 'existing-owner@example.com', 'join_date' => '2026-01-02 03:04:05'], true);
        $this->db->query(
            "UPDATE profiles SET email_address_since = '2025-05-05 05:05:05', email_address_seen = 'older@example.com' WHERE user_id = ?",
            [$userId]
        );

        $this->runUserCreationHook($userId);

        $profile = $this->onlyProfileOf($userId);
        $this->assertSame('2025-05-05 05:05:05', $profile->email_address_since);
        $this->assertSame('older@example.com', $profile->email_address_seen);
    }

    public function testHardBounceAfterSignUpMarksTheNewOwnerBounced(): void
    {
        $userId = $this->createTestUser(['email' => 'signup-bounce@example.com', 'join_date' => '2026-01-02 03:04:05']);
        $this->runUserCreationHook($userId);
        $carId = $this->createTestCar($userId, ['email' => 'signup-bounce@example.com']);
        $this->eventCarIds[] = $carId;
        $profile = $this->onlyProfileOf($userId);
        $bounceTime = '2026-01-02 03:05:05';
        $this->assertLessThanOrEqual(
            strtotime($bounceTime),
            strtotime((string) $profile->email_address_since),
            'Test setup: the sign-up mail bounce happens after email_address_since'
        );
        $repo = new CarRepository($this->db);
        $applier = new EmailEventApplier($repo, new CarVerificationManager($repo));

        $applier->apply($carId, 'signup-bounce@example.com', 'hard_bounce', null, 'signup-msg-1', $bounceTime, 'webhook');

        $flags = $this->getOwnerEmailFlags($userId);
        $this->assertSame('1', (string) $flags['email_bounced']);
        $this->assertSame('signup-bounce@example.com', $flags['email_bounced_address']);
        $this->assertSame($bounceTime, $flags['email_bounced_at']);
        $this->assertSame($flags['email_address_seen'], $flags['email_bounced_address'], 'The bounce is for the address recorded at sign-up');
    }
}
