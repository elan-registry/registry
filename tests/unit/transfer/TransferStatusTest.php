<?php

declare(strict_types=1);

use ElanRegistry\Transfer\TransferStatus;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for TransferStatus backed enum.
 *
 * Covers isTerminal() for all five cases, including the reserved Approved case
 * which is non-terminal even though no production code currently transitions to it.
 */
#[Group('fast')]
#[Group('transfer')]
final class TransferStatusTest extends TestCase
{
    /**
     * @return array<string, array{0: TransferStatus, 1: bool}>
     */
    public static function isTerminalProvider(): array
    {
        return [
            'pending is not terminal'  => [TransferStatus::Pending, false],
            'approved is not terminal' => [TransferStatus::Approved, false],
            'completed is terminal'    => [TransferStatus::Completed, true],
            'denied is terminal'       => [TransferStatus::Denied, true],
            'expired is terminal'      => [TransferStatus::Expired, true],
        ];
    }

    #[DataProvider('isTerminalProvider')]
    public function testIsTerminal(TransferStatus $status, bool $expected): void
    {
        $this->assertSame($expected, $status->isTerminal());
    }

    public function testBackingValuesMatchDatabaseEnumStrings(): void
    {
        $this->assertSame('pending',   TransferStatus::Pending->value);
        $this->assertSame('approved',  TransferStatus::Approved->value);
        $this->assertSame('completed', TransferStatus::Completed->value);
        $this->assertSame('denied',    TransferStatus::Denied->value);
        $this->assertSame('expired',   TransferStatus::Expired->value);
    }
}
