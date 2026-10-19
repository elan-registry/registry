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

    public function testInnerWhitespaceIsKept(): void
    {
        $this->assertSame('a b@example.com', EmailAddress::normalize(' A B@example.com '));
    }
}
