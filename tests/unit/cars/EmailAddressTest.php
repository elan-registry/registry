<?php

declare(strict_types=1);

use ElanRegistry\Car\EmailAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ElanRegistry\Car\EmailAddress (issue #2358).
 *
 * Expected values are literal strings, never values that the test computes
 * with the same rule as the code under test.
 */
#[Group('fast')]
#[Group('unit')]
final class EmailAddressTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function addressProvider(): array
    {
        return [
            'null becomes empty'            => [null, ''],
            'empty stays empty'             => ['', ''],
            'whitespace only becomes empty' => [" \t\n ", ''],
            'outer whitespace is trimmed'   => ["  owner@example.com\n", 'owner@example.com'],
            'mixed case becomes lower'      => ['Owner@Example.COM', 'owner@example.com'],
            'trim and lower together'       => ["\tOwner@Example.com ", 'owner@example.com'],
            // strtolower() works on bytes, so a multibyte letter stays as it is.
            'non-ASCII letter kept as is'   => ['JOSÉ@Example.com', 'josÉ@example.com'],
        ];
    }

    #[DataProvider('addressProvider')]
    public function testNormalize(?string $address, string $expected): void
    {
        $this->assertSame($expected, EmailAddress::normalize($address));
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: bool}>
     */
    public static function changeProvider(): array
    {
        return [
            'same address'             => ['owner@example.com', 'owner@example.com', false],
            'case-only edit'           => ['owner@example.com', 'Owner@Example.COM', false],
            'white-space-only edit'    => ['owner@example.com', "  owner@example.com\n", false],
            'different address'        => ['old@example.com', 'new@example.com', true],
            'no seen address yet'      => [null, 'owner@example.com', true],
            'empty seen address'       => ['', 'owner@example.com', true],
            'new address cleared'      => ['owner@example.com', null, true],
            'both null'                => [null, null, false],
            'null and empty are equal' => [null, '', false],
        ];
    }

    #[DataProvider('changeProvider')]
    public function testIsChange(?string $seen, ?string $new, bool $expected): void
    {
        $this->assertSame($expected, EmailAddress::isChange($seen, $new));
        $this->assertSame($expected, EmailAddress::isChange($new, $seen), 'The compare is symmetric');
    }

    public function testInnerWhitespaceIsKept(): void
    {
        $this->assertSame('a b@example.com', EmailAddress::normalize(' A B@example.com '));
    }
}
