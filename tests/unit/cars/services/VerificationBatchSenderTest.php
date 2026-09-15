<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationEmailComposer;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\CarVerificationSendService;
use ElanRegistry\Car\VerificationBatchSender;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\EmailTemplate;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VerificationBatchSender (#1884, PR review gap-closing pass)
 *
 * Covers two behaviors of the `verification_send_batch` POST handler in
 * app/admin/index.php that were previously untested because that file
 * cannot be require()'d directly in a unit test (see
 * tests/unit/admin/VerificationEligibilitySkipReasonTest.php's docblock for
 * why):
 *
 * 1. BATCH-LOOP FAILURE CONTAINMENT. The loop has four independent
 *    try/catch guards specifically designed so one car's failure never
 *    aborts the batch or drops subsequent cars from the report:
 *    findById() throwing, findById() returning null, the eligibility
 *    re-check throwing, and sendOne() throwing. Each is exercised below
 *    with a mixed batch (one broken id alongside valid ones) to prove the
 *    valid cars still get processed and land in the correct bucket.
 *
 * 2. isUnrecorded() -> REPORT-BUCKET ROUTING. SendResultTest proves
 *    isUnrecorded() itself returns the right boolean, and
 *    CarVerificationSendServiceTest proves sendOne() returns the right
 *    SendResult, but neither proves this class's routing — that an
 *    unrecorded SendResult lands in the 'unrecorded' bucket rather than
 *    'sent'. If the isUnrecorded() branch were ever reordered below the
 *    STATUS_SENT check, every unrecorded send would silently land in the
 *    "clean send" bucket — the exact defect commit 4224b511 already fixed
 *    once at a different layer.
 *
 * CarVerificationSendService is `final` and cannot be mocked by PHPUnit's
 * doubler, so — matching CarVerificationSendServiceTest.php's own
 * construction pattern — a REAL CarVerificationSendService is built from a
 * mocked CarRepository and CarVerificationManager plus a real
 * CarVerificationEmailComposer. Per-car outcomes (a clean send, an
 * unrecorded send, a failure, an exception) are driven by keying the mocked
 * methods off the car id passed in, and the email()/dbi() global seams the
 * same way CarVerificationSendServiceTest.php does.
 */
#[Group('fast')]
final class VerificationBatchSenderTest extends TestCase
{
    /** @var CarRepository&\PHPUnit\Framework\MockObject\MockObject */
    private CarRepository $mockRepo;

    /** @var CarVerificationManager&\PHPUnit\Framework\MockObject\MockObject */
    private CarVerificationManager $mockVerifier;

    private CarVerificationSendService $sendSvc;

    private VerificationBatchSender $sender;

    protected function setUp(): void
    {
        $this->mockRepo = $this->createMock(CarRepository::class);
        $this->mockVerifier = $this->createMock(CarVerificationManager::class);
        $composer = new CarVerificationEmailComposer(new EmailTemplate());

        $this->sendSvc = new CarVerificationSendService($this->mockRepo, $this->mockVerifier, $composer);

        $this->sender = new VerificationBatchSender(
            $this->mockRepo,
            $this->sendSvc,
            1 // $currentUserId, arbitrary — only used for logger() calls
        );

        // Default happy-path stubs for CarVerificationManager, matching
        // CarVerificationSendServiceTest.php's setUp assumptions: code
        // rotation always "succeeds" and mutates the passed car object.
        $this->mockVerifier->method('generateVerificationCode')->willReturn('newcode1234567890');
        $this->mockVerifier->method('setVerificationCode')->willReturnCallback(
            function (object $car, string $code): bool {
                $car->vericode = $code;
                return true;
            }
        );
        $this->mockVerifier->method('setVerificationSentAt')->willReturnCallback(
            function (object $car, string $sentAt): bool {
                $car->vericode_sent_at = $sentAt;
                return true;
            }
        );

        $GLOBALS['mockSentEmails'] = [];
        unset($GLOBALS['mockEmailSendResult']);
        $GLOBALS['hookTestDb'] = $this->makeOwnerFoundDb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['mockSentEmails'], $GLOBALS['mockEmailSendResult'], $GLOBALS['hookTestDb']);
        parent::tearDown();
    }

    /**
     * A DatabaseInterface double simulating Owner::find() succeeding for
     * every user id — sendOne() reads the owner via `new Owner((int)
     * $carData->user_id)`, which falls back to this dbi() seam. Mirrors
     * CarVerificationSendServiceTest.php's makeOwnerFoundDb().
     */
    private function makeOwnerFoundDb(): object
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn((object) [
            'id' => 42,
            'fname' => 'Owner',
            'lname' => 'Test',
            'email' => 'owner@example.com',
            'city' => null,
            'state' => null,
            'country' => null,
            'lat' => null,
            'lon' => null,
            'website' => null,
        ]);

        return $db;
    }

    /**
     * Builds a minimal eligible + sendable car row: not sold, not bounced,
     * not suppressed, has an email and owner, not recently verified/updated,
     * under the attempt cap (so VerificationEligibility::skipReason()
     * returns null), plus the fields CarVerificationSendService::sendOne()
     * and CarVerificationEmailComposer::compose() read.
     */
    private function makeEligibleCar(int $id): object
    {
        return (object) [
            'id'                          => $id,
            'user_id'                     => 42,
            'email'                       => "owner{$id}@example.com",
            'vericode'                    => 'oldhashedvalue',
            'vericode_sent_at'            => '2025-01-01 00:00:00',
            'year'                        => '1971',
            'type'                        => '36',
            'chassis'                     => "CH{$id}",
            'chassis_override'            => 0,
            'series'                      => '2',
            'variant'                     => 'S/E',
            'color'                       => 'Red',
            'purchasedate'                => null,
            'solddate'                    => null,
            'image'                       => null,
            'website'                     => null,
            'email_bounced'               => 0,
            'email_suppressed'            => 0,
            'last_verified'               => null,
            'owner_last_updated'          => '2020-01-01 00:00:00',
            'verification_attempts'       => 0,
            'verification_attempts_since' => null,
        ];
    }

    // --- Gap 1: batch-loop failure containment ---------------------------

    #[AllowMockObjectsWithoutExpectations]
    public function testFindByIdThrowingForOneCarDoesNotAbortTheBatch(): void
    {
        $goodCar = $this->makeEligibleCar(2);

        $this->mockRepo->method('findById')->willReturnCallback(
            function (int $id) use ($goodCar) {
                if ($id === 1) {
                    throw new CarDatabaseException('DB exploded');
                }
                return $goodCar;
            }
        );
        $this->mockRepo->method('insertEmailEvent')->willReturn(1);
        $this->mockRepo->method('incrementVerificationAttempts')->willReturn(true);

        $result = $this->sender->processBatch([1, 2]);

        // The broken id lands in 'failed', not silently dropped.
        $this->assertCount(1, $result['failed']);
        $this->assertSame(1, $result['failed'][0]['car']->id);
        $this->assertSame('The car record could not be read.', $result['failed'][0]['reason']);

        // The car AFTER the broken one is still processed and sent.
        $this->assertCount(1, $result['sent']);
        $this->assertSame($goodCar, $result['sent'][0]);

        $this->assertCount(0, $result['unrecorded']);
        $this->assertCount(0, $result['skipped']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFindByIdReturningNullSkipsThatCarAndContinuesTheBatch(): void
    {
        $goodCar = $this->makeEligibleCar(2);

        $this->mockRepo->method('findById')->willReturnCallback(
            fn (int $id) => $id === 1 ? null : $goodCar
        );
        $this->mockRepo->method('insertEmailEvent')->willReturn(1);
        $this->mockRepo->method('incrementVerificationAttempts')->willReturn(true);

        $result = $this->sender->processBatch([1, 2]);

        $this->assertCount(1, $result['skipped']);
        $this->assertSame(1, $result['skipped'][0]['car']->id);
        $this->assertSame('Car no longer exists', $result['skipped'][0]['reason']);

        $this->assertCount(1, $result['sent']);
        $this->assertSame($goodCar, $result['sent'][0]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEligibilityCheckThrowingReportsDataErrorAndContinuesTheBatch(): void
    {
        // A car whose verification_attempts_since is malformed makes
        // VerificationEligibility::skipReason() throw CarValidationException
        // (an ElanRegistryException subtype) — see that class's handling of
        // an unparseable timestamp.
        $brokenCar = $this->makeEligibleCar(1);
        $brokenCar->verification_attempts_since = 'not-a-real-timestamp';

        $goodCar = $this->makeEligibleCar(2);

        $this->mockRepo->method('findById')->willReturnCallback(
            fn (int $id) => $id === 1 ? $brokenCar : $goodCar
        );
        $this->mockRepo->method('insertEmailEvent')->willReturn(1);
        $this->mockRepo->method('incrementVerificationAttempts')->willReturn(true);

        $result = $this->sender->processBatch([1, 2]);

        $this->assertCount(1, $result['skipped']);
        $this->assertSame($brokenCar, $result['skipped'][0]['car']);
        $this->assertSame('Data error — Eligibility could not be determined', $result['skipped'][0]['reason']);

        // Car 2 was still processed after car 1's eligibility check threw.
        $this->assertCount(1, $result['sent']);
        $this->assertSame($goodCar, $result['sent'][0]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendOneThrowingForOneCarReportsFailedAndContinuesTheBatch(): void
    {
        $car1 = $this->makeEligibleCar(1);
        $car2 = $this->makeEligibleCar(2);

        $this->mockRepo->method('findById')->willReturnCallback(
            fn (int $id) => $id === 1 ? $car1 : $car2
        );
        // Car 1's send blows up inside sendOne() at the code-rotation step
        // (Scope A) — the earliest point a throw can occur once sendOne()
        // is reached, and well before any transaction is left open (the
        // failure is caught and rolled back inside sendOne() itself).
        $this->mockVerifier->method('setVerificationCode')->willReturnCallback(
            function (object $car, string $code): bool {
                if ((int) $car->id === 1) {
                    throw new RuntimeException('code rotation blew up');
                }
                $car->vericode = $code;
                return true;
            }
        );
        $this->mockRepo->method('insertEmailEvent')->willReturn(1);
        $this->mockRepo->method('incrementVerificationAttempts')->willReturn(true);

        $result = $this->sender->processBatch([1, 2]);

        $this->assertCount(1, $result['failed']);
        $this->assertSame($car1, $result['failed'][0]['car']);

        // Car 2, processed after car 1's sendOne() failed internally, is
        // still sent.
        $this->assertCount(1, $result['sent']);
        $this->assertSame($car2, $result['sent'][0]);
    }

    // --- Gap 2: isUnrecorded() -> report-bucket routing -------------------

    #[AllowMockObjectsWithoutExpectations]
    public function testUnrecordedSendResultRoutesToUnrecordedBucketNotSent(): void
    {
        $car = $this->makeEligibleCar(1);

        $this->mockRepo->method('findById')->willReturn($car);
        // insertEmailEvent() throwing after a real send is exactly
        // CarVerificationSendServiceTest's "Scope C bookkeeping failure"
        // path — sendOne() still returns STATUS_SENT but isUnrecorded().
        $this->mockRepo->method('insertEmailEvent')->willThrowException(
            new RuntimeException('DB connection lost')
        );
        $this->mockRepo->method('incrementVerificationAttempts')->willReturn(true);

        $result = $this->sender->processBatch([1]);

        $this->assertCount(1, $result['unrecorded']);
        $this->assertSame($car, $result['unrecorded'][0]['car']);
        $this->assertNotNull($result['unrecorded'][0]['reason']);

        // Must NOT also (or instead) land in 'sent' — that is the exact
        // regression this test guards against.
        $this->assertCount(0, $result['sent']);
        $this->assertCount(0, $result['failed']);
        $this->assertCount(0, $result['skipped']);
        $this->assertCount(1, $GLOBALS['mockSentEmails'], 'The email must genuinely have been sent');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCleanSentResultRoutesToSentBucketNotUnrecorded(): void
    {
        $car = $this->makeEligibleCar(1);

        $this->mockRepo->method('findById')->willReturn($car);
        $this->mockRepo->method('insertEmailEvent')->willReturn(1);
        $this->mockRepo->method('incrementVerificationAttempts')->willReturn(true);

        $result = $this->sender->processBatch([1]);

        $this->assertCount(1, $result['sent']);
        $this->assertSame($car, $result['sent'][0]);
        $this->assertCount(0, $result['unrecorded']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailedSendResultRoutesToFailedBucketWithReason(): void
    {
        $car = $this->makeEligibleCar(1);

        $this->mockRepo->method('findById')->willReturn($car);
        // email() reports failure — sendOne() must report STATUS_FAILED.
        $GLOBALS['mockEmailSendResult'] = false;

        $result = $this->sender->processBatch([1]);

        $this->assertCount(1, $result['failed']);
        $this->assertSame($car, $result['failed'][0]['car']);
        $this->assertSame('The email could not be sent.', $result['failed'][0]['reason']);
        $this->assertCount(0, $result['sent']);
        $this->assertCount(0, $result['unrecorded']);
    }

    // --- Ineligible-on-recheck (routine skip, not the data-error path) ---

    #[AllowMockObjectsWithoutExpectations]
    public function testCarThatFailedEligibilityRecheckIsSkippedWithSpecificReasonAndSendOneNeverCalled(): void
    {
        $soldCar = $this->makeEligibleCar(1);
        $soldCar->solddate = '2026-01-01';

        $this->mockRepo->method('findById')->willReturn($soldCar);

        $result = $this->sender->processBatch([1]);

        $this->assertCount(1, $result['skipped']);
        $this->assertSame('Marked sold', $result['skipped'][0]['reason']);

        // sendOne() must never have been reached for a skipped car — no
        // email sent, no transaction touched.
        $this->assertCount(0, $GLOBALS['mockSentEmails']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEmptyBatchReturnsAllEmptyBuckets(): void
    {
        $this->mockRepo->expects($this->never())->method('findById');

        $result = $this->sender->processBatch([]);

        $this->assertSame(
            ['sent' => [], 'unrecorded' => [], 'skipped' => [], 'failed' => []],
            $result
        );
    }
}

// The seam CarVerificationSendService::sendOne()'s `new Owner((int)
// $carData->user_id)` falls back to when no DatabaseInterface is passed —
// same pattern as tests/unit/cars/services/CarVerificationSendServiceTest.php,
// tests/unit/security/SyncOwnerEmailOnVerifyHookTest.php, and
// tests/unit/admin/UserFormHookVerificationCardTest.php. Guarded (and reading
// the SAME $GLOBALS['hookTestDb'] key those files use) so this cannot
// collide regardless of which of these files PHPUnit loads first under
// processIsolation="false".
if (!function_exists('dbi')) {
    function dbi(): DatabaseInterface
    {
        return $GLOBALS['hookTestDb'];
    }
}
