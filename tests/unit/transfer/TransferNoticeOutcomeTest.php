<?php

declare(strict_types=1);

use ElanRegistry\Transfer\TransferNoticeOutcome;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for TransferNoticeOutcome backed enum.
 *
 * Covers ownerWasNotified() for all three cases. Only Sent means the current
 * owner got the transfer request notice (#2476).
 */
#[Group('fast')]
#[Group('transfer')]
final class TransferNoticeOutcomeTest extends TestCase
{
    /**
     * @return array<string, array{0: TransferNoticeOutcome, 1: bool}>
     */
    public static function outcomeProvider(): array
    {
        return [
            'sent'    => [TransferNoticeOutcome::Sent, true],
            'skipped' => [TransferNoticeOutcome::Skipped, false],
            'failed'  => [TransferNoticeOutcome::Failed, false],
        ];
    }

    #[DataProvider('outcomeProvider')]
    public function testOwnerWasNotifiedIsTrueOnlyForSent(TransferNoticeOutcome $outcome, bool $expected): void
    {
        $this->assertSame($expected, $outcome->ownerWasNotified());
    }
}
