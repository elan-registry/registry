<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\BrevoWebhookEventProcessor;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\ProcessingResult;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2356: a Brevo event finds its cars through the owner's `users.email`.
 *
 * The verification send mails `users.email`. `cars.email` is only a copy and
 * can be stale. These tests run the webhook processor with the real
 * repository, so the match SQL and the flag writes run for real. The webhook,
 * the reconciliation job and the suppression sync share
 * CarRepository::findByEmail(), so the rule holds for all three.
 *
 * @see https://github.com/elan-registry/registry/issues/2356
 */
#[Group('integration')]
#[Group('car-verification')]
final class BrevoEventOwnerEmailMatchTest extends IntegrationTestCase
{
    private CarRepository $repo;
    private BrevoWebhookEventProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->repo = new CarRepository($this->db);
        $applier = new EmailEventApplier($this->repo, new CarVerificationManager($this->repo));
        $this->processor = new BrevoWebhookEventProcessor($this->repo, $applier);
    }

    /** Event rows of a user, whatever their car_id (#2358: events belong to the user). */
    private function eventRowCount(int $userId): int
    {
        return (int) $this->db->query('SELECT COUNT(*) AS n FROM er_email_events WHERE user_id = ?', [$userId])->first()->n;
    }

    /** @return array<string, mixed> */
    private function hardBounce(string $email): array
    {
        return [
            'email' => $email,
            'event' => 'hard_bounce',
            'message-id' => 'owner-match-' . uniqid(),
            'tags' => ['car_verification'],
        ];
    }

    /** An event for users.email matches the owner's car, also when the car holds a stale address. */
    public function testEventForUsersEmailMatchesTheOwnerWhoseCarHoldsAStaleCopy(): void
    {
        $current = 'current-' . uniqid() . '@example.com';
        $ownerId = $this->createTestUser(['email' => $current], true);
        $carId = $this->createTestCar($ownerId, ['email' => 'stale-' . uniqid() . '@example.com']);

        $result = $this->processor->process($this->hardBounce('  ' . strtoupper($current) . ' '));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $this->assertSame([$carId], $this->carIds($this->repo->findByEmail($current)));
        $flags = $this->getOwnerEmailFlags($ownerId);
        $this->assertSame('1', (string) $flags['email_bounced'], 'The bounce lands on the owner who holds the address');
        $this->assertTrue($this->repo->effectiveEmailFlags($ownerId)->bounced, 'The bounce applies to users.email');
        $this->assertSame(1, $this->eventRowCount($ownerId), 'One owner-level event row');
    }

    /** An event for an old cars.email copy matches no owner, so nothing is flagged. */
    public function testEventForAnOldCarEmailNoLongerMatches(): void
    {
        $old = 'old-' . uniqid() . '@example.com';
        $ownerId = $this->createTestUser(['email' => 'new-' . uniqid() . '@example.com'], true);
        $carId = $this->createTestCar($ownerId, ['email' => $old]);

        $result = $this->processor->process($this->hardBounce($old));

        $this->assertSame(ProcessingResult::NO_CAR_MATCH, $result);
        $this->assertSame([], $this->repo->findByEmail($old));
        $this->assertSame('0', (string) $this->getOwnerEmailFlags($ownerId)['email_bounced']);
        $this->assertSame(
            0,
            $this->eventRowCount($ownerId),
            'An unmatched event writes no event row'
        );
    }

    /**
     * Two owners have cars that share one cars.email value, but only one
     * owner holds it as users.email. The event flags that owner only.
     */
    public function testSharedCarEmailGivesNoCrossOwnerFlag(): void
    {
        $shared = 'shared-' . uniqid() . '@example.com';
        $holderId = $this->createTestUser(['email' => $shared], true);
        $holderCarId = $this->createTestCar($holderId, ['email' => $shared]);
        $otherId = $this->createTestUser(['email' => 'other-' . uniqid() . '@example.com'], true);
        $otherCarId = $this->createTestCar($otherId, ['email' => $shared]);

        $result = $this->processor->process($this->hardBounce($shared));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $this->assertSame([$holderCarId], $this->carIds($this->repo->findByEmail($shared)));
        $this->assertSame('1', (string) $this->getOwnerEmailFlags($holderId)['email_bounced']);
        $this->assertSame(
            '0',
            (string) $this->getOwnerEmailFlags($otherId)['email_bounced'],
            'The other owner only shares a stale car copy and must not get the flag'
        );
        $this->assertSame(0, $this->eventRowCount($otherId), 'The other owner gets no event row');
        // The control: the event was recorded, once, for the holder. Without it a count of 0 could mean a dead query.
        $this->assertSame(1, $this->eventRowCount($holderId), 'The holder gets exactly one event row');
    }

    /** One owner with two cars gets the event on both cars, whatever each car copy holds. */
    public function testEveryCarOfTheMatchedOwnerIsReturned(): void
    {
        $current = 'two-cars-' . uniqid() . '@example.com';
        $ownerId = $this->createTestUser(['email' => $current], true);
        $first = $this->createTestCar($ownerId, ['email' => $current]);
        $second = $this->createTestCar($ownerId, ['email' => '']);

        $rows = $this->repo->findByEmail($current);

        $this->assertSame([$first, $second], $this->carIds($rows));
        foreach ($rows as $row) {
            $this->assertSame($current, $row->email, 'The row holds the matched users.email');
        }
    }

    /** The noowner system account never takes an event, also when its users.email is the event address. */
    public function testNoownerAccountHoldingTheAddressIsNotMatched(): void
    {
        $noOwnerRow = $this->db->query('SELECT id, email FROM users WHERE username = ?', ['noowner'])->first();
        $this->assertIsObject($noOwnerRow, 'noowner system account missing. Run composer migrate (RegisterNoownerAccount).');
        $noOwnerId = (int) $noOwnerRow->id;
        $address = 'noowner-' . uniqid() . '@example.com';
        $flagsBefore = $this->profileFlagRow($noOwnerId);
        $carId = $this->createTestCar($noOwnerId, ['email' => $address]);

        // The shared test schema keeps the seeded account, so its email must go back.
        $this->db->query('UPDATE users SET email = ? WHERE id = ?', [$address, $noOwnerId]);
        try {
            $this->assertSame([], $this->repo->findByEmail($address));
            $this->assertSame(ProcessingResult::NO_CAR_MATCH, $this->processor->process($this->hardBounce($address)));
        } finally {
            $this->db->query('UPDATE users SET email = ? WHERE id = ?', [$noOwnerRow->email, $noOwnerId]);
        }

        $this->assertSame($flagsBefore, $this->profileFlagRow($noOwnerId), 'The noowner profile must not get a flag');
        $this->assertSame(0, $this->eventRowCount($noOwnerId), 'No event row for the noowner account');
    }

    /** A stored users.email with case and white space (a tab too) still matches the plain event address. */
    public function testStoredUsersEmailIsNormalizedForTheMatch(): void
    {
        $local = 'owner-' . uniqid();
        $ownerId = $this->createTestUser(['email' => $local . '@example.com'], true);
        $this->setUserEmailRaw($ownerId, '  ' . ucfirst($local) . "@Example.COM\t");
        $carId = $this->createTestCar($ownerId);

        $result = $this->processor->process($this->hardBounce($local . '@example.com'));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $this->assertSame([$carId], $this->carIds($this->repo->findByEmail($local . '@example.com')));
        $this->assertTrue($this->repo->effectiveEmailFlags($ownerId)->bounced, 'The bounce applies to the stored address');
    }

    /**
     * PHP trim() keeps \f and a no-break space, but the SQL wrapper removes
     * them. Such an event address must not match an owner whose email is empty.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('blankAfterSqlNormalizeProvider')]
    public function testAnAddressThatNormalizesToEmptyMatchesNoOwner(string $address): void
    {
        $ownerId = $this->createTestUser(['email' => 'empty-' . uniqid() . '@example.com'], true);
        $this->setUserEmailRaw($ownerId, '');
        $carId = $this->createTestCar($ownerId);

        $this->assertNotSame('', trim($address), 'Test setup: PHP trim() keeps this address');
        $this->assertNotContains($carId, $this->carIds($this->repo->findByEmail($address)));
        $this->assertSame(ProcessingResult::NO_CAR_MATCH, $this->processor->process($this->hardBounce($address)));
    }

    /** @return array<string, array{string}> */
    public static function blankAfterSqlNormalizeProvider(): array
    {
        return [
            'form feed'     => ["\f"],
            'no-break space' => ["\u{00A0}"],
        ];
    }

    /**
     * The flag columns of a profile row, or null when the user has none.
     *
     * @return array<string, mixed>|null
     */
    private function profileFlagRow(int $userId): ?array
    {
        $row = $this->db->query(
            'SELECT email_opted_out, email_blocked, email_blocked_address, email_bounced, email_bounced_address
               FROM profiles WHERE user_id = ?',
            [$userId]
        )->first();

        return $row === null || $row === false ? null : (array) $row;
    }

    /**
     * @param array<object> $rows
     * @return list<int>
     */
    private function carIds(array $rows): array
    {
        return array_map(static fn (object $row): int => (int) $row->id, $rows);
    }
}
