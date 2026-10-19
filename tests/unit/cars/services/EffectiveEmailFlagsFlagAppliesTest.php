<?php

declare(strict_types=1);

namespace Tests\Unit\Cars\Services;

use ElanRegistry\Car\EffectiveEmailFlags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The PHP form of the "flag applies" rule (#2393). The SQL form is in
 * CarRepository::FLAG_ACTIVE_BLOCKED and FLAG_ACTIVE_BOUNCED.
 */
#[Group('fast')]
final class EffectiveEmailFlagsFlagAppliesTest extends TestCase
{
    /**
     * @return array<string, array{int, ?string, string, bool}>
     */
    public static function ruleProvider(): array
    {
        return [
            'flag 0, same address'            => [0, 'a@example.com', 'a@example.com', false],
            'flag 0, no address'              => [0, null, 'a@example.com', false],
            'flag 1, same address'            => [1, 'a@example.com', 'a@example.com', true],
            'flag 1, NULL address'            => [1, null, 'a@example.com', true],
            'flag 1, empty address'           => [1, '', 'a@example.com', true],
            'flag 1, blank address'           => [1, '   ', 'a@example.com', true],
            'flag 1, same after normalizing'  => [1, '  A@Example.COM ', 'a@example.com', true],
            'flag 1, different address'       => [1, 'old@example.com', 'a@example.com', false],
            'flag 1, empty current address'   => [1, 'a@example.com', '', false],
            'flag 1, NULL and empty current'  => [1, null, '', true],
            'flag 1, raw current address'     => [1, 'a@example.com', '  A@Example.COM ', true],
            'flag 1, raw current, other'      => [1, 'old@example.com', ' A@Example.COM', false],
        ];
    }

    #[DataProvider('ruleProvider')]
    public function test_flagApplies_returnsExpected(int $flag, ?string $address, string $current, bool $expected): void
    {
        $this->assertSame($expected, EffectiveEmailFlags::flagApplies($flag, $address, $current));
    }
}
