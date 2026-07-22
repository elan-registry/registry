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
    private function createMockDb(int $rowCount = 0): \DB
    {
        $rows = array_fill(0, $rowCount, (object) []);
        $db = $this->createStub(\DB::class);
        $db->method('query')->willReturn(new \QueryResult($rows));
        $db->method('error')->willReturn(false);
        return $db;
    }

    /**
     * Creates a mock DB that dispatches by table name:
     * queries against `car_transfer_requests` return $transferRow,
     * queries against `cars` return $carRow.
     * error() always returns false (no DB error).
     */
    private function createFoundMockDb(object $transferRow, object $carRow): \DB
    {
        $db = $this->createStub(\DB::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($transferRow, $carRow): \QueryResult {
                $row = str_contains($sql, 'car_transfer_requests') ? $transferRow : $carRow;
                return new \QueryResult([$row]);
            }
        );
        $db->method('error')->willReturn(false);
        return $db;
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
        // 'test@example.com' is the hardcoded email returned by the Owner stub in
        // tests/unit/bootstrap-unit.php — if that fixture changes, update this assertion.
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

    /**
     * sendResponse() returns true when the previous-owner email succeeds even if
     * the requester notification fails — verifying the other side of the OR logic in
     * `return $requesterNotificationSent || $previousOwnerNotificationSent`.
     */
    public function testSendResponseReturnsTrueWhenPreviousOwnerSucceedsAndRequesterFails(): void
    {
        $db      = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $attempt = 0;
        $mailer  = function () use (&$attempt): bool {
            $attempt++;
            return $attempt !== 1; // first call (requester) fails; second (previous owner) succeeds
        };

        $service = new TransferEmailService($db, $mailer);
        $result  = $service->sendResponse(1, true, '', 1);

        $this->assertTrue($result);
    }

    /**
     * sendResponse() approved path skips the previous-owner notification when
     * $previousOwnerId is null — the guard in sendPreviousOwnerNotification() returns
     * false early to avoid emailing the new owner (who now holds $carData->user_id).
     * Mailer must be called exactly once (requester only).
     */
    public function testSendResponseApprovedWithNullPreviousOwnerIdSkipsPreviousOwnerNotification(): void
    {
        $db      = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $attempt = 0;
        $mailer  = function () use (&$attempt): bool {
            $attempt++;
            return true;
        };

        $service = new TransferEmailService($db, $mailer);
        $result  = $service->sendResponse(1, true, '', null);

        $this->assertTrue($result);
        $this->assertSame(1, $attempt, 'Mailer must be called only for requester; previous-owner notification must be skipped');
    }

    /**
     * sendResponse() approved path skips the previous-owner notification when
     * $previousOwnerId is 0 (int cast of NULL user_id from DB). Same guard as null case.
     */
    public function testSendResponseApprovedWithZeroPreviousOwnerIdSkipsPreviousOwnerNotification(): void
    {
        $db      = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $attempt = 0;
        $mailer  = function () use (&$attempt): bool {
            $attempt++;
            return true;
        };

        $service = new TransferEmailService($db, $mailer);
        $result  = $service->sendResponse(1, true, '', 0);

        $this->assertTrue($result);
        $this->assertSame(1, $attempt, 'Mailer must be called only for requester; previous-owner notification must be skipped when previousOwnerId is 0');
    }

    /**
     * sendResponse() denied path uses $carData->user_id as the previous-owner lookup
     * when $previousOwnerId is null. Verifies the mailer is called exactly twice.
     */
    public function testSendResponseDeniedPathCallsMailerTwice(): void
    {
        $db      = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $attempt = 0;
        $mailer  = function () use (&$attempt): bool {
            $attempt++;
            return true;
        };

        $service = new TransferEmailService($db, $mailer);
        $result  = $service->sendResponse(1, false, '', null);

        $this->assertTrue($result);
        $this->assertSame(2, $attempt, 'Mailer must be called for requester and previous owner');
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
        $capturedBodies = [];
        $mailer = function (string $to, string $subject, string $body) use (&$capturedBodies): bool {
            $capturedBodies[] = $body;
            return true;
        };
        $service = new TransferEmailService($db, $mailer);
        $service->sendResponse(1, false, '<script>alert(1)</script>');

        $this->assertCount(2, $capturedBodies, 'Mailer must be called for both requester and previous owner');
        foreach ($capturedBodies as $body) {
            $this->assertStringContainsString('&lt;script&gt;', $body);
            $this->assertStringNotContainsString('<script>alert', $body);
        }
    }

    public function testSendResponsePreviousOwnerEmailBodyEscapesXssInChassis(): void
    {
        $carRow  = $this->makeCarRow(['chassis' => '<script>alert(1)</script>']);
        $db      = $this->createFoundMockDb($this->makeTransferRow(), $carRow);
        $capturedBodies = [];
        $mailer = function (string $to, string $subject, string $body) use (&$capturedBodies): bool {
            $capturedBodies[] = $body;
            return true;
        };
        $service = new TransferEmailService($db, $mailer);
        $service->sendResponse(1, false, '', 1);

        $this->assertCount(2, $capturedBodies, 'Both mailer calls must fire');
        $this->assertStringContainsString('&lt;script&gt;', $capturedBodies[1]);
        $this->assertStringNotContainsString('<script>alert', $capturedBodies[1]);
    }
}
