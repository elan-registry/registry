<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\OwnerDatabaseException;
use ElanRegistry\Migration\EmailEventRekeyQueries;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * EmailEventRekeyQueries reads through a PDO or a DatabaseInterface (#2358).
 * A failed read must give the same exception type on both, so a caller needs
 * one catch.
 */
#[Group('fast')]
final class EmailEventRekeyQueriesFailureTest extends TestCase
{
    public function testAPdoExceptionFromADatabaseInterfaceIsWrappedAsOwnerDatabaseException(): void
    {
        $pdoError = new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willThrowException($pdoError);

        try {
            (new EmailEventRekeyQueries($db))->isRekeyed();
            $this->fail('A failed read must throw');
        } catch (OwnerDatabaseException $e) {
            $this->assertSame($pdoError, $e->getPrevious());
            $this->assertStringContainsString('EmailEventRekeyQueries: query failed', $e->getMessage());
        }
    }

    public function testAnErrorFlagFromADatabaseInterfaceThrowsOwnerDatabaseException(): void
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturn($db);
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Table missing');

        $this->expectException(OwnerDatabaseException::class);
        $this->expectExceptionMessage('EmailEventRekeyQueries: query failed: Table missing');

        (new EmailEventRekeyQueries($db))->isRekeyed();
    }
}
