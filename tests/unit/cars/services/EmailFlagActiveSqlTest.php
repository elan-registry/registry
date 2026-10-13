<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EffectiveEmailFlags;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ElanRegistryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CarRepository::emailFlagActiveSql() and the shape of
 * EffectiveEmailFlags (#2356).
 *
 * The predicate is SQL text that callers put into WHERE clauses. It must
 * come from class constants only, so the argument can never reach the SQL.
 * The expected strings below are literals, written out in full and not built
 * with the code's own fragments. The SQL itself runs in the integration
 * test EffectiveEmailFlagsSharedTableTest.
 */
#[Group('fast')]
final class EmailFlagActiveSqlTest extends TestCase
{
    private const EXPECTED_BLOCKED = "p.email_blocked = 1 AND (p.email_blocked_address IS NULL"
        . " OR LOWER(REGEXP_REPLACE(p.email_blocked_address, '^[[:space:]]+|[[:space:]]+$', '')) = ''"
        . " OR LOWER(REGEXP_REPLACE(p.email_blocked_address, '^[[:space:]]+|[[:space:]]+$', ''))"
        . " = LOWER(REGEXP_REPLACE(u.email, '^[[:space:]]+|[[:space:]]+$', '')) COLLATE utf8mb4_bin)";

    private const EXPECTED_BOUNCED = "p.email_bounced = 1 AND (p.email_bounced_address IS NULL"
        . " OR LOWER(REGEXP_REPLACE(p.email_bounced_address, '^[[:space:]]+|[[:space:]]+$', '')) = ''"
        . " OR LOWER(REGEXP_REPLACE(p.email_bounced_address, '^[[:space:]]+|[[:space:]]+$', ''))"
        . " = LOWER(REGEXP_REPLACE(u.email, '^[[:space:]]+|[[:space:]]+$', '')) COLLATE utf8mb4_bin)";

    // --- The exact predicate ------------------------------------------

    public function testBlockedPredicateIsExact(): void
    {
        $this->assertSame(self::EXPECTED_BLOCKED, CarRepository::emailFlagActiveSql('email_blocked'));
    }

    public function testBouncedPredicateIsExact(): void
    {
        $this->assertSame(self::EXPECTED_BOUNCED, CarRepository::emailFlagActiveSql('email_bounced'));
    }

    public function testPredicateUsesOnlyTheProfileAndUsersAliases(): void
    {
        foreach (['email_blocked', 'email_bounced'] as $flag) {
            $sql = CarRepository::emailFlagActiveSql($flag);

            $this->assertStringStartsWith("p.{$flag} = 1 AND (", $sql);
            $this->assertStringContainsString("p.{$flag}_address IS NULL", $sql, 'A NULL address applies (fail closed)');
            $this->assertStringContainsString('u.email', $sql);
            $this->assertStringContainsString('COLLATE utf8mb4_bin', $sql, 'The compare is exact after normalization');
            $this->assertStringEndsWith(')', $sql);
            $this->assertSame(
                substr_count($sql, '('),
                substr_count($sql, ')'),
                'The predicate must have balanced parentheses, so a caller can wrap it in NOT (...)'
            );
        }
    }

    public function testEachPredicateNamesOnlyItsOwnFlag(): void
    {
        $blocked = CarRepository::emailFlagActiveSql('email_blocked');
        $bounced = CarRepository::emailFlagActiveSql('email_bounced');

        $this->assertStringNotContainsString('email_bounced', $blocked);
        $this->assertStringNotContainsString('email_blocked', $bounced);
        $this->assertStringNotContainsString('email_opted_out', $blocked . $bounced, 'An opt-out is not tied to an address');
        $this->assertNotSame($blocked, $bounced);
    }

    /** The result depends on nothing but the allowed flag name. */
    public function testPredicateIsStableAcrossCalls(): void
    {
        $first = CarRepository::emailFlagActiveSql('email_blocked');
        CarRepository::emailFlagActiveSql('email_bounced');
        $this->assertSame($first, CarRepository::emailFlagActiveSql('email_blocked'));
    }

    /**
     * Every identifier-like token in the output is one of the constant
     * fragments. A fragment built from the argument would add a new token.
     */
    public function testOutputHoldsOnlyConstantFragments(): void
    {
        $allowed = [
            'p.email_blocked', 'p.email_blocked_address', 'p.email_bounced', 'p.email_bounced_address',
            'u.email', 'AND', 'IS', 'NULL', 'OR', 'LOWER', 'REGEXP_REPLACE', 'COLLATE', 'utf8mb4_bin',
        ];
        foreach (['email_blocked', 'email_bounced'] as $flag) {
            $sql = CarRepository::emailFlagActiveSql($flag);
            $withoutRegex = str_replace("'^[[:space:]]+|[[:space:]]+\$'", '', $sql);
            preg_match_all('/[A-Za-z_][A-Za-z_0-9.]*/', $withoutRegex, $matches);

            $unexpected = array_values(array_diff(array_unique($matches[0]), $allowed));
            $this->assertSame([], $unexpected, "Unexpected token in the {$flag} predicate");
            $this->assertStringNotContainsString(';', $sql);
            $this->assertStringNotContainsString('--', $sql);
            $this->assertStringNotContainsString('/*', $sql);
        }
    }

    // --- One address for every query -----------------------------------

    /**
     * The verification send mails users.email. A car query uses the same
     * predicate as an owner query, so a flag that matches only a stale
     * cars.email copy does not apply.
     */
    public function testPredicateNeverComparesWithTheCarEmailCopy(): void
    {
        foreach (['email_blocked', 'email_bounced'] as $flag) {
            $this->assertStringNotContainsString('cars.', CarRepository::emailFlagActiveSql($flag));
        }
        $this->assertFalse(
            method_exists(CarRepository::class, 'emailFlagSendBlockingSql'),
            'A second, car-address predicate must not come back'
        );
        $this->assertFalse(method_exists(CarRepository::class, 'flagsBlockingAddress'));
    }

    // --- Rejected input -----------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedFlagProvider(): array
    {
        return [
            'opted out (no address term)' => ['email_opted_out'],
            'unknown name'                => ['email_unknown'],
            'a column that exists'        => ['email_blocked_address'],
            'injection with OR'           => ['email_blocked OR 1=1'],
            'injection with DROP'         => ['; DROP TABLE profiles'],
            'comment tail'                => ['email_blocked--'],
            'empty'                       => [''],
            'leading space'               => [' email_blocked'],
            'trailing space'              => ['email_bounced '],
            'trailing newline'            => ["email_bounced\n"],
            'upper case'                  => ['EMAIL_BLOCKED'],
            'mixed case'                  => ['Email_Bounced'],
            'NUL byte after the name'     => ["email_blocked\0"],
            'NUL byte before the name'    => ["\0email_bounced"],
            'blocked and bounced'         => ['email_blocked,email_bounced'],
            'numeric string'              => ['1'],
        ];
    }

    #[DataProvider('rejectedFlagProvider')]
    public function testAnyOtherNameThrowsAValidationException(string $flag): void
    {
        try {
            CarRepository::emailFlagActiveSql($flag);
            $this->fail('emailFlagActiveSql() must reject: ' . var_export($flag, true));
        } catch (CarValidationException $e) {
            $this->assertSame(
                self::rejectionMessage(),
                $e->getMessage(),
                'The message is a constant. It must not echo the rejected value.'
            );
            $this->assertStringContainsString('email_blocked', $e->getMessage());
            $this->assertStringContainsString('email_bounced', $e->getMessage());
        }
    }

    /** The message that a plainly unknown name gives. */
    private static function rejectionMessage(): string
    {
        try {
            CarRepository::emailFlagActiveSql('zzz');
        } catch (CarValidationException $e) {
            return $e->getMessage();
        }

        return '';
    }

    public function testRejectionIsATypedRegistryException(): void
    {
        $this->expectException(ElanRegistryException::class);
        CarRepository::emailFlagActiveSql('email_opted_out');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonStringProvider(): array
    {
        return [
            'int'    => [1],
            'null'   => [null],
            'bool'   => [true],
            'float'  => [1.5],
            'array'  => [['email_blocked']],
            'object' => [new stdClass()],
        ];
    }

    #[DataProvider('nonStringProvider')]
    public function testNonStringArgumentThrowsTypeError(mixed $flag): void
    {
        $this->expectException(TypeError::class);

        CarRepository::emailFlagActiveSql($flag);
    }

    // --- EffectiveEmailFlags ------------------------------------------

    public function testEffectiveEmailFlagsIsFinalAndReadonly(): void
    {
        $class = new ReflectionClass(EffectiveEmailFlags::class);

        $this->assertTrue($class->isFinal());
        $this->assertTrue($class->isReadOnly());
    }

    public function testEffectiveEmailFlagsCannotBeChanged(): void
    {
        $flags = EffectiveEmailFlags::none();

        $this->expectException(Error::class);
        (new ReflectionProperty(EffectiveEmailFlags::class, 'blocked'))->setValue($flags, true);
    }

    public function testNoneHasNoFlagAndNoDetail(): void
    {
        $none = EffectiveEmailFlags::none();

        $this->assertFalse($none->hasProfile);
        foreach (['optedOut', 'blocked', 'bounced'] as $flag) {
            $this->assertFalse($none->{$flag}, $flag);
        }
        foreach (['optedOutAt', 'blockedAddress', 'blockedAt', 'blockedCause', 'bouncedAddress', 'bouncedAt', 'currentEmail'] as $detail) {
            $this->assertNull($none->{$detail}, $detail);
        }
    }

    public function testDetailsAreKeptOnlyForFlagsThatApply(): void
    {
        $flags = new EffectiveEmailFlags(
            true,
            false, '2026-08-01 00:00:00',
            true, 'blocked@example.com', '2026-08-02 00:00:00', 'spam',
            false, 'bounced@example.com', '2026-08-03 00:00:00',
            'owner@example.com'
        );

        $this->assertTrue($flags->blocked);
        $this->assertSame('blocked@example.com', $flags->blockedAddress);
        $this->assertSame('2026-08-02 00:00:00', $flags->blockedAt);
        $this->assertSame('spam', $flags->blockedCause);
        $this->assertFalse($flags->optedOut);
        $this->assertNull($flags->optedOutAt, 'A flag that does not apply shows no date');
        $this->assertFalse($flags->bounced);
        $this->assertNull($flags->bouncedAddress, 'A flag that does not apply shows no old address');
        $this->assertNull($flags->bouncedAt);
        $this->assertSame('owner@example.com', $flags->currentEmail);
    }

    public function testNoProfileWinsOverEveryFlagArgument(): void
    {
        $flags = new EffectiveEmailFlags(
            false,
            true, '2026-08-01 00:00:00',
            true, 'a@example.com', '2026-08-02 00:00:00', 'spam',
            true, 'a@example.com', '2026-08-03 00:00:00',
            null
        );

        $this->assertFalse($flags->hasProfile);
        $this->assertFalse($flags->optedOut);
        $this->assertFalse($flags->blocked);
        $this->assertFalse($flags->bounced);
        $this->assertNull($flags->blockedAddress);
        $this->assertNull($flags->bouncedAt);
    }

    public function testNoProfileDropsTheCurrentEmail(): void
    {
        $flags = new EffectiveEmailFlags(false, false, null, false, null, null, null, false, null, null, 'owner@example.com');

        $this->assertNull($flags->currentEmail, 'With no profile, a reader cannot name an address');
    }
}
