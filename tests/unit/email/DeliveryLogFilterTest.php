<?php

declare(strict_types=1);

use ElanRegistry\Email\DeliveryLogFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DeliveryLogFilter::fromInput (#2102).
 *
 * @see usersc/classes/Email/DeliveryLogFilter.php
 */
#[Group('fast')]
#[Group('unit')]
final class DeliveryLogFilterTest extends TestCase
{
    public function testFromInputWithNullsGivesDefaults(): void
    {
        $filter = DeliveryLogFilter::fromInput(null, null, null, null, null);

        $this->assertNull($filter->recipient);
        $this->assertNull($filter->tag);
        $this->assertSame('all', $filter->outcome);
        $this->assertSame(2, $filter->hours);
        $this->assertSame(1, $filter->page);
    }

    /**
     * @return array<string, array{mixed, string|null}>
     */
    public static function emailProvider(): array
    {
        return [
            'valid' => ['a@example.com', 'a@example.com'],
            'trimmed' => ['  a@example.com ', 'a@example.com'],
            'no at sign' => ['not-an-email', null],
            'empty' => ['', null],
            'array' => [['a@example.com'], null],
            'int' => [5, null],
            'with header injection' => ["a@example.com\r\nBcc: x@example.com", null],
        ];
    }

    #[DataProvider('emailProvider')]
    public function testFromInputValidatesEmail(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, DeliveryLogFilter::fromInput($input, null, null, null, null)->recipient);
    }

    /**
     * @return array<string, array{mixed, string|null}>
     */
    public static function tagProvider(): array
    {
        return [
            'plain' => ['verification', 'verification'],
            'trimmed' => ['  verification  ', 'verification'],
            'commas folded to spaces' => ['a,b', 'a b'],
            // raw() does not encode, so a decode here would change the tag on
            // each pager round trip.
            'entity text kept' => ['a&amp;b', 'a&amp;b'],
            'ampersand kept' => ['a&b', 'a&b'],
            'only spaces' => ['   ', null],
            'only comma' => [',', null],
            'empty' => ['', null],
            'array' => [['x'], null],
            'int' => [3, null],
        ];
    }

    #[DataProvider('tagProvider')]
    public function testFromInputNormalizesTag(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, DeliveryLogFilter::fromInput(null, $input, null, null, null)->tag);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function outcomeProvider(): array
    {
        return [
            'all' => ['all', 'all'],
            'delivered' => ['delivered', 'delivered'],
            'hard_bounce' => ['hard_bounce', 'hard_bounce'],
            'soft_bounce' => ['soft_bounce', 'soft_bounce'],
            'blocked' => ['blocked', 'blocked'],
            'unsubscribed' => ['unsubscribed', 'unsubscribed'],
            'spam_complaint' => ['spam_complaint', 'spam_complaint'],
            'none' => ['none', 'none'],
            'stale' => ['stale', 'stale'],
            'unknown' => ['bogus', 'all'],
            'event name, not outcome value' => ['spam', 'all'],
            'wrong case' => ['Delivered', 'all'],
            'array' => [['stale'], 'all'],
            'int' => [0, 'all'],
            'null' => [null, 'all'],
        ];
    }

    #[DataProvider('outcomeProvider')]
    public function testFromInputAllowsOnlyListedOutcomes(mixed $input, string $expected): void
    {
        $this->assertSame($expected, DeliveryLogFilter::fromInput(null, null, $input, null, null)->outcome);
    }

    public function testAllowedOutcomesListsAllEachEnumValueAndStale(): void
    {
        $this->assertSame(
            ['all', 'delivered', 'hard_bounce', 'soft_bounce', 'blocked', 'unsubscribed', 'spam_complaint', 'none', 'stale'],
            DeliveryLogFilter::allowedOutcomes()
        );
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function hoursProvider(): array
    {
        return [
            'lower bound' => [1, 1],
            'upper bound' => [720, 720],
            'string number' => ['24', 24],
            'zero' => [0, 2],
            'above max' => [721, 2],
            'negative' => [-5, 2],
            'text' => ['abc', 2],
            'float' => [3.5, 2],
            'float string' => ['3.5', 2],
            'array' => [[5], 2],
            'object' => [new stdClass(), 2],
            'null' => [null, 2],
        ];
    }

    #[DataProvider('hoursProvider')]
    public function testFromInputBoundsHours(mixed $input, int $expected): void
    {
        $this->assertSame($expected, DeliveryLogFilter::fromInput(null, null, null, $input, null)->hours);
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function pageProvider(): array
    {
        return [
            'one' => [1, 1],
            'large' => ['999', 999],
            'zero' => [0, 1],
            'negative' => ['-3', 1],
            'text' => ['x', 1],
            'float' => [2.0, 1],
            'array' => [[2], 1],
            'object' => [new stdClass(), 1],
            'overflow string' => ['99999999999999999999', 1],
            'null' => [null, 1],
        ];
    }

    #[DataProvider('pageProvider')]
    public function testFromInputBoundsPage(mixed $input, int $expected): void
    {
        $this->assertSame($expected, DeliveryLogFilter::fromInput(null, null, null, null, $input)->page);
    }
}
