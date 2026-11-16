<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2356: one data table runs through CarRepository::effectiveEmailFlags() (the
 * PHP rule) and through CarRepository::emailFlagActiveSql() (the SQL rule) in
 * a real query. Both must return the literal result in each row.
 *
 * The expected values are literals from the matching rule in the issue (a flag
 * applies when it is set and its address is empty or equals the current
 * `users.email`, both normalized with lower case and no surrounding white
 * space). They are not computed with either implementation. The white-space
 * rows prove that MySQL and PHP agree on a tab, a newline, a CRLF pair and a
 * vertical tab (`\x0B`), the characters where `LOWER(TRIM())` would differ.
 *
 * @see https://github.com/elan-registry/registry/issues/2356
 */
#[Group('integration')]
#[Group('car-verification')]
final class EffectiveEmailFlagsSharedTableTest extends IntegrationTestCase
{
    private const ADDRESS = 'owner@example.com';

    private CarRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->repo = new CarRepository($this->db);
    }

    /**
     * Rows: users.email as stored, the flag's stored address, and whether the
     * flag applies. The flag is set in every row of this table.
     *
     * @return array<string, array{0: string, 1: ?string, 2: bool}>
     */
    private static function addressRows(): array
    {
        return [
            'match'                                 => [self::ADDRESS, self::ADDRESS, true],
            'case: stored upper'                    => [self::ADDRESS, 'OWNER@EXAMPLE.COM', true],
            'case: users.email upper'               => ['OWNER@Example.com', self::ADDRESS, true],
            'users.email with leading spaces'       => ['  ' . self::ADDRESS, self::ADDRESS, true],
            'users.email with trailing spaces'      => [self::ADDRESS . '   ', self::ADDRESS, true],
            'users.email with trailing tab'         => [self::ADDRESS . "\t", self::ADDRESS, true],
            'users.email with trailing newline'     => [self::ADDRESS . "\n", self::ADDRESS, true],
            'users.email with trailing CRLF'        => [self::ADDRESS . "\r\n", self::ADDRESS, true],
            'users.email with trailing vertical tab' => [self::ADDRESS . "\x0B", self::ADDRESS, true],
            'users.email with leading tab'          => ["\t" . self::ADDRESS, self::ADDRESS, true],
            'stored address with case and spaces'   => [self::ADDRESS, "  Owner@Example.COM \t", true],
            'stored address with trailing newline'  => [self::ADDRESS, self::ADDRESS . "\n", true],
            'stored address of spaces only'         => [self::ADDRESS, '   ', true],
            'stored address of a tab only'          => [self::ADDRESS, "\t", true],
            'stored address NULL'                   => [self::ADDRESS, null, true],
            'stored address empty'                  => [self::ADDRESS, '', true],
            'mismatch'                              => [self::ADDRESS, 'other@example.com', false],
            'mismatch: prefix of the address'       => [self::ADDRESS, 'owner@example.co', false],
            'mismatch: space inside the address'    => [self::ADDRESS, 'owner @example.com', false],
            'mismatch with white space on both'     => ["\t" . self::ADDRESS . "\n", ' other@example.com ', false],
        ];
    }

    /**
     * Every address row for each of the two flags that are tied to an address.
     *
     * @return array<string, array{0: string, 1: string, 2: ?string, 3: bool}>
     */
    public static function flagAddressProvider(): array
    {
        $cases = [];
        foreach (['email_blocked', 'email_bounced'] as $flag) {
            foreach (self::addressRows() as $name => [$userEmail, $stored, $expected]) {
                $cases["{$flag}: {$name}"] = [$flag, $userEmail, $stored, $expected];
            }
        }

        return $cases;
    }

    /**
     * Create an owner with a profile, write `users.email` raw, and set one
     * flag with the stored address exactly as given.
     */
    private function createOwnerWithFlag(string $flag, string $userEmail, ?string $storedAddress, bool $flagSet = true): int
    {
        $ownerId = $this->createTestUser([], true);
        $this->setUserEmailRaw($ownerId, $userEmail);
        $this->writeFlagRaw($ownerId, $flag, $storedAddress, $flagSet);

        return $ownerId;
    }

    /**
     * Write one flag and its address column with no normalization, as a
     * writer that does not trim would.
     */
    private function writeFlagRaw(int $ownerId, string $flag, ?string $storedAddress, bool $flagSet = true): void
    {
        $this->assertContains($flag, ['email_blocked', 'email_bounced'], 'Test setup: unknown flag');
        $result = $this->db->query(
            "UPDATE profiles SET {$flag} = ?, {$flag}_address = ? WHERE user_id = ?",
            [$flagSet ? 1 : 0, $storedAddress, $ownerId]
        );
        $this->assertFalse($result->error(), 'Test setup: ' . $result->errorString());
    }

    private function methodSaysApplies(int $ownerId, string $flag): bool
    {
        $flags = $this->repo->effectiveEmailFlags($ownerId);

        return $flag === 'email_blocked' ? $flags->blocked : $flags->bounced;
    }

    /**
     * Run the predicate in a real query. The LEFT JOIN keeps an owner with no
     * profile row, as the dashboard queries do.
     */
    private function predicateSaysApplies(int $ownerId, string $flag): bool
    {
        $predicate = CarRepository::emailFlagActiveSql($flag);
        $result = $this->db->query(
            "SELECT COALESCE(({$predicate}), 0) AS active
               FROM users u
               LEFT JOIN profiles p ON p.user_id = u.id
              WHERE u.id = ?",
            [$ownerId]
        );
        $this->assertFalse($result->error(), 'The predicate must run as SQL: ' . $result->errorString());
        $this->assertSame(1, $result->count(), 'Test setup: one row for the owner');

        return (int) $result->first()->active === 1;
    }

    #[DataProvider('flagAddressProvider')]
    #[Group('fast')]
    public function testMethodAndPredicateGiveTheLiteralResult(string $flag, string $userEmail, ?string $stored, bool $expected): void
    {
        $ownerId = $this->createOwnerWithFlag($flag, $userEmail, $stored);

        $this->assertSame($expected, $this->methodSaysApplies($ownerId, $flag), 'effectiveEmailFlags()');
        $this->assertSame($expected, $this->predicateSaysApplies($ownerId, $flag), 'emailFlagActiveSql()');
    }

    /** @return array<string, array{0: string}> */
    public static function addressFlagProvider(): array
    {
        return ['email_blocked' => ['email_blocked'], 'email_bounced' => ['email_bounced']];
    }

    #[DataProvider('addressFlagProvider')]
    #[Group('fast')]
    public function testFlagNotSetNeverApplies(string $flag): void
    {
        foreach ([self::ADDRESS, null, '', 'other@example.com'] as $stored) {
            $ownerId = $this->createOwnerWithFlag($flag, self::ADDRESS, $stored, false);

            $label = var_export($stored, true);
            $this->assertFalse($this->methodSaysApplies($ownerId, $flag), "effectiveEmailFlags(), flag 0, address {$label}");
            $this->assertFalse($this->predicateSaysApplies($ownerId, $flag), "emailFlagActiveSql(), flag 0, address {$label}");
        }
    }

    #[DataProvider('addressFlagProvider')]
    #[Group('fast')]
    public function testOwnerWithNoProfileRowHasNoFlagByEitherRule(string $flag): void
    {
        $ownerId = $this->createTestUser(['email' => self::ADDRESS]);

        $flags = $this->repo->effectiveEmailFlags($ownerId);
        $this->assertFalse($flags->hasProfile);
        $this->assertFalse($flags->optedOut);
        $this->assertFalse($flags->blocked);
        $this->assertFalse($flags->bounced);
        $this->assertFalse($this->predicateSaysApplies($ownerId, $flag), 'The predicate is not true for a missing profile row');
    }
}
