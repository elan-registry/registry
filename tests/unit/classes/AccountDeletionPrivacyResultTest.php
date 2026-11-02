<?php

declare(strict_types=1);

use ElanRegistry\AccountDeletionPrivacyOutcome;
use ElanRegistry\AccountDeletionPrivacyResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AccountDeletionPrivacyResult.
 *
 * A caller picks its message from the outcome, so each named constructor must
 * set its own outcome. A commit-unknown result must not read as rolled back:
 * script 30 would then tell the admin that the personal data remains.
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

        $this->assertSame(AccountDeletionPrivacyOutcome::CommitUnknown, $result->outcome);
        $this->assertSame('commit', $result->failedStage);
        $this->assertSame(0, $result->deletedEmailEventCount);
        $this->assertSame(0, $result->deletedHoldingRowCount);
    }

    public function testRolledBackNamesTheFailedStage(): void
    {
        $result = AccountDeletionPrivacyResult::rolledBack('event delete');

        $this->assertSame(AccountDeletionPrivacyOutcome::RolledBack, $result->outcome);
        $this->assertSame('event delete', $result->failedStage);
    }

    public function testCommittedKeepsTheCountsAndHasNoFailedStage(): void
    {
        $result = AccountDeletionPrivacyResult::committed(1, 2, 1, true);

        $this->assertSame(AccountDeletionPrivacyOutcome::Committed, $result->outcome);
        $this->assertNull($result->failedStage);
        $this->assertSame(1, $result->deletedEmailEventCount);
        $this->assertSame(2, $result->deletedPluginMessageCount);
        $this->assertSame(1, $result->deletedHoldingRowCount);
        $this->assertTrue($result->pluginTableAbsent);
    }

    public function testRefusedHasNoFailedStage(): void
    {
        $result = AccountDeletionPrivacyResult::refused();

        $this->assertSame(AccountDeletionPrivacyOutcome::Refused, $result->outcome);
        $this->assertNull($result->failedStage);
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function negativeCountProvider(): array
    {
        return [
            'email events' => [-1, 0, 0],
            'plugin messages' => [0, -1, 0],
            'holding rows' => [0, 0, -1],
        ];
    }

    #[DataProvider('negativeCountProvider')]
    public function testCommittedRejectsANegativeCount(int $events, int $pluginMessages, int $holdingRows): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('counts must not be negative');

        AccountDeletionPrivacyResult::committed($events, $pluginMessages, $holdingRows, false);
    }
}
