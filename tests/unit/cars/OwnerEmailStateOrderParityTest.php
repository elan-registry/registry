<?php

declare(strict_types=1);

namespace Tests\Unit\Cars;

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EffectiveEmailFlags;
use ElanRegistry\Car\EmailNoticeBuilder;
use ElanRegistry\Car\MailKind;
use ElanRegistry\Car\SendPolicy;
use ElanRegistry\Car\VerificationTabStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Three views name the owner's email state: the verification send reason,
 * the account notice, and the admin Status chip. Three issues wrote three
 * orders, and each order was pinned only by its own test (#2461). This test
 * feeds the same flags to all three and requires the same answer. The chip
 * gets the same flags: each one is true only when it applies to the owner's
 * current address, as the `_active` columns give it to the Verification tab.
 *
 * The order is: blocked, bounced, opted out.
 */
#[Group('fast')]
final class OwnerEmailStateOrderParityTest extends TestCase
{
    private const OWNER_ID = 7;
    private const CURRENT  = 'current@example.com';

    private const REASON_KINDS = [
        'Owner email is blocked.'                      => 'blocked',
        'Owner email bounced.'                         => 'bounced',
        'Owner has opted out of verification emails.'  => 'opted_out',
    ];

    /**
     * @return array<string, array{0: bool, 1: bool, 2: bool, 3: string|null, 4: string}>
     *         blocked, bounced, opted out, latest chip event, expected kind
     */
    public static function stateProvider(): array
    {
        return [
            'opted out only'        => [false, false, true, null, 'opted_out'],
            'blocked and opted out' => [true, false, true, null, 'blocked'],
            'bounced and opted out' => [false, true, true, null, 'bounced'],
            'blocked and bounced'   => [true, true, false, null, 'blocked'],
            'all three'             => [true, true, true, null, 'blocked'],
            // An opted-out owner gets no new sends, so the latest event can be
            // a hard bounce at an address the owner no longer uses. That bounce
            // flag does not apply, so effectiveEmailFlags() gives bounced = false.
            'old hard bounce at a previous address and opted out'
                                    => [false, false, true, 'hard_bounce', 'opted_out'],
        ];
    }

    #[DataProvider('stateProvider')]
    public function testAllThreeViewsAgreeOnTheOrder(
        bool $blocked,
        bool $bounced,
        bool $optedOut,
        ?string $eventName,
        string $expected
    ): void {
        // The addresses equal currentEmail, so the block and the bounce apply.
        $flags = new EffectiveEmailFlags(
            hasProfile: true,
            optedOut: $optedOut,
            optedOutAt: null,
            blocked: $blocked,
            blockedAddress: $blocked ? self::CURRENT : null,
            blockedAt: null,
            blockedCause: null,
            bounced: $bounced,
            bouncedAddress: $bounced ? self::CURRENT : null,
            bouncedAt: null,
            currentEmail: self::CURRENT,
        );

        $repo = $this->createStub(CarRepository::class);
        $repo->method('effectiveEmailFlags')->willReturn($flags);

        $policy = new SendPolicy($repo);
        $reason = $policy->verificationReason($policy->forUser(MailKind::Verification, self::OWNER_ID));
        $this->assertNotNull($reason);
        $policyKind = self::REASON_KINDS[$reason];

        $notice = (new EmailNoticeBuilder($repo))->buildForOwner(self::OWNER_ID);
        $this->assertNotNull($notice);

        $event    = $eventName !== null ? (object) ['event' => $eventName, 'occurred_at' => '2026-09-01 10:00:00'] : null;
        $chipKind = VerificationTabStatus::chip(
            $event,
            $flags->hasProfile,
            $flags->blocked,
            $flags->blockedCause,
            $flags->optedOut,
            $flags->bounced
        )['kind'];

        $this->assertSame($expected, $policyKind);
        $this->assertSame($expected, $notice['kind']);
        $this->assertSame($expected, $chipKind);
    }
}
