<?php

declare(strict_types=1);

namespace Tests\Unit\Classes;

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for transaction ownership in {@see Owner::commit()} and
 * {@see Owner::rollback()} (#2515).
 *
 * A throw from the driver must keep ownership. If it does not, the caller's
 * catch block calls rollback() as a no-op and the transaction stays open on
 * the connection.
 *
 * @author Jim Boone
 */
#[Group('unit')]
final class OwnerTransactionOwnershipTest extends TestCase
{
    /**
     * A database mock whose inTransaction() follows beginTransaction().
     * A failed commit() or rollBack() does not end the transaction, as in PDO.
     *
     * @return DatabaseInterface&MockObject
     */
    private function makeDatabase(): DatabaseInterface&MockObject
    {
        $db = $this->createMock(DatabaseInterface::class);

        $inTransaction = false;
        $db->method('inTransaction')->willReturnCallback(function () use (&$inTransaction): bool {
            return $inTransaction;
        });
        $db->method('beginTransaction')->willReturnCallback(function () use (&$inTransaction): bool {
            $inTransaction = true;
            return true;
        });

        return $db;
    }

    public function testCommitThatThrowsKeepsOwnershipSoRollbackRuns(): void
    {
        $db = $this->makeDatabase();
        $db->method('commit')->willThrowException(new \PDOException('simulated commit failure', 2006));
        $db->expects($this->once())->method('rollBack');

        $owner = new Owner(null, $db);
        $owner->beginTransaction();
        try {
            $owner->commit();
            $this->fail('commit() must re-throw the driver exception');
        } catch (\PDOException) {
            $owner->rollback();
        }
    }

    public function testRollbackThatThrowsKeepsOwnership(): void
    {
        $db = $this->makeDatabase();
        $calls = 0;
        $db->expects($this->exactly(2))->method('rollBack')->willReturnCallback(function () use (&$calls): bool {
            $calls++;
            if ($calls === 1) {
                throw new \PDOException('simulated rollback failure', 2006);
            }
            return true;
        });

        $owner = new Owner(null, $db);
        $owner->beginTransaction();
        try {
            $owner->rollback();
            $this->fail('rollback() must re-throw the driver exception');
        } catch (\PDOException) {
            $owner->rollback();
        }
    }

    public function testSuccessfulCommitReleasesOwnership(): void
    {
        $db = $this->makeDatabase();
        // inTransaction() stays true after the commit, so only the ownership flag stops the rollBack().
        $db->expects($this->once())->method('commit')->willReturn(true);
        $db->expects($this->never())->method('rollBack');

        $owner = new Owner(null, $db);
        $owner->beginTransaction();
        $owner->commit();
        $owner->rollback();
    }
}
