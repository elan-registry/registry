<?php

declare(strict_types=1);

namespace Tests\Unit\Cars\Services;

use ElanRegistry\Car\EffectiveEmailFlags;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * EffectiveEmailFlags::fromQueueRow() reads the flags of one Verification-tab
 * queue row, so VerificationTabStatus::chip() gets the same value type as
 * SendPolicy and EmailNoticeBuilder.
 */
#[Group('fast')]
final class EffectiveEmailFlagsFromQueueRowTest extends TestCase
{
    public function testEligibleRowWithNoProfileColumnsHasAProfileAndNoFlag(): void
    {
        $flags = EffectiveEmailFlags::fromQueueRow((object) ['id' => 1, 'user_id' => 2]);

        $this->assertTrue($flags->hasProfile);
        $this->assertFalse($flags->blocked);
        $this->assertFalse($flags->bounced);
        $this->assertFalse($flags->optedOut);
        $this->assertNull($flags->blockedCause);
    }

    public function testActiveColumnsSetEachFlag(): void
    {
        $flags = EffectiveEmailFlags::fromQueueRow((object) [
            'has_profile'          => '1',
            'email_blocked_active' => '1',
            'email_bounced_active' => 1,
            'email_opted_out'      => '1',
            'email_blocked_cause'  => 'spam',
        ]);

        $this->assertTrue($flags->hasProfile);
        $this->assertTrue($flags->blocked);
        $this->assertTrue($flags->bounced);
        $this->assertTrue($flags->optedOut);
        $this->assertSame('spam', $flags->blockedCause);
    }

    /**
     * The stored cause stays in the row after the block stops applying to
     * the current address. It must not show then.
     */
    public function testCauseOfABlockThatDoesNotApplyIsDropped(): void
    {
        $flags = EffectiveEmailFlags::fromQueueRow((object) [
            'has_profile'          => 1,
            'email_blocked_active' => 0,
            'email_blocked_cause'  => 'spam',
        ]);

        $this->assertFalse($flags->blocked);
        $this->assertNull($flags->blockedCause);
    }

    public function testNoProfileRowClearsEveryFlag(): void
    {
        $flags = EffectiveEmailFlags::fromQueueRow((object) [
            'has_profile'          => 0,
            'email_blocked_active' => 1,
            'email_bounced_active' => 1,
            'email_opted_out'      => 1,
            'email_blocked_cause'  => 'spam',
        ]);

        $this->assertFalse($flags->hasProfile);
        $this->assertFalse($flags->blocked);
        $this->assertFalse($flags->bounced);
        $this->assertFalse($flags->optedOut);
        $this->assertNull($flags->blockedCause);
    }

    public function testBlockWithNoStoredCauseHasNullCause(): void
    {
        $flags = EffectiveEmailFlags::fromQueueRow((object) [
            'has_profile'          => 1,
            'email_blocked_active' => 1,
            'email_blocked_cause'  => null,
        ]);

        $this->assertTrue($flags->blocked);
        $this->assertNull($flags->blockedCause);
    }
}
