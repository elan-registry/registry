<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * CarRepository::ownerBlockEventCauses() against the live database (#2500).
 *
 * The method names each cause of an address block from the owner's
 * er_email_events rows. The query is the behavior under test (event filter,
 * exact address match, time bound), so no mock is used.
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarRepositoryBlockEventCausesTest extends IntegrationTestCase
{
    private CarRepository $repo;

    private int $messageSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->repo = new CarRepository($this->db);
        $this->messageSeq = 0;
    }

    private function createOwner(): int
    {
        return $this->createTestUser(['email' => 'causes-' . uniqid() . '@example.com'], true);
    }

    /** Insert one er_email_events row. The unique key is (user, message, event), so each row gets its own message ID. */
    private function seedEvent(int $userId, string $email, string $event, string $occurredAt = '2026-10-20 10:00:00'): void
    {
        $this->messageSeq++;
        $this->db->query(
            'INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, NULL, ?, ?, NULL, ?, ?)',
            [$userId, $email, $event, '<causes-' . uniqid() . '-' . $this->messageSeq . '@test>', $occurredAt]
        );
        $this->assertFalse($this->db->error(), 'Test setup: event row: ' . $this->db->errorString());
    }

    /**
     * @param list<string> $causes
     * @return list<string>
     */
    private static function sorted(array $causes): array
    {
        sort($causes);

        return $causes;
    }

    public function testReturnsEachSuppressionEventOnceDespiteDuplicateRows(): void
    {
        $userId = $this->createOwner();
        $email = 'dup-' . uniqid() . '@example.com';
        $this->seedEvent($userId, $email, 'spam');
        $this->seedEvent($userId, $email, 'spam');
        $this->seedEvent($userId, $email, 'unsubscribed');
        $this->seedEvent($userId, $email, 'unsubscribed');

        $causes = $this->repo->ownerBlockEventCauses($userId, $email, null);

        $this->assertSame(['spam', 'unsubscribed'], self::sorted($causes));
    }

    public function testIgnoresEventsThatAreNotBlockCauses(): void
    {
        $userId = $this->createOwner();
        $email = 'other-' . uniqid() . '@example.com';
        foreach (['blocked', 'hard_bounce', 'sent', 'delivered'] as $event) {
            $this->seedEvent($userId, $email, $event);
        }

        $this->assertSame([], $this->repo->ownerBlockEventCauses($userId, $email, null));
    }

    public function testIgnoresAnotherUsersRows(): void
    {
        $userId = $this->createOwner();
        $otherUserId = $this->createOwner();
        $email = 'shared-' . uniqid() . '@example.com';
        $this->seedEvent($otherUserId, $email, 'spam');
        $this->seedEvent($userId, $email, 'unsubscribed');

        $this->assertSame(['unsubscribed'], $this->repo->ownerBlockEventCauses($userId, $email, null));
    }

    public function testIgnoresAnotherAddress(): void
    {
        $userId = $this->createOwner();
        $email = 'mine-' . uniqid() . '@example.com';
        $this->seedEvent($userId, 'old-' . uniqid() . '@example.com', 'spam');

        $this->assertSame([], $this->repo->ownerBlockEventCauses($userId, $email, null));
    }

    public function testMatchesTheAddressExactlyWithoutCaseOrAccentFolding(): void
    {
        $userId = $this->createOwner();
        $tag = uniqid();
        $this->seedEvent($userId, "jose{$tag}@example.com", 'spam');
        $this->seedEvent($userId, "José{$tag}@example.com", 'spam');
        $this->seedEvent($userId, "JOSE{$tag}@example.com", 'spam');

        // The lookup address is the plain lower-case form. Only an exact row counts.
        $this->assertSame(['spam'], $this->repo->ownerBlockEventCauses($userId, "jose{$tag}@example.com", null));

        $onlyVariants = $this->createOwner();
        $this->seedEvent($onlyVariants, "José{$tag}b@example.com", 'spam');
        $this->seedEvent($onlyVariants, "JOSE{$tag}b@example.com", 'unsubscribed');
        $this->assertSame([], $this->repo->ownerBlockEventCauses($onlyVariants, "jose{$tag}b@example.com", null));
    }

    public function testAfterBoundIsExclusiveAndNullAppliesNoBound(): void
    {
        $userId = $this->createOwner();
        $email = 'bound-' . uniqid() . '@example.com';
        $this->seedEvent($userId, $email, 'spam', '2026-10-20 10:00:00');
        $this->seedEvent($userId, $email, 'unsubscribed', '2026-10-20 10:00:01');

        $this->assertSame(
            ['unsubscribed'],
            $this->repo->ownerBlockEventCauses($userId, $email, '2026-10-20 10:00:00'),
            'A row exactly at the bound is out, a later row is in'
        );
        $this->assertSame(
            ['spam', 'unsubscribed'],
            self::sorted($this->repo->ownerBlockEventCauses($userId, $email, null))
        );
        $this->assertSame([], $this->repo->ownerBlockEventCauses($userId, $email, '2026-10-20 10:00:01'));
    }

    public function testNoUserOrNoAddressReturnsEmpty(): void
    {
        $userId = $this->createOwner();
        $email = 'guard-' . uniqid() . '@example.com';
        $this->seedEvent($userId, $email, 'spam');

        $this->assertSame([], $this->repo->ownerBlockEventCauses(0, $email, null));
        $this->assertSame([], $this->repo->ownerBlockEventCauses($userId, '', null));
        $this->assertSame([], $this->repo->ownerBlockEventCauses($userId, '   ', null));
    }
}
