<?php

declare(strict_types=1);

use ElanRegistry\AccountDeletionPrivacyResult;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AccountDeletionPrivacyResult.
 *
 * A caller picks its message from the flags, so each named constructor must
 * set exactly one outcome. A commit-unknown result must not read as rolled
 * back: script 30 would then tell the admin that the personal data remains.
 *
 * @see usersc/classes/AccountDeletionPrivacyResult.php
 */
#[Group('fast')]
#[Group('unit')]
final class AccountDeletionPrivacyResultTest extends TestCase
{
    public function testCommitUnknownIsNotCommittedAndNotARollback(): void
    {
        $result = AccountDeletionPrivacyResult::commitUnknown();

        $this->assertTrue($result->commitOutcomeUnknown);
        $this->assertFalse($result->committed);
        $this->assertFalse($result->refused);
        $this->assertSame('commit', $result->failedStage);
        $this->assertSame(0, $result->deletedEmailEventCount);
        $this->assertSame(0, $result->deletedHoldingRowCount);
    }

    public function testRolledBackHasAKnownOutcome(): void
    {
        $result = AccountDeletionPrivacyResult::rolledBack('event delete');

        $this->assertFalse($result->commitOutcomeUnknown);
        $this->assertFalse($result->committed);
        $this->assertFalse($result->refused);
        $this->assertSame('event delete', $result->failedStage);
    }

    public function testCommittedAndRefusedHaveAKnownOutcome(): void
    {
        $this->assertFalse(AccountDeletionPrivacyResult::committed(1, 2, 1, false)->commitOutcomeUnknown);
        $this->assertFalse(AccountDeletionPrivacyResult::refused()->commitOutcomeUnknown);
    }
}
