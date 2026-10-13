<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EffectiveEmailFlags;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PassThroughDatabase;

/**
 * #2356: CarRepository::effectiveEmailFlags() against real rows, with the
 * detail fields. The address-match table for both flags and the predicate is in
 * EffectiveEmailFlagsSharedTableTest. This file checks what the value object
 * holds for each case.
 *
 * @see https://github.com/elan-registry/registry/issues/2356
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarRepositoryEffectiveEmailFlagsTest extends IntegrationTestCase
{
    private const EMAIL = 'flags-owner@example.com';

    private CarRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->repo = new CarRepository($this->db);
    }

    private function createOwner(string $email = self::EMAIL): int
    {
        return $this->createTestUser(['email' => $email], true);
    }

    public function testMatchingBlockAndBounceApplyAndHoldTheirDetails(): void
    {
        $ownerId = $this->createOwner();
        $this->setOwnerBlocked($ownerId, self::EMAIL, '2026-04-05 06:07:08', 'unsubscribed');
        $this->setOwnerBounced($ownerId, self::EMAIL, '2026-05-06 07:08:09');
        $this->setOwnerOptedOut($ownerId, true, '2026-03-04 05:06:07');

        $flags = $this->repo->effectiveEmailFlags($ownerId);

        $this->assertTrue($flags->hasProfile);
        $this->assertTrue($flags->optedOut);
        $this->assertSame('2026-03-04 05:06:07', $flags->optedOutAt);
        $this->assertTrue($flags->blocked);
        $this->assertSame(self::EMAIL, $flags->blockedAddress);
        $this->assertSame('2026-04-05 06:07:08', $flags->blockedAt);
        $this->assertSame('unsubscribed', $flags->blockedCause);
        $this->assertTrue($flags->bounced);
        $this->assertSame(self::EMAIL, $flags->bouncedAddress);
        $this->assertSame('2026-05-06 07:08:09', $flags->bouncedAt);
        $this->assertSame(self::EMAIL, $flags->currentEmail);
    }

    public function testOwnerWithNoFlagHasNoneApplyAndNoDetails(): void
    {
        $ownerId = $this->createOwner();

        $flags = $this->repo->effectiveEmailFlags($ownerId);

        $this->assertTrue($flags->hasProfile);
        $this->assertFalse($flags->optedOut);
        $this->assertFalse($flags->blocked);
        $this->assertFalse($flags->bounced);
        $this->assertNull($flags->optedOutAt);
        $this->assertNull($flags->blockedAddress);
        $this->assertNull($flags->blockedCause);
        $this->assertNull($flags->bouncedAddress);
        $this->assertSame(self::EMAIL, $flags->currentEmail);
    }

    public function testFlagsForAnotherAddressDoNotApplyAndLeakNoDetails(): void
    {
        $ownerId = $this->createOwner();
        $this->setOwnerBlocked($ownerId, 'old@example.com', '2026-04-05 06:07:08', 'spam');
        $this->setOwnerBounced($ownerId, 'old@example.com', '2026-05-06 07:08:09');
        $this->setOwnerOptedOut($ownerId, true, '2026-03-04 05:06:07');

        $flags = $this->repo->effectiveEmailFlags($ownerId);

        $this->assertFalse($flags->blocked);
        $this->assertNull($flags->blockedAddress, 'The old address does not show');
        $this->assertNull($flags->blockedAt);
        $this->assertNull($flags->blockedCause);
        $this->assertFalse($flags->bounced);
        $this->assertNull($flags->bouncedAddress);
        $this->assertNull($flags->bouncedAt);
        $this->assertTrue($flags->optedOut, 'The opt-out applies whatever the address');
        $this->assertSame('2026-03-04 05:06:07', $flags->optedOutAt);
        $this->assertSame(
            ['1', 'old@example.com', 'spam'],
            [
                (string) $this->getOwnerEmailFlags($ownerId)['email_blocked'],
                $this->getOwnerEmailFlags($ownerId)['email_blocked_address'],
                $this->getOwnerEmailFlags($ownerId)['email_blocked_cause'],
            ],
            'The reader does not write. The flag stays in the row.'
        );
    }

    /**
     * A flag with no stored address applies (fail closed). The detail address
     * is null, and currentEmail holds the address that the flag applies to.
     *
     * @return array<string, array{0: ?string}>
     */
    public static function missingAddressProvider(): array
    {
        return [
            'NULL address'              => [null],
            'empty address'             => [''],
            'white-space-only address' => [" \t "],
        ];
    }

    #[DataProvider('missingAddressProvider')]
    public function testFlagWithMissingAddressApplies(?string $stored): void
    {
        $ownerId = $this->createOwner('current-' . uniqid() . '@example.com');
        $this->setOwnerBlocked($ownerId, 'placeholder@example.com', '2026-04-05 06:07:08', null);
        $this->setOwnerBounced($ownerId, 'placeholder@example.com', '2026-05-06 07:08:09');
        $this->db->query(
            'UPDATE profiles SET email_blocked_address = ?, email_bounced_address = ? WHERE user_id = ?',
            [$stored, $stored, $ownerId]
        );

        $flags = $this->repo->effectiveEmailFlags($ownerId);

        $this->assertTrue($flags->blocked);
        $this->assertTrue($flags->bounced);
        $this->assertNull($flags->blockedCause, 'Test setup: the cause is unknown');
        $this->assertSame($stored, $flags->blockedAddress, 'The stored address is returned as stored');
        $this->assertSame($stored, $flags->bouncedAddress);
        $this->assertStringStartsWith('current-', (string) $flags->currentEmail, 'A reader can name the current address');
    }

    /**
     * A users.email with a trailing tab or newline equals the stored address.
     *
     * @return array<string, array{0: string}>
     */
    public static function whiteSpaceEmailProvider(): array
    {
        return [
            'trailing tab'     => ["\t"],
            'trailing newline' => ["\n"],
            'trailing CRLF'    => ["\r\n"],
            'trailing spaces'  => ['  '],
        ];
    }

    #[DataProvider('whiteSpaceEmailProvider')]
    public function testUsersEmailWithTrailingWhiteSpaceStillMatches(string $suffix): void
    {
        $ownerId = $this->createOwner();
        $this->setOwnerBlocked($ownerId, self::EMAIL);
        $this->setOwnerBounced($ownerId, self::EMAIL);
        $this->setUserEmailRaw($ownerId, self::EMAIL . $suffix);

        $flags = $this->repo->effectiveEmailFlags($ownerId);

        $this->assertTrue($flags->blocked);
        $this->assertTrue($flags->bounced);
        $this->assertSame(self::EMAIL . $suffix, $flags->currentEmail, 'currentEmail is the stored value, not normalized');
    }

    public function testAdminEditOfUsersEmailTurnsTheFlagsOffAndBackOn(): void
    {
        $ownerId = $this->createOwner();
        $this->setOwnerBlocked($ownerId, self::EMAIL, '2026-04-05 06:07:08', 'spam');
        $this->setOwnerBounced($ownerId, self::EMAIL, '2026-05-06 07:08:09');

        $this->setUserEmailRaw($ownerId, 'b-address@example.com');
        $afterEdit = $this->repo->effectiveEmailFlags($ownerId);
        $this->assertFalse($afterEdit->blocked);
        $this->assertFalse($afterEdit->bounced);
        $this->assertSame('b-address@example.com', $afterEdit->currentEmail);

        $this->setUserEmailRaw($ownerId, strtoupper(self::EMAIL));
        $back = $this->repo->effectiveEmailFlags($ownerId);
        $this->assertTrue($back->blocked);
        $this->assertTrue($back->bounced);
        $this->assertSame('2026-04-05 06:07:08', $back->blockedAt);
    }

    public function testOwnerWithNoProfileRowGetsNoneWithNoCurrentEmail(): void
    {
        $ownerId = $this->createTestUser(['email' => self::EMAIL]);

        $flags = $this->repo->effectiveEmailFlags($ownerId);

        $this->assertEquals(EffectiveEmailFlags::none(), $flags);
        $this->assertFalse($flags->hasProfile, 'A caller must treat this as do not mail');
        $this->assertFalse($flags->optedOut);
        $this->assertFalse($flags->blocked);
        $this->assertFalse($flags->bounced);
        $this->assertNull($flags->currentEmail);
    }

    public function testUnknownUserGetsNone(): void
    {
        $flags = $this->repo->effectiveEmailFlags(2147483000);

        $this->assertFalse($flags->hasProfile);
    }

    public function testFlagsOfAnotherOwnerAreNotRead(): void
    {
        $ownerId = $this->createOwner();
        $otherId = $this->createOwner('other-' . uniqid() . '@example.com');
        $this->setOwnerBlocked($otherId, $this->getOtherEmail($otherId));
        $this->setOwnerOptedOut($otherId);

        $flags = $this->repo->effectiveEmailFlags($ownerId);

        $this->assertFalse($flags->blocked);
        $this->assertFalse($flags->optedOut);
    }

    public function testEffectiveEmailFlagsReadsWithOneQueryAndNoLock(): void
    {
        $ownerId = $this->createOwner();
        $this->setOwnerBlocked($ownerId, self::EMAIL);
        $recording = new class ($this->db) extends PassThroughDatabase {
            /** @var list<string> */
            public array $sql = [];

            public function query(string $sql, array $params = []): static
            {
                $this->sql[] = $sql;

                return parent::query($sql, $params);
            }
        };

        $flags = (new CarRepository($recording))->effectiveEmailFlags($ownerId);

        $this->assertTrue($flags->blocked);
        $this->assertCount(1, $recording->sql, 'One query reads profile and users together');
        $this->assertStringNotContainsStringIgnoringCase('FOR UPDATE', $recording->sql[0], 'A reader takes no lock');
    }

    public function testEffectiveEmailFlagsThrowsCarDatabaseExceptionOnADatabaseError(): void
    {
        $failing = new class ($this->db) extends PassThroughDatabase {
            public function query(string $sql, array $params = []): static
            {
                return $this->simulateFailure('simulated failure');
            }
        };

        $this->expectException(CarDatabaseException::class);
        (new CarRepository($failing))->effectiveEmailFlags(1);
    }

    private function getOtherEmail(int $ownerId): string
    {
        $row = $this->db->query('SELECT email FROM users WHERE id = ?', [$ownerId])->first();
        $this->assertIsObject($row);

        return (string) $row->email;
    }
}
