<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EffectiveEmailFlags;
use ElanRegistry\Car\SendPolicy;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;
use ElanRegistry\Transfer\TransferEmailService;
use ElanRegistry\Transfer\TransferNoticeOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('fast')]
#[Group('unit')]
#[Group('transfer')]
final class TransferEmailServiceTest extends TestCase
{
    /** @var list<int> User IDs that the policy read flags for, in call order */
    private array $askedUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The logger() stub appends to this global. Without a reset, an
        // assertion on the log can read lines from an earlier test.
        $GLOBALS['mockLogEntries'] = [];
        $this->askedUserIds = [];
    }

    protected function tearDown(): void
    {
        // Remove any per-test global overrides
        unset($GLOBALS['mockAdminEmails'], $GLOBALS['mockLogEntries']);
        parent::tearDown();
    }

    /**
     * Flag state of one owner, built as SendPolicyTest::flags() builds it.
     *
     * @param list<string> $flags Any of no_profile, email_bounced, email_opted_out, email_blocked
     */
    private function flags(array $flags): EffectiveEmailFlags
    {
        if (in_array('no_profile', $flags, true)) {
            return EffectiveEmailFlags::none();
        }

        return new EffectiveEmailFlags(
            true,
            in_array('email_opted_out', $flags, true),
            null,
            in_array('email_blocked', $flags, true),
            null,
            null,
            null,
            in_array('email_bounced', $flags, true),
            null,
            in_array('email_bounced', $flags, true) ? '2026-03-04 05:06:07' : null,
            'test@example.com'
        );
    }

    /**
     * A real SendPolicy over a stubbed CarRepository (SendPolicy is final).
     * The stub records each user ID that the policy reads.
     *
     * @param array<int, list<string>> $flagsByUser Flag names by user ID; a user not listed has none
     * @param array<int, \Throwable> $throwByUser Exception to throw on the flag read, by user ID
     */
    private function policy(array $flagsByUser = [], array $throwByUser = []): SendPolicy
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('effectiveEmailFlags')->willReturnCallback(
            function (int $userId) use ($flagsByUser, $throwByUser): EffectiveEmailFlags {
                $this->askedUserIds[] = $userId;
                if (isset($throwByUser[$userId])) {
                    throw $throwByUser[$userId];
                }
                return $this->flags($flagsByUser[$userId] ?? []);
            }
        );

        return new SendPolicy($repo);
    }

    private function allowAllPolicy(): SendPolicy
    {
        return $this->policy();
    }

    /**
     * @return array<int, array{user_id: int|string, category: string, message: string}>
     */
    private function logEntries(): array
    {
        /** @var array<int, array{user_id: int|string, category: string, message: string}> $entries */
        $entries = $GLOBALS['mockLogEntries'] ?? [];
        return $entries;
    }

    /**
     * Log lines that report a policy skip.
     *
     * @return list<array{user_id: int|string, category: string, message: string}>
     */
    private function skipLogEntries(): array
    {
        return array_values(array_filter(
            $this->logEntries(),
            static fn (array $e): bool => str_contains($e['message'], 'skipped for user')
        ));
    }

    /**
     * Creates a database double whose queries report $rowCount rows
     * (0 = transfer not found). error() always returns false (no DB error).
     *
     * query() returns the double itself because the real \DB::query() always
     * returns $this for chaining — the result data lives in count()/first()
     * on the same instance.
     */
    private function createMockDb(int $rowCount = 0): DatabaseInterface
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn($rowCount);
        // Real \DB::first() returns [] when there are no rows, never null.
        $db->method('first')->willReturn([]);
        return $db;
    }

    /**
     * Creates a database double that dispatches by table name:
     * queries against `car_transfer_requests` return $transferRow,
     * the `users LEFT JOIN profiles` lookup that Owner::find() runs returns a
     * user row for the requested id, and everything else returns $carRow.
     * error() always returns false (no DB error).
     *
     * TransferEmailService passes its own $db to every `new Owner(...)` it
     * constructs, so this one double answers both the service's car/transfer
     * queries and the owner lookups made through it.
     *
     * Real \DB carries result state on the instance — query() returns $this and
     * count()/first() describe the most recent statement — so $currentRow
     * mirrors that: each query() selects the row the following first() returns.
     */
    private function createFoundMockDb(object $transferRow, object $carRow): DatabaseInterface
    {
        $db         = $this->createStub(DatabaseInterface::class);
        $currentRow = $transferRow;

        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($db, $transferRow, $carRow, &$currentRow): DatabaseInterface {
                if (str_contains($sql, 'car_transfer_requests')) {
                    $currentRow = $transferRow;
                } elseif (str_contains($sql, 'profiles') && str_contains($sql, 'WHERE u.id')) {
                    $currentRow = $this->makeUserRow((int) ($params[0] ?? 0));
                } else {
                    $currentRow = $carRow;
                }
                return $db;
            }
        );
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturnCallback(
            function () use (&$currentRow): object {
                return $currentRow;
            }
        );
        return $db;
    }

    /**
     * A `users LEFT JOIN profiles` row as Owner::find() expects to read it.
     *
     * Owner::find() short-circuits on ids <= 0 without querying, so the
     * owner-not-found tests never reach this helper.
     */
    private function makeUserRow(int $userId): object
    {
        return (object) [
            'id'        => $userId,
            'email'     => 'test@example.com',
            'fname'     => 'Test',
            'lname'     => 'User',
            'join_date' => '2024-01-01 00:00:00',
            'city'      => 'Test City',
            'state'     => 'TS',
            'country'   => 'US',
            'lat'       => null,
            'lon'       => null,
            'website'   => '',
        ];
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
    // Constructor validation
    // -------------------------------------------------------------------------

    public function testConstructorThrowsWhenMailerIsNotCallable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TransferEmailService: $mailer must be callable');

        new TransferEmailService($this->createMockDb(0), 123);
    }

    // -------------------------------------------------------------------------
    // Early-exit (not-found) tests — existing coverage
    // -------------------------------------------------------------------------

    public function testSendRequestReturnsFailedWhenTransferNotFound(): void
    {
        $db      = $this->createMockDb(0);
        $mailer  = function (): bool { return false; };
        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());

        $this->assertSame(TransferNoticeOutcome::Failed, $service->sendRequest(999));
    }

    public function testSendAdminAlertReturnsFalseWhenTransferNotFound(): void
    {
        $db      = $this->createMockDb(0);
        $mailer  = function (): bool { return false; };
        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());

        $this->assertFalse($service->sendAdminAlert(999));
    }

    public function testSendResponseReturnsFalseWhenTransferNotFound(): void
    {
        $db      = $this->createMockDb(0);
        $mailer  = function (): bool { return false; };
        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());

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
        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());

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

        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());
        $result  = $service->sendRequest(1);

        $this->assertSame(TransferNoticeOutcome::Sent, $result);
        // 'test@example.com' is the email carried by makeUserRow(), which the
        // db double returns for Owner::find() — update both together.
        $this->assertSame('test@example.com', $capturedTo);
    }

    /**
     * With no policy argument, the service builds its own
     * SendPolicy(new CarRepository($db)). The flag read then goes through the
     * same database double, and an opted-out current owner gets no notice.
     * The send returns Skipped, which is neither a send nor a failure.
     *
     * Fails without the #2463 change: TransferNotice is not yet a policy kind
     * and the default policy does not gate the send, so the mailer is called.
     */
    public function testSendRequestWithDefaultPolicySkipsOptedOutOwner(): void
    {
        $transferRow = $this->makeTransferRow();
        $carRow      = $this->makeCarRow();
        $flagsRow    = (object) [
            'email_opted_out'       => '1',
            'email_opted_out_at'    => '2026-01-02 03:04:05',
            'email_blocked'         => '0',
            'email_blocked_address' => null,
            'email_blocked_at'      => null,
            'email_blocked_cause'   => null,
            'email_bounced'         => '0',
            'email_bounced_address' => null,
            'email_bounced_at'      => null,
            'user_email'            => 'test@example.com',
        ];

        $db         = $this->createStub(DatabaseInterface::class);
        $currentRow = $transferRow;
        $flagReads  = 0;
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($db, $transferRow, $carRow, $flagsRow, &$currentRow, &$flagReads): DatabaseInterface {
                if (str_contains($sql, 'p.email_opted_out')) {
                    $flagReads++;
                    $currentRow = $flagsRow;
                } elseif (str_contains($sql, 'car_transfer_requests')) {
                    $currentRow = $transferRow;
                } elseif (str_contains($sql, 'profiles') && str_contains($sql, 'WHERE u.id')) {
                    $currentRow = $this->makeUserRow((int) ($params[0] ?? 0));
                } else {
                    $currentRow = $carRow;
                }
                return $db;
            }
        );
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturnCallback(
            function () use (&$currentRow): object {
                return $currentRow;
            }
        );

        $mailerCalls = 0;
        $mailer = function () use (&$mailerCalls): bool {
            $mailerCalls++;
            return true;
        };

        // No third argument: the production default policy.
        $service = new TransferEmailService($db, $mailer);

        $this->assertSame(TransferNoticeOutcome::Skipped, $service->sendRequest(1));
        $this->assertSame(1, $flagReads, 'The default policy read the owner flags through the database');
        $this->assertSame(0, $mailerCalls, 'An opted-out owner gets no transfer notice');
        $this->assertCount(1, $this->skipLogEntries());
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

        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());
        $result  = $service->sendAdminAlert(1);

        $this->assertTrue($result);
        $this->assertSame(2, $attempt, 'Mailer must be called once per admin address');
    }

    public function testSendAdminAlertReturnsFalseWhenAllMailerAttemptsFail(): void
    {
        $GLOBALS['mockAdminEmails'] = 'fail1@example.com,fail2@example.com';

        $db      = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $attempt = 0;
        $mailer  = function () use (&$attempt): bool {
            $attempt++;
            return false;
        };

        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());
        $result  = $service->sendAdminAlert(1);

        $this->assertFalse($result);
        $this->assertSame(2, $attempt, 'Mailer must be attempted once per admin address even though all fail');
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

        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());
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

        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());
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

        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());
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

        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());
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

        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());
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

        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());
        $result  = $service->sendResponse(1, false, '', null);

        $this->assertTrue($result);
        $this->assertSame(2, $attempt, 'Mailer must be called for requester and previous owner');
    }

    // -------------------------------------------------------------------------
    // Owner-not-found guards — new coverage
    // -------------------------------------------------------------------------

    /**
     * Asserts $mockLogEntries contains an entry whose message contains $needle.
     *
     * A guard's "not found" message (e.g. "Current owner ID 0 not found") is
     * distinct from the catch-all Throwable handler's message (e.g. "... error
     * for request #1 [TypeError] in ..."), which fires with the same false-return/
     * mailer-not-called observable outcome if the guard is deleted and the null
     * owner hits a typed parameter downstream. Asserting the guard's specific
     * message — not just the return value — is what makes these tests actually
     * detect a deleted guard instead of passing either way.
     *
     * @param array<int, array{user_id: int, category: string, message: string}> $logEntries
     */
    private function assertLogContains(array $logEntries, string $needle): void
    {
        $matches = array_filter($logEntries, fn($e) => str_contains($e['message'], $needle));
        $this->assertNotEmpty($matches, "Expected a log entry containing \"$needle\", got: " . json_encode($logEntries));
    }

    public function testSendRequestReturnsFailedWhenCurrentOwnerNotFound(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        $db     = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow(['user_id' => 0]));
        $called = false;
        $mailer = function () use (&$called): bool {
            $called = true;
            return true;
        };

        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());

        $this->assertSame(TransferNoticeOutcome::Failed, $service->sendRequest(1));
        $this->assertFalse($called, 'Mailer must not be called when the current owner cannot be found');
        $this->assertLogContains($mockLogEntries, 'Current owner ID 0 not found');
    }

    public function testSendRequestReturnsFailedWhenCarNotFound(): void
    {
        // The transfer row exists, but the car query finds no row.
        $db          = $this->createStub(DatabaseInterface::class);
        $transferRow = $this->makeTransferRow();
        $rowCount    = 0;
        $db->method('query')->willReturnCallback(
            function (string $sql) use ($db, &$rowCount): DatabaseInterface {
                $rowCount = str_contains($sql, 'car_transfer_requests') ? 1 : 0;
                return $db;
            }
        );
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturnCallback(function () use (&$rowCount): int {
            return $rowCount;
        });
        $db->method('first')->willReturn($transferRow);
        $subjects = [];
        $service  = new TransferEmailService($db, $this->recordingMailer($subjects), $this->allowAllPolicy());

        $this->assertSame(TransferNoticeOutcome::Failed, $service->sendRequest(1));
        $this->assertSame([], $subjects, 'Mailer must not be called when the car cannot be found');
        $this->assertLogContains($this->logEntries(), 'Car ID 10 not found');
    }

    public function testSendRequestReturnsFailedWhenMailerReturnsFalse(): void
    {
        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $subjects = [];
        $service  = new TransferEmailService($db, $this->recordingMailer($subjects, [1 => false]), $this->allowAllPolicy());

        $this->assertSame(TransferNoticeOutcome::Failed, $service->sendRequest(1));
        $this->assertCount(1, $subjects);
        $this->assertLogContains($this->logEntries(), 'Failed to send transfer request notification to current owner: user 1');
    }

    public function testSendRequestReturnsFailedWhenRequesterNotFound(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        $db     = $this->createFoundMockDb($this->makeTransferRow(['requested_by_user_id' => 0]), $this->makeCarRow());
        $called = false;
        $mailer = function () use (&$called): bool {
            $called = true;
            return true;
        };

        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());

        $this->assertSame(TransferNoticeOutcome::Failed, $service->sendRequest(1));
        $this->assertFalse($called, 'Mailer must not be called when the requester cannot be found');
        $this->assertLogContains($mockLogEntries, 'Requester ID 0 not found');
    }

    public function testSendAdminAlertReturnsFalseWhenCurrentOwnerNotFound(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];
        $GLOBALS['mockAdminEmails'] = 'admin@example.com';

        $db     = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow(['user_id' => 0]));
        $called = false;
        $mailer = function () use (&$called): bool {
            $called = true;
            return true;
        };

        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());

        $this->assertFalse($service->sendAdminAlert(1));
        $this->assertFalse($called, 'Mailer must not be called when the current owner cannot be found');
        $this->assertLogContains($mockLogEntries, 'Current owner ID 0 not found');
    }

    public function testSendAdminAlertReturnsFalseWhenRequesterNotFound(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];
        $GLOBALS['mockAdminEmails'] = 'admin@example.com';

        $db     = $this->createFoundMockDb($this->makeTransferRow(['requested_by_user_id' => 0]), $this->makeCarRow());
        $called = false;
        $mailer = function () use (&$called): bool {
            $called = true;
            return true;
        };

        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());

        $this->assertFalse($service->sendAdminAlert(1));
        $this->assertFalse($called, 'Mailer must not be called when the requester cannot be found');
        $this->assertLogContains($mockLogEntries, 'Requester ID 0 not found');
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
        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());
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
        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());
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
        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());
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
        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());
        $service->sendResponse(1, false, '', 1);

        $this->assertCount(2, $capturedBodies, 'Both mailer calls must fire');
        $this->assertStringContainsString('&lt;script&gt;', $capturedBodies[1]);
        $this->assertStringNotContainsString('<script>alert', $capturedBodies[1]);
    }

    // -------------------------------------------------------------------------
    // Send policy (#2463): TransferNotice
    // -------------------------------------------------------------------------

    /**
     * Mailer double that records the subject of each send.
     *
     * @param list<string> $subjects Filled by reference
     * @param array<int, bool> $results Result by call number (1-based); default true
     * @param array<int, \Throwable> $throws Exception to throw by call number
     */
    private function recordingMailer(array &$subjects, array $results = [], array $throws = []): \Closure
    {
        return function (string $to, string $subject, string $body) use (&$subjects, $results, $throws): bool {
            $subjects[] = $subject;
            $n = count($subjects);
            if (isset($throws[$n])) {
                throw $throws[$n];
            }
            return $results[$n] ?? true;
        };
    }

    public function testSendRequestOptedOutCurrentOwnerSkipsMailerReturnsSkippedAndLogsUserId(): void
    {
        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $subjects = [];
        $service  = new TransferEmailService($db, $this->recordingMailer($subjects), $this->policy([1 => ['email_opted_out']]));

        $result = $service->sendRequest(1);

        $this->assertSame(TransferNoticeOutcome::Skipped, $result, 'A skipped notice is neither a send nor a failure');
        $this->assertSame([], $subjects, 'The mailer must not be called for an opted-out owner');
        $skips = $this->skipLogEntries();
        $this->assertCount(1, $skips);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_ERROR, $skips[0]['category']);
        $this->assertStringContainsString('user 1', $skips[0]['message']);
        $this->assertStringContainsString('email_opted_out', $skips[0]['message']);
        $this->assertCount(1, $this->logEntries(), 'The skip is the only log line');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockingFlagProvider(): array
    {
        return [
            'no profile'  => ['no_profile'],
            'bounced'     => ['email_bounced'],
            'opted out'   => ['email_opted_out'],
            // email_blocked blocks TransferNotice but not AdminContact, so this
            // row fails if the service asks for the wrong kind.
            'blocked'     => ['email_blocked'],
        ];
    }

    #[DataProvider('blockingFlagProvider')]
    public function testSendRequestEachBlockingFlagSkipsTheMailerAndNamesTheFlag(string $flag): void
    {
        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $subjects = [];
        $service  = new TransferEmailService($db, $this->recordingMailer($subjects), $this->policy([1 => [$flag]]));

        $this->assertSame(TransferNoticeOutcome::Skipped, $service->sendRequest(1));
        $this->assertSame([], $subjects);
        $skips = $this->skipLogEntries();
        $this->assertCount(1, $skips);
        $this->assertStringContainsString($flag, $skips[0]['message']);
    }

    public function testSendRequestPolicyIsAskedForTheCurrentOwnerId(): void
    {
        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow(['user_id' => 5]));
        $subjects = [];
        $service  = new TransferEmailService($db, $this->recordingMailer($subjects), $this->policy());

        $this->assertSame(TransferNoticeOutcome::Sent, $service->sendRequest(1));
        $this->assertSame([5], $this->askedUserIds, 'Only the current owner (5), not the requester (2)');
        $this->assertCount(1, $subjects);
    }

    public function testSendResponsePolicyIsAskedForRequesterThenPreviousOwner(): void
    {
        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow(['user_id' => 3]));
        $subjects = [];
        $service  = new TransferEmailService($db, $this->recordingMailer($subjects), $this->policy());

        $this->assertTrue($service->sendResponse(1, true, '', 7));
        $this->assertSame([2, 7], $this->askedUserIds, 'Requester (2), then the supplied previous owner (7)');
        $this->assertCount(2, $subjects);
    }

    public function testSendResponseOptedOutRequesterSkipsRequesterMailOnly(): void
    {
        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $subjects = [];
        $service  = new TransferEmailService($db, $this->recordingMailer($subjects), $this->policy([2 => ['email_opted_out']]));

        $result = $service->sendResponse(1, false);

        $this->assertTrue($result);
        $this->assertCount(1, $subjects, 'Only the previous owner gets mail');
        $this->assertStringContainsString('Transfer Decision', $subjects[0]);
        $skips = $this->skipLogEntries();
        $this->assertCount(1, $skips);
        $this->assertStringContainsString('user 2', $skips[0]['message']);
        $this->assertStringContainsString('email_opted_out', $skips[0]['message']);
    }

    public function testSendResponseOptedOutPreviousOwnerSkipsPreviousOwnerMailOnly(): void
    {
        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $subjects = [];
        $service  = new TransferEmailService($db, $this->recordingMailer($subjects), $this->policy([1 => ['email_opted_out']]));

        $result = $service->sendResponse(1, false);

        $this->assertTrue($result);
        $this->assertCount(1, $subjects, 'Only the requester gets mail');
        $this->assertStringNotContainsString('Transfer Decision', $subjects[0]);
        $skips = $this->skipLogEntries();
        $this->assertCount(1, $skips);
        $this->assertStringContainsString('user 1', $skips[0]['message']);
    }

    /**
     * A skip is not a send. When the policy skips both notices, no mail went
     * out, so the caller must not log "at least one notification sent".
     */
    public function testSendResponseDeniedBothOptedOutMakesNoMailerCallAndReturnsFalse(): void
    {
        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $subjects = [];
        $service  = new TransferEmailService(
            $db,
            $this->recordingMailer($subjects),
            $this->policy([1 => ['email_opted_out'], 2 => ['email_opted_out']])
        );

        $this->assertFalse($service->sendResponse(1, false));
        $this->assertSame([], $subjects);
        $this->assertCount(2, $this->skipLogEntries());
    }

    public function testSendResponseApprovedBothOptedOutMakesNoMailerCallAndReturnsFalse(): void
    {
        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow(['user_id' => 2]));
        $subjects = [];
        $service  = new TransferEmailService(
            $db,
            $this->recordingMailer($subjects),
            $this->policy([2 => ['email_blocked'], 7 => ['email_opted_out']])
        );

        $this->assertFalse($service->sendResponse(1, true, '', 7));
        $this->assertSame([], $subjects);
        $this->assertCount(2, $this->skipLogEntries());
    }

    public function testSendResponseOneSkippedAndOneFailedReturnsFalse(): void
    {
        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $subjects = [];
        // The requester (2) is skipped. The one mail, to the previous owner, fails.
        $service  = new TransferEmailService(
            $db,
            $this->recordingMailer($subjects, [1 => false]),
            $this->policy([2 => ['email_opted_out']])
        );

        $this->assertFalse($service->sendResponse(1, false));
        $this->assertCount(1, $subjects, 'Only the previous owner mail was tried');
    }

    public function testSendAdminAlertIgnoresThePolicy(): void
    {
        $GLOBALS['mockAdminEmails'] = 'admin1@example.com,admin2@example.com';
        $everyFlag = ['no_profile', 'email_bounced', 'email_opted_out', 'email_blocked'];

        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $subjects = [];
        $service  = new TransferEmailService(
            $db,
            $this->recordingMailer($subjects),
            $this->policy([1 => $everyFlag, 2 => $everyFlag])
        );

        $this->assertTrue($service->sendAdminAlert(1));
        $this->assertCount(2, $subjects, 'Both admin addresses get the alert');
        $this->assertSame([], $this->askedUserIds, 'The admin alert must not read owner flags');
        $this->assertSame([], $this->skipLogEntries());
    }

    // -------------------------------------------------------------------------
    // Policy read failure
    // -------------------------------------------------------------------------

    private function readFailure(): CarDatabaseException
    {
        return new CarDatabaseException('ERROR #HY000: lock wait for owner@example.com');
    }

    public function testSendRequestPolicyReadFailureReturnsFailedWithoutMailAndLogsSummary(): void
    {
        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $subjects = [];
        $service  = new TransferEmailService($db, $this->recordingMailer($subjects), $this->policy([], [1 => $this->readFailure()]));

        $this->assertSame(TransferNoticeOutcome::Failed, $service->sendRequest(1));
        $this->assertSame([], $subjects);
        $entries = $this->logEntries();
        $this->assertCount(1, $entries);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_ERROR, $entries[0]['category']);
        $this->assertStringContainsString('CarDatabaseException', $entries[0]['message']);
        $this->assertStringContainsString('SQLSTATE HY000', $entries[0]['message']);
        $this->assertStringNotContainsString('owner@example.com', $entries[0]['message']);
        $this->assertStringNotContainsString('lock wait', $entries[0]['message']);
    }

    public function testSendResponseRequesterPolicyReadFailureReturnsFalseWithoutMail(): void
    {
        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $subjects = [];
        $service  = new TransferEmailService($db, $this->recordingMailer($subjects), $this->policy([], [2 => $this->readFailure()]));

        $this->assertFalse($service->sendResponse(1, false));
        $this->assertSame([], $subjects, 'The failure stops the method before any send');
        $this->assertNotEmpty($this->logEntries());
        foreach ($this->logEntries() as $entry) {
            $this->assertStringNotContainsString('owner@example.com', $entry['message']);
        }
    }

    public function testSendResponsePreviousOwnerPolicyReadFailureSendsNothingToThatOwner(): void
    {
        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $subjects = [];
        $service  = new TransferEmailService($db, $this->recordingMailer($subjects), $this->policy([], [1 => $this->readFailure()]));

        $result = $service->sendResponse(1, false);

        $this->assertTrue($result, 'The requester notice went out');
        $this->assertCount(1, $subjects);
        $this->assertStringNotContainsString('Transfer Decision', $subjects[0], 'No mail to the previous owner');
        $summaries = array_filter(
            $this->logEntries(),
            static fn (array $e): bool => str_contains($e['message'], 'CarDatabaseException')
        );
        $this->assertCount(1, $summaries);
    }

    // -------------------------------------------------------------------------
    // Log lines never hold an address
    // -------------------------------------------------------------------------

    /**
     * Each scenario runs one send path and returns nothing; the test reads the log.
     *
     * @return array<string, array{0: string}>
     */
    public static function logScenarioProvider(): array
    {
        return [
            'request success'          => ['requestSuccess'],
            'request mailer false'     => ['requestMailerFalse'],
            'request owner not found'  => ['requestOwnerNotFound'],
            'admin partial failure'    => ['adminPartial'],
            'admin total failure'      => ['adminTotal'],
            'response mailer failure'  => ['responseFailure'],
            'policy skip'              => ['policySkip'],
            'mailer throws'            => ['mailerThrows'],
            'previous owner throws'    => ['previousOwnerThrows'],
            'policy read failure'      => ['policyReadFailure'],
        ];
    }

    #[DataProvider('logScenarioProvider')]
    public function testNoLogLineNamesAnAddress(string $scenario): void
    {
        $GLOBALS['mockAdminEmails'] = 'admin1@example.com,admin2@example.com';
        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $ownerGone = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow(['user_id' => 0]));
        $subjects = [];
        $boom     = new \RuntimeException('smtp rejected owner@example.com');

        switch ($scenario) {
            case 'requestSuccess':
                (new TransferEmailService($db, $this->recordingMailer($subjects), $this->policy()))->sendRequest(1);
                break;
            case 'requestMailerFalse':
                (new TransferEmailService($db, $this->recordingMailer($subjects, [1 => false]), $this->policy()))->sendRequest(1);
                break;
            case 'requestOwnerNotFound':
                (new TransferEmailService($ownerGone, $this->recordingMailer($subjects), $this->policy()))->sendRequest(1);
                break;
            case 'adminPartial':
                (new TransferEmailService($db, $this->recordingMailer($subjects, [1 => false]), $this->policy()))->sendAdminAlert(1);
                break;
            case 'adminTotal':
                (new TransferEmailService($db, $this->recordingMailer($subjects, [1 => false, 2 => false]), $this->policy()))->sendAdminAlert(1);
                break;
            case 'responseFailure':
                (new TransferEmailService($db, $this->recordingMailer($subjects, [1 => false, 2 => false]), $this->policy()))->sendResponse(1, false);
                break;
            case 'policySkip':
                (new TransferEmailService($db, $this->recordingMailer($subjects), $this->policy([1 => ['email_opted_out']])))->sendRequest(1);
                break;
            case 'mailerThrows':
                (new TransferEmailService($db, $this->recordingMailer($subjects, [], [1 => $boom]), $this->policy()))->sendRequest(1);
                break;
            case 'previousOwnerThrows':
                (new TransferEmailService($db, $this->recordingMailer($subjects, [], [2 => $boom]), $this->policy()))->sendResponse(1, false);
                break;
            case 'policyReadFailure':
                (new TransferEmailService($db, $this->recordingMailer($subjects), $this->policy([], [1 => $this->readFailure()])))->sendRequest(1);
                break;
            default:
                $this->fail("Unknown scenario $scenario");
        }

        $entries = $this->logEntries();
        $this->assertNotEmpty($entries, 'The scenario must log; an empty log would pass the loop by default');
        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('example.com', $entry['message']);
            $this->assertDoesNotMatchRegularExpression('/[^\s@]+@[^\s@]+/', $entry['message']);
        }
    }

    public function testSendRequestWhenMailerThrowsLogsExceptionSummaryNotRawMessage(): void
    {
        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $subjects = [];
        $mailer   = $this->recordingMailer($subjects, [], [1 => new \RuntimeException('smtp rejected owner@example.com')]);
        $service  = new TransferEmailService($db, $mailer, $this->policy());

        $this->assertSame(TransferNoticeOutcome::Failed, $service->sendRequest(1));

        $entries = $this->logEntries();
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('RuntimeException', $entries[0]['message']);
        $this->assertStringNotContainsString('smtp rejected', $entries[0]['message']);
        $this->assertStringNotContainsString('owner@example.com', $entries[0]['message']);
    }

    public function testSendAdminAlertFailureLogNamesListPositionNotAddress(): void
    {
        $GLOBALS['mockAdminEmails'] = 'a1@example.com,a2@example.com,a3@example.com';
        $db       = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $subjects = [];
        $service  = new TransferEmailService($db, $this->recordingMailer($subjects, [2 => false]), $this->policy());

        $this->assertTrue($service->sendAdminAlert(1));

        $failures = array_filter(
            $this->logEntries(),
            static fn (array $e): bool => str_contains($e['message'], 'failed to send to')
        );
        $this->assertCount(1, $failures);
        $message = array_values($failures)[0]['message'];
        $this->assertStringContainsString('admin #2 of 3', $message);
        $this->assertStringNotContainsString('a2@example.com', $message);
    }

    // -------------------------------------------------------------------------
    // Admin alert names a missing owner notice (#2476)
    // -------------------------------------------------------------------------

    private const OWNER_NOT_NOTIFIED = 'The registry sent no notice of this request to the current owner.';

    /**
     * Send the admin alert to one address and return the body.
     *
     * @param TransferNoticeOutcome|null $ownerNotice Null uses the default argument
     */
    private function adminAlertBody(?TransferNoticeOutcome $ownerNotice): string
    {
        $GLOBALS['mockAdminEmails'] = 'admin@example.com';
        $db     = $this->createFoundMockDb($this->makeTransferRow(), $this->makeCarRow());
        $bodies = [];
        $mailer = function (string $to, string $subject, string $body) use (&$bodies): bool {
            $bodies[] = $body;
            return true;
        };
        $service = new TransferEmailService($db, $mailer, $this->allowAllPolicy());

        $result = $ownerNotice === null ? $service->sendAdminAlert(1) : $service->sendAdminAlert(1, $ownerNotice);

        $this->assertTrue($result);
        $this->assertCount(1, $bodies);
        return $bodies[0];
    }

    /**
     * @return array<string, array{0: TransferNoticeOutcome}>
     */
    public static function ownerNotNotifiedProvider(): array
    {
        return [
            'skipped' => [TransferNoticeOutcome::Skipped],
            'failed'  => [TransferNoticeOutcome::Failed],
        ];
    }

    /** Fails when the admin alert omits the no-notice sentence. */
    #[DataProvider('ownerNotNotifiedProvider')]
    public function testSendAdminAlertBodyNamesTheMissingOwnerNotice(TransferNoticeOutcome $ownerNotice): void
    {
        $body = $this->adminAlertBody($ownerNotice);

        $this->assertSame(1, substr_count($body, self::OWNER_NOT_NOTIFIED));
        $this->assertLessThan(
            strpos($body, 'Transfer Request Details'),
            strpos($body, self::OWNER_NOT_NOTIFIED),
            'The sentence comes before the request details box'
        );
    }

    public function testSendAdminAlertBodyOmitsTheSentenceWhenTheOwnerWasNotified(): void
    {
        $this->assertStringNotContainsString(self::OWNER_NOT_NOTIFIED, $this->adminAlertBody(TransferNoticeOutcome::Sent));
    }

    public function testSendAdminAlertBodyOmitsTheSentenceForTheDefaultArgument(): void
    {
        $this->assertStringNotContainsString(self::OWNER_NOT_NOTIFIED, $this->adminAlertBody(null));
    }
}
