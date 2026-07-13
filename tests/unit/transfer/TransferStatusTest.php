<?php

declare(strict_types=1);

use ElanRegistry\Transfer\TransferStatus;
use PHPUnit\Framework\TestCase;
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
    public function testPendingIsNotTerminal(): void
    {
        $this->assertFalse(TransferStatus::Pending->isTerminal());
    }

    public function testApprovedIsNotTerminal(): void
    {
        $this->assertFalse(TransferStatus::Approved->isTerminal());
    }

    public function testCompletedIsTerminal(): void
    {
        $this->assertTrue(TransferStatus::Completed->isTerminal());
    }

    public function testDeniedIsTerminal(): void
    {
        $this->assertTrue(TransferStatus::Denied->isTerminal());
    }

    public function testExpiredIsTerminal(): void
    {
        $this->assertTrue(TransferStatus::Expired->isTerminal());
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
