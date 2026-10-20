<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EmailAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Parity of the SQL address rule and `EmailAddress::normalize()`.
 *
 * `CarRepository` normalizes an address in SQL with
 * `LOWER(REGEXP_REPLACE(col, '^[[:space:]]+|[[:space:]]+$', ''))`.
 * PHP uses `strtolower(trim())`. The two rules agree for ASCII text with
 * outer white space from `[ \t\n\r\x0B]`. They differ for `\0`, `\f`,
 * Unicode spaces and non-ASCII letters.
 *
 * This test records the result of each rule for a fixed set of addresses.
 * Expected values are literals, not computed. A change to either rule
 * fails a test, so the change cannot go unseen.
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarRepositoryAddressRuleParityTest extends IntegrationTestCase
{
    private const SQL_RULE = "LOWER(REGEXP_REPLACE(?, '^[[:space:]]+|[[:space:]]+\$', ''))";

    /**
     * Each case: raw address, PHP result, SQL result.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function ruleCases(): array
    {
        return [
            'agree: plain lower case' => ['jim@example.com', 'jim@example.com', 'jim@example.com'],
            'agree: upper case ASCII' => ['Jim@Example.COM', 'jim@example.com', 'jim@example.com'],
            'agree: outer space, tab, LF, CR, VT' => [
                " \t\n\r\x0Bjim@example.com \t\n\r\x0B",
                'jim@example.com',
                'jim@example.com',
            ],
            'differ: leading NUL' => ["\0jim@example.com", 'jim@example.com', "\0jim@example.com"],
            'differ: trailing NUL' => ["jim@example.com\0", 'jim@example.com', "jim@example.com\0"],
            'differ: leading form feed' => ["\fjim@example.com", "\fjim@example.com", 'jim@example.com'],
            'differ: trailing form feed' => ["jim@example.com\f", "jim@example.com\f", 'jim@example.com'],
            'differ: no-break space' => [
                "\u{00A0}jim@example.com\u{00A0}",
                "\u{00A0}jim@example.com\u{00A0}",
                'jim@example.com',
            ],
            'differ: em space' => ["\u{2003}jim@example.com", "\u{2003}jim@example.com", 'jim@example.com'],
            'differ: ideographic space' => ["jim@example.com\u{3000}", "jim@example.com\u{3000}", 'jim@example.com'],
            'differ: non-ASCII upper case letter' => ["J\u{00DC}RGEN@example.com", "j\u{00DC}rgen@example.com", "j\u{00FC}rgen@example.com"],
            'agree: non-ASCII lower case letter' => ["j\u{00FC}rgen@example.com", "j\u{00FC}rgen@example.com", "j\u{00FC}rgen@example.com"],
        ];
    }

    #[DataProvider('ruleCases')]
    public function test_normalize_fixedAddress_returnsKnownPhpResult(string $raw, string $php, string $sql): void
    {
        $this->assertSame(bin2hex($php), bin2hex(EmailAddress::normalize($raw)));
    }

    #[DataProvider('ruleCases')]
    public function test_sqlRule_fixedAddress_returnsKnownSqlResult(string $raw, string $php, string $sql): void
    {
        $this->requireDatabase();
        $row = $this->db->query('SELECT ' . self::SQL_RULE . ' AS v', [$raw])->first();
        $this->assertIsObject($row);
        $this->assertSame(bin2hex($sql), bin2hex((string) $row->v));
    }

    /**
     * findUserIdsByAddress() selects with SQL, then keeps a user only when the
     * PHP rule gives the same value for the stored and the given address.
     * So a match needs both rules to agree on the stored address and the
     * given address.
     *
     * @return array<string, array{string, string, bool}> stored, given, matches
     */
    public static function findCases(): array
    {
        return [
            'case and outer white space' => ['Jim@Example.com', "  jim@EXAMPLE.com\t", true],
            'stored value with outer white space' => [" jim@example.com\n", 'jim@example.com', true],
            'stored NUL, given plain' => ["jim@example.com\0", 'jim@example.com', false],
            'stored plain, given NUL' => ['jim@example.com', "\0jim@example.com\0", true],
            'stored form feed, given plain' => ["\fjim@example.com", 'jim@example.com', false],
            'stored plain, given form feed' => ['jim@example.com', "jim@example.com\f", false],
            'stored form feed, given form feed' => ["\fjim@example.com", "\fjim@example.com", true],
            'stored no-break space, given plain' => ["jim@example.com\u{00A0}", 'jim@example.com', false],
            'stored plain, given no-break space' => ['jim@example.com', "\u{00A0}jim@example.com", false],
            'stored em space, given em space' => ["\u{2003}jim@example.com", "\u{2003}jim@example.com", true],
            'non-ASCII upper, given lower' => ["J\u{00DC}RGEN@example.com", "j\u{00FC}rgen@example.com", false],
            'non-ASCII lower, given upper' => ["j\u{00FC}rgen@example.com", "J\u{00DC}RGEN@example.com", false],
            'non-ASCII same bytes' => ["J\u{00DC}RGEN@example.com", "J\u{00DC}RGEN@example.com", true],
            'non-ASCII lower, same bytes' => ["j\u{00FC}rgen@example.com", "j\u{00FC}rgen@example.com", true],
            'different address' => ['jim@example.com', 'tim@example.com', false],
        ];
    }

    #[DataProvider('findCases')]
    public function test_findUserIdsByAddress_fixedAddress_matchesKnownResult(string $stored, string $given, bool $matches): void
    {
        $this->requireDatabase();
        $userId = $this->createTestUser();
        $this->setUserEmailRaw($userId, $stored);

        $found = (new CarRepository($this->db))->findUserIdsByAddress($given);

        $this->assertSame($matches ? [$userId] : [], $found);
    }
}
