<?php

declare(strict_types=1);

use ElanRegistry\Transfer\TransferEmailService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('fast')]
#[Group('unit')]
#[Group('transfer')]
final class TransferEmailServiceTest extends TestCase
{
    /**
     * Creates a mock DB whose query() always returns count=0 and first()=null.
     */
    private function createMockDb(int $rowCount = 0): object
    {
        return new class($rowCount) {
            public function __construct(private int $rowCount) {}

            public function query(string $sql, array $params = []): object
            {
                $count = $this->rowCount;
                return new class($count) {
                    public function __construct(private int $count) {}
                    public function count(): int { return $this->count; }
                    public function first(): mixed { return null; }
                };
            }
        };
    }

    public function testSendRequestReturnsFalseWhenTransferNotFound(): void
    {
        $db      = $this->createMockDb(0);
        $mailer  = function (): bool { return false; };
        $service = new TransferEmailService($db, $mailer, '/fake/path/');

        $this->assertFalse($service->sendRequest(999));
    }

    public function testSendAdminAlertReturnsFalseWhenTransferNotFound(): void
    {
        $db      = $this->createMockDb(0);
        $mailer  = function (): bool { return false; };
        $service = new TransferEmailService($db, $mailer, '/fake/path/');

        $this->assertFalse($service->sendAdminAlert(999));
    }

    public function testSendResponseReturnsFalseWhenTransferNotFound(): void
    {
        $db      = $this->createMockDb(0);
        $mailer  = function (): bool { return false; };
        $service = new TransferEmailService($db, $mailer, '/fake/path/');

        $this->assertFalse($service->sendResponse(999, true));
    }

    public function testMailerIsNotCalledWhenTransferNotFoundViaSendRequest(): void
    {
        $db     = $this->createMockDb(0);
        $called = false;
        $mailer = function () use (&$called): bool {
            $called = true;
            return false;
        };
        $service = new TransferEmailService($db, $mailer, '/fake/path/');

        $service->sendRequest(999);

        $this->assertFalse($called);
    }
}
