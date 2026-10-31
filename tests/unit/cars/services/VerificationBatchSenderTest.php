<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationEmailComposer;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\CarVerificationSendService;
use ElanRegistry\Car\VerificationBatchSender;
use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\EmailTemplate;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\BrevoSiteRoot;

require_once __DIR__ . '/../../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../../Support/VerificationSettingsFakeDatabase.php';

/**
 * Unit tests for VerificationBatchSender (#1884, PR review gap-closing pass)
 *
 * Covers two behaviors of the `verification_send_batch` POST handler in
 * app/admin/index.php that were previously untested because that file
 * cannot be require()'d directly in a unit test (it needs the full
 * UserSpice bootstrap):
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
    /** Mixed case, so the address checks must ignore case. */
    private const LEAK_ADDRESS = 'Leak-Test-2420@Example.org';

    private const LEAK_LOCAL_PART = 'Leak-Test-2420';

    /** @var CarRepository&\PHPUnit\Framework\MockObject\MockObject */
    private CarRepository $mockRepo;

    /** @var CarVerificationManager&\PHPUnit\Framework\MockObject\MockObject */
    private CarVerificationManager $mockVerifier;

    private CarVerificationSendService $sendSvc;

    private VerificationBatchSender $sender;

    /** Temp site root that holds the sendinblue plugin files, or null when none was made. */
    private ?BrevoSiteRoot $brevoSiteRoot = null;

    protected function setUp(): void
    {
        $this->mockRepo = $this->createMock(CarRepository::class);
        $this->mockVerifier = $this->createMock(CarVerificationManager::class);
        // Every owner has a profile with all three flags clear. The send
        // service reads it before it mails (#2101).
        $this->mockRepo->method('effectiveEmailFlags')->willReturn(
            new \ElanRegistry\Car\EffectiveEmailFlags(true, false, null, false, null, null, null, false, null, null, 'owner@example.com')
        );
        $composer = new CarVerificationEmailComposer(new EmailTemplate());

        $this->sendSvc = new CarVerificationSendService(
            $this->mockRepo,
            $this->mockVerifier,
            $composer,
            $this->makeBrevoReadySettings()
        );

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
        $GLOBALS['mockLogEntries'] = [];
        unset($GLOBALS['mockEmailSendResult']);
        $GLOBALS['hookTestDb'] = $this->makeOwnerFoundDb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['mockSentEmails'], $GLOBALS['mockEmailSendResult'], $GLOBALS['hookTestDb'], $GLOBALS['mockLogEntries']);
        $this->brevoSiteRoot?->remove();
        parent::tearDown();
    }

    /** Brevo-ready settings on a fresh temp site root. tearDown() removes the root. */
    private function makeBrevoReadySettings(): VerificationSettings
    {
        $this->brevoSiteRoot = BrevoSiteRoot::create('batch_sender_test_');

        return $this->brevoSiteRoot->readySettings();
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
     * Builds a minimal eligible + sendable car row: not sold, has an email and owner, not recently verified/updated,
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

    // --- Brevo not ready: the whole batch fails closed (#2184, #2330) ----

    #[AllowMockObjectsWithoutExpectations]
    public function testBatchWhileBrevoIsNotReadySendsNothingFailsEveryCarAndKeepsEveryVericode(): void
    {
        $this->brevoSiteRoot?->writePluginVersion('1.6.2');
        $car1 = $this->makeEligibleCar(1);
        $car2 = $this->makeEligibleCar(2);
        $car3 = $this->makeEligibleCar(3);
        $cars = [1 => $car1, 2 => $car2, 3 => $car3];

        $this->mockRepo->method('findById')->willReturnCallback(fn (int $id) => $cars[$id]);
        $this->mockRepo->expects($this->never())->method('beginTransaction');
        $this->mockRepo->expects($this->never())->method('updateCar');
        $this->mockRepo->expects($this->never())->method('insertEmailEvent');
        $this->mockRepo->expects($this->never())->method('incrementVerificationAttempts');

        $result = $this->sender->processBatch([1, 2, 3]);

        $this->assertSame([], $result['sent']);
        $this->assertSame([], $result['unrecorded']);
        $this->assertSame([], $result['skipped']);
        $this->assertCount(3, $result['failed'], 'Every car must land in failed');
        foreach ([1, 2, 3] as $index => $id) {
            $this->assertSame($cars[$id], $result['failed'][$index]['car']);
            $this->assertStringStartsWith('No email was sent. ', $result['failed'][$index]['reason']);
            $this->assertStringContainsString('1.6.2', $result['failed'][$index]['reason'], 'The reason must name the installed version');
            $this->assertSame('oldhashedvalue', $cars[$id]->vericode, "The vericode of car {$id} must not be rotated");
            $this->assertSame('2025-01-01 00:00:00', $cars[$id]->vericode_sent_at, "vericode_sent_at of car {$id} must not change");
        }
        $this->assertSame([], $GLOBALS['mockSentEmails'], 'No email may be sent while Brevo is not ready');
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

    // --- No address in the catch-site log lines (#2420) ------------------

    #[AllowMockObjectsWithoutExpectations]
    public function testFindByIdThrowLogsTheClassAndNotTheAddressInTheMessage(): void
    {
        $this->mockRepo->method('findById')->willThrowException(
            new CarDatabaseException('No row for owner ' . self::LEAK_ADDRESS)
        );

        $this->sender->processBatch([1]);

        $this->assertLogLineNamesClassWithoutAddress(
            'Verification send: findById threw for car 1',
            CarDatabaseException::class
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEligibilityThrowLogsTheClassAndNotTheAddressInTheMessage(): void
    {
        // skipReason() echoes a malformed verification_attempts_since value
        // into its CarValidationException message.
        $car = $this->makeEligibleCar(1);
        $car->verification_attempts_since = self::LEAK_ADDRESS;
        $this->mockRepo->method('findById')->willReturn($car);

        $this->sender->processBatch([1]);

        $this->assertLogLineNamesClassWithoutAddress(
            'Verification send: eligibility re-check failed for car 1',
            CarValidationException::class
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendOneThrowLogsTheClassAndNotTheAddressInTheMessage(): void
    {
        // effectiveEmailFlags() runs inside sendOne() outside its own catches,
        // so this throw reaches the batch sender. setUp() stubs it to succeed,
        // so this test needs a repo of its own.
        $repo = $this->createMock(CarRepository::class);
        $repo->method('findById')->willReturn($this->makeEligibleCar(1));
        $repo->method('effectiveEmailFlags')->willThrowException(
            new RuntimeException('Flags read failed for ' . self::LEAK_ADDRESS)
        );
        $sendSvc = new CarVerificationSendService(
            $repo,
            $this->mockVerifier,
            new CarVerificationEmailComposer(new EmailTemplate()),
            $this->brevoSiteRoot?->readySettings()
        );

        $result = (new VerificationBatchSender($repo, $sendSvc, 1))->processBatch([1]);

        $this->assertCount(1, $result['failed']);
        $this->assertLogLineNamesClassWithoutAddress(
            'Verification send: sendOne threw for car 1',
            RuntimeException::class
        );
    }

    /**
     * Assert that the expected catch-site line exists and names the class,
     * and that no log entry holds the address or its local part
     *
     * @param string $prefix Start of the expected log line
     * @param class-string $exceptionClass Class the line must name
     */
    private function assertLogLineNamesClassWithoutAddress(string $prefix, string $exceptionClass): void
    {
        /** @var list<array{user_id: mixed, category: mixed, message: mixed, metadata: mixed}> $entries */
        $entries = $GLOBALS['mockLogEntries'] ?? [];

        // A check of zero entries proves nothing, so the line must exist first.
        $matches = array_values(array_filter(
            $entries,
            fn (array $entry): bool => $entry['category'] === LogCategories::LOG_CATEGORY_CAR_VERIFICATION
                && str_starts_with((string) $entry['message'], $prefix)
        ));
        $this->assertCount(1, $matches, "Expected one log line that starts with '{$prefix}'");

        foreach ($entries as $entry) {
            $text = (string) $entry['message'] . ' ' . var_export($entry['metadata'], true);
            foreach ([self::LEAK_ADDRESS, self::LEAK_LOCAL_PART] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $text);
            }
        }

        $this->assertStringContainsString($exceptionClass . ' (code ', (string) $matches[0]['message']);
    }

    // --- capToBatchSize() (#2330) ----------------------------------------

    /** @return array<string, array{0: int, 1: int}> [id count, batch size], not truncated */
    public static function notTruncatedProvider(): array
    {
        return [
            'equal to the batch size' => [5, 5],
            'under the batch size'    => [3, 5],
            'empty'                   => [0, 5],
            'size 1, one id'          => [1, 1],
        ];
    }

    #[AllowMockObjectsWithoutExpectations]
    #[\PHPUnit\Framework\Attributes\DataProvider('notTruncatedProvider')]
    public function testCapToBatchSizeKeepsEveryIdAndReturnsNullMessagesWhenNotOver(int $count, int $size): void
    {
        $ids = $count > 0 ? range(101, 100 + $count) : [];

        $cap = VerificationBatchSender::capToBatchSize($ids, $size);

        $this->assertSame($ids, $cap['ids']);
        $this->assertNull($cap['logMessage']);
        $this->assertNull($cap['adminMessage']);
    }

    /** @return array<string, array{0: list<int>, 1: int, 2: list<int>, 3: string, 4: string}> */
    public static function truncatedProvider(): array
    {
        return [
            'one over' => [
                [10, 20, 30, 40, 50, 60],
                5,
                [10, 20, 30, 40, 50],
                'Verification send: car_ids[] carried 6 ids, exceeding the configured batch size of 5; truncated to the first 5.',
                'Only the first 5 cars were sent — the request exceeded the configured batch size.',
            ],
            'many over' => [
                range(1, 200),
                5,
                [1, 2, 3, 4, 5],
                'Verification send: car_ids[] carried 200 ids, exceeding the configured batch size of 5; truncated to the first 5.',
                'Only the first 5 cars were sent — the request exceeded the configured batch size.',
            ],
            'size 1' => [
                [7, 8],
                1,
                [7],
                'Verification send: car_ids[] carried 2 ids, exceeding the configured batch size of 1; truncated to the first 1.',
                'Only the first 1 cars were sent — the request exceeded the configured batch size.',
            ],
            // A size below 1 is clamped to 1. Unclamped, array_slice() keeps
            // nothing for 0 and cuts from the end for -1.
            'size 0 clamps to 1' => [
                [7, 8, 9],
                0,
                [7],
                'Verification send: car_ids[] carried 3 ids, exceeding the configured batch size of 1; truncated to the first 1.',
                'Only the first 1 cars were sent — the request exceeded the configured batch size.',
            ],
            'size -1 clamps to 1' => [
                [7, 8, 9],
                -1,
                [7],
                'Verification send: car_ids[] carried 3 ids, exceeding the configured batch size of 1; truncated to the first 1.',
                'Only the first 1 cars were sent — the request exceeded the configured batch size.',
            ],
        ];
    }

    /**
     * @param list<int> $ids
     * @param list<int> $expectedIds
     */
    #[AllowMockObjectsWithoutExpectations]
    #[\PHPUnit\Framework\Attributes\DataProvider('truncatedProvider')]
    public function testCapToBatchSizeTruncatesToTheFirstNIdsAndNamesBothNumbers(
        array $ids,
        int $size,
        array $expectedIds,
        string $expectedLog,
        string $expectedAdmin
    ): void {
        $cap = VerificationBatchSender::capToBatchSize($ids, $size);

        $this->assertSame($expectedIds, $cap['ids']);
        $this->assertSame($expectedLog, $cap['logMessage']);
        $this->assertSame($expectedAdmin, $cap['adminMessage']);
    }

    /**
     * The PHPDoc documents no failure mode: the input is array<mixed> and the
     * result is a list. Values keep their type and order, and keys are dropped.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testCapToBatchSizeKeepsMixedTypeValuesAndReturnsAListFromAnAssociativeArray(): void
    {
        $ids = ['b' => '12', 'a' => 7, 'z' => 'abc', 9 => null, 3 => ['x']];

        $cap = VerificationBatchSender::capToBatchSize($ids, 4);

        $this->assertSame(['12', 7, 'abc', null], $cap['ids']);
        $this->assertTrue(array_is_list($cap['ids']));
        $this->assertStringContainsString('carried 5 ids', (string) $cap['logMessage']);

        $untouched = VerificationBatchSender::capToBatchSize(['k' => 'v', 'j' => 2], 5);
        $this->assertSame(['v', 2], $untouched['ids']);
        $this->assertNull($untouched['logMessage']);
    }

    // --- filterCarIds() (#2457) ------------------------------------------

    /** @return array<string, array{0: mixed}> One submitted value that is not a positive car id */
    public static function rejectedCarIdProvider(): array
    {
        return [
            'nested array'           => [['5']],
            'empty nested array'     => [[]],
            'object'                 => [new \stdClass()],
            'null'                   => [null],
            'true'                   => [true],
            'false'                  => [false],
            'float'                  => [3.0],
            'non-numeric string'     => ['abc'],
            'numeric prefix string'  => ['3abc'],
            'int zero'               => [0],
            'negative int'           => [-1],
            'string zero'            => ['0'],
            'negative string'        => ['-4'],
            'decimal string'         => ['1.5'],
            'leading space string'   => [' 3'],
            'plus sign string'       => ['+3'],
            'leading zero string'    => ['03'],
            'past PHP_INT_MAX'       => ['99999999999999999999'],
            'empty string'           => [''],
        ];
    }

    #[AllowMockObjectsWithoutExpectations]
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedCarIdProvider')]
    public function testFilterCarIdsRejectsAValueThatIsNotAPositiveCarId(mixed $value): void
    {
        $result = VerificationBatchSender::filterCarIds([$value]);

        $this->assertSame([], $result['ids']);
        $this->assertSame([$value], $result['rejected']);
    }

    /** @return array<string, array{0: int|string, 1: int}> [submitted value, kept car id] */
    public static function acceptedCarIdProvider(): array
    {
        return [
            'int'            => [42, 42],
            'numeric string' => ['42', 42],
            'string one'     => ['1', 1],
        ];
    }

    #[AllowMockObjectsWithoutExpectations]
    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedCarIdProvider')]
    public function testFilterCarIdsKeepsAPositiveCarIdAsAnInt(int|string $value, int $expected): void
    {
        $result = VerificationBatchSender::filterCarIds([$value]);

        $this->assertSame([$expected], $result['ids']);
        $this->assertSame([], $result['rejected']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFilterCarIdsKeepsTheSubmittedOrderOfBothLists(): void
    {
        $result = VerificationBatchSender::filterCarIds(['30', ['x'], 10, '0', '20', null, 5]);

        $this->assertSame([30, 10, 20, 5], $result['ids']);
        $this->assertSame([['x'], '0', null], $result['rejected']);
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
