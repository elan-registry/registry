<?php

declare(strict_types=1);

namespace Tests\Unit\Cars\Services;

use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\ProfileFlagValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The profile readers accept only 0 and 1 as a stored flag (#2393).
 *
 * `flagValue()` is private, so every test goes through a public reader.
 */
#[Group('fast')]
final class CarRepositoryProfileFlagValueTest extends TestCase
{
    private const USER_ID = 77;
    private const ADDRESS = 'owner@example.com';

    /**
     * A stub database that returns one profiles row for any query.
     *
     * @param array<string, mixed> $row
     */
    private function repoReturning(array $row): CarRepository
    {
        $row += [
            'id' => 5,
            'user_id' => self::USER_ID,
            'email_opted_out' => 0,
            'email_opted_out_at' => null,
            'email_blocked' => 0,
            'email_blocked_address' => self::ADDRESS,
            'email_blocked_at' => null,
            'email_blocked_cause' => null,
            'email_bounced' => 0,
            'email_bounced_address' => self::ADDRESS,
            'email_bounced_at' => null,
            'email_bounce_cleared_at' => null,
            'email_address_seen' => self::ADDRESS,
            'user_email' => self::ADDRESS,
        ];

        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturn($db);
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn((object) $row);

        return new CarRepository($db);
    }

    // ---- values the reader accepts -----------------------------------

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function acceptedValueProvider(): array
    {
        return [
            'int 0'    => [0, 0],
            'int 1'    => [1, 1],
            'string 0' => ['0', 0],
            'string 1' => ['1', 1],
        ];
    }

    #[DataProvider('acceptedValueProvider')]
    public function test_findProfileOptedOut_cleanValue_returnsInt(mixed $stored, int $expected): void
    {
        $repo = $this->repoReturning(['email_opted_out' => $stored]);

        $this->assertSame($expected, $repo->findProfileOptedOut(self::USER_ID));
    }

    #[DataProvider('acceptedValueProvider')]
    public function test_effectiveEmailFlags_cleanValue_readsOptedOutBlockedAndBounced(mixed $stored, int $expected): void
    {
        // Same stored address as users.email, so a set flag applies.
        $repo = $this->repoReturning([
            'email_opted_out' => $stored,
            'email_blocked' => $stored,
            'email_bounced' => $stored,
        ]);

        $flags = $repo->effectiveEmailFlags(self::USER_ID);

        $this->assertSame($expected === 1, $flags->optedOut);
        $this->assertSame($expected === 1, $flags->blocked);
        $this->assertSame($expected === 1, $flags->bounced);
    }

    // ---- values the reader rejects -----------------------------------

    /**
     * @return array<string, array{mixed}>
     */
    public static function rejectedValueProvider(): array
    {
        return [
            'null'         => [null],
            'string 2'     => ['2'],
            'int 2'        => [2],
            'string abc'   => ['abc'],
            'bool true'    => [true],
            'empty string' => [''],
            'int -1'       => [-1],
        ];
    }

    #[DataProvider('rejectedValueProvider')]
    public function test_findProfileOptedOut_badValue_throwsProfileFlagValueException(mixed $stored): void
    {
        $repo = $this->repoReturning(['email_opted_out' => $stored]);

        $this->expectException(ProfileFlagValueException::class);

        $repo->findProfileOptedOut(self::USER_ID);
    }

    #[DataProvider('rejectedValueProvider')]
    public function test_effectiveEmailFlags_badBlockedValue_throwsProfileFlagValueException(mixed $stored): void
    {
        $repo = $this->repoReturning(['email_blocked' => $stored]);

        $this->expectException(ProfileFlagValueException::class);

        $repo->effectiveEmailFlags(self::USER_ID);
    }

    #[DataProvider('rejectedValueProvider')]
    public function test_effectiveEmailFlags_badBouncedValue_throwsProfileFlagValueException(mixed $stored): void
    {
        $repo = $this->repoReturning(['email_bounced' => $stored]);

        $this->expectException(ProfileFlagValueException::class);

        $repo->effectiveEmailFlags(self::USER_ID);
    }

    public function test_exception_namesColumnAndUserId_andHoldsNoAddress(): void
    {
        $repo = $this->repoReturning(['email_blocked' => 2]);

        try {
            $repo->effectiveEmailFlags(self::USER_ID);
            $this->fail('Expected ProfileFlagValueException.');
        } catch (ProfileFlagValueException $e) {
            $this->assertStringContainsString('email_blocked', $e->getMessage());
            $this->assertStringContainsString('user=' . self::USER_ID, $e->getMessage());
            $this->assertStringNotContainsString(self::ADDRESS, $e->getMessage());
            $this->assertStringNotContainsString('example.com', $e->getMessage());
        }
    }

    public function test_exception_isACarDatabaseException(): void
    {
        $this->assertSame(CarDatabaseException::class, (new \ReflectionClass(ProfileFlagValueException::class))->getParentClass()->getName());
    }

    // ---- one row per reader: a stored 2 throws -----------------------

    /**
     * @return array<string, array{callable(CarRepository): mixed, array<string, mixed>, string}>
     */
    public static function readerProvider(): array
    {
        $at = '2026-10-01 12:00:00';

        return [
            'effectiveEmailFlags (opted out)' => [
                static fn (CarRepository $r) => $r->effectiveEmailFlags(self::USER_ID),
                ['email_opted_out' => 2],
                'email_opted_out',
            ],
            'effectiveEmailFlags (blocked)' => [
                static fn (CarRepository $r) => $r->effectiveEmailFlags(self::USER_ID),
                ['email_blocked' => 2],
                'email_blocked',
            ],
            'effectiveEmailFlags (bounced)' => [
                static fn (CarRepository $r) => $r->effectiveEmailFlags(self::USER_ID),
                ['email_bounced' => 2],
                'email_bounced',
            ],
            'findProfileOptedOut' => [
                static fn (CarRepository $r) => $r->findProfileOptedOut(self::USER_ID),
                ['email_opted_out' => 2],
                'email_opted_out',
            ],
            'setProfileOptedOut' => [
                static fn (CarRepository $r) => $r->setProfileOptedOut(self::USER_ID, true),
                ['email_opted_out' => 2],
                'email_opted_out',
            ],
            'setProfileBlocked' => [
                static fn (CarRepository $r) => $r->setProfileBlocked(self::USER_ID, self::ADDRESS, null, $at),
                ['email_blocked' => 2],
                'email_blocked',
            ],
            'clearProfileBlocked' => [
                static fn (CarRepository $r) => $r->clearProfileBlocked(self::USER_ID),
                ['email_blocked' => 2],
                'email_blocked',
            ],
            'setProfileBounced' => [
                static fn (CarRepository $r) => $r->setProfileBounced(self::USER_ID, self::ADDRESS, $at),
                ['email_bounced' => 2],
                'email_bounced',
            ],
            'clearProfileBounced' => [
                static fn (CarRepository $r) => $r->clearProfileBounced(self::USER_ID),
                ['email_bounced' => 2],
                'email_bounced',
            ],
            'clearFlagsForConfirmedEmailChange (bounced)' => [
                static fn (CarRepository $r) => $r->clearFlagsForConfirmedEmailChange(self::USER_ID),
                ['email_bounced' => 2],
                'email_bounced',
            ],
            'clearFlagsForConfirmedEmailChange (blocked)' => [
                static fn (CarRepository $r) => $r->clearFlagsForConfirmedEmailChange(self::USER_ID),
                ['email_blocked' => 2],
                'email_blocked',
            ],
        ];
    }

    /**
     * @param callable(CarRepository): mixed $call
     * @param array<string, mixed>           $row
     */
    #[DataProvider('readerProvider')]
    public function test_reader_storedTwo_throwsProfileFlagValueException(callable $call, array $row, string $column): void
    {
        $repo = $this->repoReturning($row);

        try {
            $call($repo);
            $this->fail('Expected ProfileFlagValueException.');
        } catch (ProfileFlagValueException $e) {
            $this->assertStringContainsString($column, $e->getMessage());
            $this->assertStringContainsString((string) self::USER_ID, $e->getMessage());
            $this->assertStringNotContainsString(self::ADDRESS, $e->getMessage());
        }
    }

    public function test_effectiveEmailFlags_noProfileRow_stillReturnsNone(): void
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturn($db);
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);

        $flags = (new CarRepository($db))->effectiveEmailFlags(self::USER_ID);

        $this->assertFalse($flags->hasProfile);
    }
}
