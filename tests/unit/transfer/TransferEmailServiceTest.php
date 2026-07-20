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
    protected function tearDown(): void
    {
        // Remove any per-test global overrides
        unset($GLOBALS['mockAdminEmails']);
        parent::tearDown();
    }

    /**
     * Creates a mock DB whose query() always returns count=0 (transfer not found).
     * error() always returns false (no DB error).
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

            public function error(): bool { return false; }
        };
    }

    /**
     * Creates a mock DB that dispatches by table name:
     * queries against `car_transfer_requests` return $transferRow,
     * queries against `cars` return $carRow.
     * error() always returns false (no DB error).
     */
    private function createFoundMockDb(object $transferRow, object $carRow): object
    {
        return new class($transferRow, $carRow) {
            public function __construct(
                private object $transferRow,
                private object $carRow,
            ) {}

            public function query(string $sql, array $params = []): object
            {
                $row = str_contains($sql, 'car_transfer_requests') ? $this->transferRow : $this->carRow;
                return new class($row) {
                    public function __construct(private object $row) {}
                    public function count(): int { return 1; }
                    public function first(): object { return $this->row; }
                };
            }

            public function error(): bool { return false; }
        };
    }

    private function makeTransferRow(array $overrides = []): object
    {
        return (object) array_merge([
            'id'                  => 1,
            'existing_car_id'     => 10,
            'requested_by_user_id' => 2,
            'submitted_comments'  => 'Test comments',
            'request_date'        => '2024-01-01 00:00:00',
            'expires_at'          => '2024-02-01 00:00:00',
            'completed_date'      => null,
        ], $overrides);
    }

    private function makeCarRow(array $overrides = []): object
    {
        return (object) array_merge([
            'id'      => 10,
            'user_id' => 1,
            'year'    => '1973',
            'series'  => 'S4',
            'variant' => 'SE',
            'chassis' => 'TEST001',
            'color'   => 'Red',
            'engine'  => 'ENG001',
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // Early-exit (not-found) tests — existing coverage
    // -------------------------------------------------------------------------

    public function testSendRequestReturnsFalseWhenTransferNotFound(): void
    {
        $db      = $this->createMockDb(0);
        $mailer  = function (): bool { return false; };
        $service = new TransferEmailService($db, $mailer);

        $this->assertFalse($service->sendRequest(999));
    }

    public function testSendAdminAlertReturnsFalseWhenTransferNotFound(): void
    {
        $db      = $this->createMockDb(0);
        $mailer  = function (): bool { return false; };
        $service = new TransferEmailService($db, $mailer);

        $this->assertFalse($service->sendAdminAlert(999));
    }

    public function testSendResponseReturnsFalseWhenTransferNotFound(): void
    {
        $db      = $this->createMockDb(0);
        $mailer  = function (): bool { return false; };
        $service = new TransferEmailService($db, $mailer);

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
        $service = new TransferEmailService($db, $mailer);

        $service->sendRequest(999);

        $this->assertFalse($called);
    }

    // -------------------------------------------------------------------------
    // Success-path and partial-failure tests — new coverage
    // -------------------------------------------------------------------------

    /**
     * sendRequest() calls the mailer when a valid transfer + car row exist.
     * The mailer receives the current owner's email address.
     */
    public function testSendRequestCallsMailerWhenRecordFound(): void
    {
        $db = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());

        $capturedTo = null;
        $mailer = function (string $to, string $subject, string $body) use (&$capturedTo): bool {
            $capturedTo = $to;
            return true;
        };

        $service = new TransferEmailService($db, $mailer);
        $result  = $service->sendRequest(1);

        $this->assertTrue($result);
        // Owner::find() is intercepted by the mock DB in bootstrap-unit.php, which returns 'test@example.com'
        $this->assertSame('test@example.com', $capturedTo);
    }

    /**
     * sendAdminAlert() returns true when at least one of two admin addresses succeeds.
     * The first delivery fails; the second succeeds. Loop-return condition is verified.
     */
    public function testSendAdminAlertReturnsTrueWhenAtLeastOneSucceeds(): void
    {
        $GLOBALS['mockAdminEmails'] = 'fail@example.com,pass@example.com';

        $db      = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $attempt = 0;
        $mailer  = function (string $to) use (&$attempt): bool {
            $attempt++;
            return $attempt > 1; // first call fails, second succeeds
        };

        $service = new TransferEmailService($db, $mailer);
        $result  = $service->sendAdminAlert(1);

        $this->assertTrue($result);
        $this->assertSame(2, $attempt, 'Mailer must be called once per admin address');
    }

    /**
     * sendAdminAlert() returns false immediately when no admin email is configured.
     */
    public function testSendAdminAlertReturnsFalseWhenNoAdminEmailConfigured(): void
    {
        $GLOBALS['mockAdminEmails'] = '';

        $db     = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $called = false;
        $mailer = function () use (&$called): bool {
            $called = true;
            return true;
        };

        $service = new TransferEmailService($db, $mailer);
        $result  = $service->sendAdminAlert(1);

        $this->assertFalse($result);
        $this->assertFalse($called, 'Mailer must not be called when there are no admin addresses');
    }

    /**
     * sendResponse() returns true when the requester email succeeds even if
     * the previous-owner notification fails — verifying the OR logic in
     * `return $requesterNotificationSent || $previousOwnerNotificationSent`.
     */
    public function testSendResponseReturnsTrueWhenRequesterSucceedsAndPreviousOwnerFails(): void
    {
        $db      = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $attempt = 0;
        $mailer  = function () use (&$attempt): bool {
            $attempt++;
            return $attempt === 1; // first call (requester) succeeds; second (previous owner) fails
        };

        $service = new TransferEmailService($db, $mailer);
        // Pass previousOwnerId so sendPreviousOwnerNotification() resolves an owner
        $result  = $service->sendResponse(1, true, '', 1);

        $this->assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // XSS content escaping — email body must escape user-supplied data
    // -------------------------------------------------------------------------

    public function testSendRequestEmailBodyEscapesXssInChassis(): void
    {
        $carRow  = $this->makeCarRow(['chassis' => '<script>alert(1)</script>']);
        $db      = $this->createFoundMockDb($this->makeTransferRow(), $carRow);
        $capturedBody = null;
        $mailer = function (string $to, string $subject, string $body) use (&$capturedBody): bool {
            $capturedBody = $body;
            return true;
        };
        $service = new TransferEmailService($db, $mailer);
        $service->sendRequest(1);

        $this->assertNotNull($capturedBody, 'Mailer must be called');
        $this->assertStringContainsString('&lt;script&gt;', $capturedBody);
        $this->assertStringNotContainsString('<script>alert', $capturedBody);
    }

    public function testSendAdminAlertEmailBodyEscapesXssInComments(): void
    {
        $GLOBALS['mockAdminEmails'] = 'admin@example.com';
        $transferRow = $this->makeTransferRow(['submitted_comments' => '<script>alert(1)</script>']);
        $db      = $this->createFoundMockDb($transferRow, $this->makeCarRow());
        $capturedBody = null;
        $mailer = function (string $to, string $subject, string $body) use (&$capturedBody): bool {
            $capturedBody = $body;
            return true;
        };
        $service = new TransferEmailService($db, $mailer);
        $service->sendAdminAlert(1);

        $this->assertNotNull($capturedBody, 'Mailer must be called');
        $this->assertStringContainsString('&lt;script&gt;', $capturedBody);
        $this->assertStringNotContainsString('<script>alert', $capturedBody);
    }

    public function testSendResponseEmailBodyEscapesXssInAdminNotes(): void
    {
        $db      = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $capturedBody = null;
        $attempt = 0;
        $mailer = function (string $to, string $subject, string $body) use (&$capturedBody, &$attempt): bool {
            $attempt++;
            if ($attempt === 1) { // first call = requester email
                $capturedBody = $body;
            }
            return true;
        };
        $service = new TransferEmailService($db, $mailer);
        $service->sendResponse(1, false, '<script>alert(1)</script>');

        $this->assertNotNull($capturedBody, 'Requester mailer must be called');
        $this->assertStringContainsString('&lt;script&gt;', $capturedBody);
        $this->assertStringNotContainsString('<script>alert', $capturedBody);
    }
}
