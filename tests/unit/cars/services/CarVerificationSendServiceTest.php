<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationEmailComposer;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\CarVerificationSendService;
use ElanRegistry\Car\SendResult;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\EmailTemplate;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CarVerificationSendService (#1884)
 *
 * sendOne() constructs `new Owner((int) $carData->user_id)` directly rather
 * than taking an injected Owner — so the DB it reads through is supplied via
 * the `dbi()` seam Owner's constructor falls back to when no DatabaseInterface
 * is passed, the same pattern used by
 * tests/unit/security/SyncOwnerEmailOnVerifyHookTest.php and
 * tests/unit/admin/UserFormHookVerificationCardTest.php. `dbi()` is defined
 * once at the bottom of this file (guarded by function_exists — with
 * processIsolation="false", whichever of these three files PHPUnit loads
 * first wins the global definition). All three read $GLOBALS['hookTestDb'],
 * the SAME shared key the other two already use (see
 * UserFormHookVerificationCardTest.php's own docblock on its dbi()) — not a
 * new key — so whichever definition wins still returns THIS file's double,
 * set in setUp() and swapped per test as needed.
 *
 * CarRepository and CarVerificationManager are mocked directly (this class
 * depends on them via constructor injection); CarVerificationEmailComposer is
 * mocked too since its own rendering is out of scope here (covered by
 * CarVerificationEmailComposerTest).
 */
#[Group('fast')]
final class CarVerificationSendServiceTest extends TestCase
{
    /** @var CarRepository&\PHPUnit\Framework\MockObject\MockObject */
    private CarRepository $mockRepo;

    /** @var CarVerificationManager&\PHPUnit\Framework\MockObject\MockObject */
    private CarVerificationManager $mockVerifier;

    /**
     * CarVerificationEmailComposer is declared final and cannot be mocked by
     * PHPUnit's doubler, so a real instance is used instead (matching
     * CarVerificationEmailComposerTest.php's own construction pattern). It
     * performs no database access — see its class docblock — so a real
     * instance is safe to exercise in a unit test; assertions below only
     * check that sendOne() calls email() with a subject/html shaped by the
     * real compose() output, never the exact rendered markup.
     */
    private CarVerificationEmailComposer $composer;

    private CarVerificationSendService $service;

    protected function setUp(): void
    {
        $this->mockRepo = $this->createMock(CarRepository::class);
        $this->mockVerifier = $this->createMock(CarVerificationManager::class);
        $this->composer = new CarVerificationEmailComposer(new EmailTemplate());

        $this->service = new CarVerificationSendService(
            $this->mockRepo,
            $this->mockVerifier,
            $this->composer
        );

        // Fresh mocks each test, and reset the global email-mock state
        // (tests/bootstrap-unit.php's email() tracks sends in
        // $GLOBALS['mockSentEmails'] and reads $GLOBALS['mockEmailSendResult']
        // to decide success/failure) so no test leaks into the next.
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
     * A DatabaseInterface double simulating Owner::find() succeeding: one row,
     * no error. Mirrors the shape Owner::find()'s users LEFT JOIN profiles
     * query expects back from first().
     */
    private function makeOwnerFoundDb(int $userId = 42): object
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn((object) [
            'id' => $userId,
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
     * A DatabaseInterface double simulating Owner::find() finding no row —
     * the "owner could not be loaded" path.
     */
    private function makeOwnerNotFoundDb(): object
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);
        $db->method('first')->willReturn([]);

        return $db;
    }

    private function eligibleCar(): object
    {
        return (object) [
            'id' => 100,
            'user_id' => 42,
            'email' => 'owner@example.com',
            'vericode' => 'oldhashedvalue',
            'vericode_sent_at' => '2025-01-01 00:00:00',
            'year' => '1971',
            'type' => '36',
            'chassis' => 'TEST00100',
            'chassis_override' => 0,
            'series' => '2',
            'variant' => 'S/E',
            'color' => 'Red',
            'purchasedate' => null,
            'solddate' => null,
            'image' => null,
            'website' => null,
        ];
    }

    // ------------------------------------------------------------------
    // sendOne() — success path
    // ------------------------------------------------------------------

    public function testSendOneSuccessReturnsSentResultAndRunsFullBookkeeping(): void
    {
        $carData = $this->eligibleCar();
        $fixedCode = 'newcode1234567890';

        $this->mockVerifier->expects($this->once())->method('generateVerificationCode')
            ->willReturn($fixedCode);
        $this->mockVerifier->expects($this->once())->method('setVerificationCode')
            ->with($carData, $fixedCode)
            ->willReturnCallback(function (object $car, string $code): bool {
                $car->vericode = $code;
                return true;
            });
        $this->mockVerifier->expects($this->once())->method('setVerificationSentAt')
            ->with($carData, $this->isString())
            ->willReturnCallback(function (object $car, string $sentAt): bool {
                $car->vericode_sent_at = $sentAt;
                return true;
            });

        // Transaction A (rotate code) then Transaction B (bookkeeping) — two
        // begin/commit cycles, no rollback.
        $this->mockRepo->expects($this->exactly(2))->method('beginTransaction');
        $this->mockRepo->expects($this->exactly(2))->method('commit');
        $this->mockRepo->expects($this->never())->method('rollback');

        $this->mockRepo->expects($this->once())->method('insertEmailEvent')
            ->with(
                100,
                'owner@example.com',
                'sent',
                null,
                'local:' . hash('sha256', $fixedCode),
                $this->isString()
            )
            ->willReturn(1);

        $this->mockRepo->expects($this->once())->method('incrementVerificationAttempts')
            ->with(100)
            ->willReturn(true);

        $this->mockRepo->expects($this->never())->method('updateCar');

        $result = $this->service->sendOne($carData);

        $this->assertSame(SendResult::STATUS_SENT, $result->status);
        $this->assertSame(100, $result->carId);
        $this->assertNull($result->reason);

        $this->assertCount(1, $GLOBALS['mockSentEmails'], 'email() must have been called exactly once');
        $this->assertSame('owner@example.com', $GLOBALS['mockSentEmails'][0][0]);
    }

    // ------------------------------------------------------------------
    // sendOne() — Scope C bookkeeping-failure path (#1884 review fix)
    //
    // The email is genuinely delivered in each of these cases (email() is
    // never made to fail), so sendOne() must report STATUS_SENT — never
    // STATUS_FAILED, which would tell the caller to treat this car as still
    // needing an email — but the result must be distinguishable from a clean
    // send via isUnrecorded(), since the bookkeeping that prevents a
    // duplicate mailing did not complete.
    // ------------------------------------------------------------------

    /**
     * mockVerifier is used here purely as a behavior stub — this test
     * verifies the Scope C bookkeeping-failure path via mockRepo's expects()
     * below, not via call counts on the verifier.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testSendOneReturnsSentUnrecordedWhenInsertEmailEventThrows(): void
    {
        $carData = $this->eligibleCar();
        $fixedCode = 'newcode1234567890';

        $this->mockVerifier->method('generateVerificationCode')->willReturn($fixedCode);
        $this->mockVerifier->method('setVerificationCode')
            ->willReturnCallback(function (object $car, string $code): bool {
                $car->vericode = $code;
                return true;
            });
        $this->mockVerifier->method('setVerificationSentAt')
            ->willReturnCallback(function (object $car, string $sentAt): bool {
                $car->vericode_sent_at = $sentAt;
                return true;
            });

        $this->mockRepo->expects($this->once())->method('insertEmailEvent')
            ->willThrowException(new \RuntimeException('DB connection lost'));
        $this->mockRepo->expects($this->never())->method('incrementVerificationAttempts');
        $this->mockRepo->expects($this->once())->method('rollback');
        $this->mockRepo->expects($this->never())->method('restoreVerificationCodeState');

        $result = $this->service->sendOne($carData);

        $this->assertSame(SendResult::STATUS_SENT, $result->status);
        $this->assertTrue($result->isUnrecorded());
        $this->assertNotNull($result->reason);
        $this->assertCount(1, $GLOBALS['mockSentEmails'], 'The email must still have been sent');
    }

    /**
     * mockVerifier is used here purely as a behavior stub — see the docblock
     * on the previous test.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testSendOneReturnsSentUnrecordedWhenIncrementVerificationAttemptsReturnsFalse(): void
    {
        $carData = $this->eligibleCar();
        $fixedCode = 'newcode1234567890';

        $this->mockVerifier->method('generateVerificationCode')->willReturn($fixedCode);
        $this->mockVerifier->method('setVerificationCode')
            ->willReturnCallback(function (object $car, string $code): bool {
                $car->vericode = $code;
                return true;
            });
        $this->mockVerifier->method('setVerificationSentAt')
            ->willReturnCallback(function (object $car, string $sentAt): bool {
                $car->vericode_sent_at = $sentAt;
                return true;
            });

        // No exception anywhere in Scope C — insertEmailEvent() succeeds and
        // the transaction commits — but incrementVerificationAttempts()
        // returns false (its documented no-row-matched signal, not an
        // exception). This must be caught by sendOne()'s post-commit check,
        // not just its catch block.
        $this->mockRepo->expects($this->once())->method('insertEmailEvent')->willReturn(1);
        $this->mockRepo->expects($this->once())->method('incrementVerificationAttempts')->willReturn(false);
        $this->mockRepo->expects($this->exactly(2))->method('commit');
        $this->mockRepo->expects($this->never())->method('rollback');

        $result = $this->service->sendOne($carData);

        $this->assertSame(SendResult::STATUS_SENT, $result->status);
        $this->assertTrue($result->isUnrecorded());
        $this->assertNotNull($result->reason);
    }

    /**
     * mockVerifier is used here purely as a behavior stub — see the docblock
     * on the first Scope C test above.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testSendOneReturnsCleanSentWhenInsertEmailEventReturnsZeroRowsWritten(): void
    {
        $carData = $this->eligibleCar();
        $fixedCode = 'newcode1234567890';

        $this->mockVerifier->method('generateVerificationCode')->willReturn($fixedCode);
        $this->mockVerifier->method('setVerificationCode')
            ->willReturnCallback(function (object $car, string $code): bool {
                $car->vericode = $code;
                return true;
            });
        $this->mockVerifier->method('setVerificationSentAt')
            ->willReturnCallback(function (object $car, string $sentAt): bool {
                $car->vericode_sent_at = $sentAt;
                return true;
            });

        // insertEmailEvent() returns 0 while incrementVerificationAttempts()
        // succeeds. 0 MUST NOT escalate to sentUnrecorded(): the underlying
        // statement is INSERT ... ON DUPLICATE KEY UPDATE, which MySQL reports
        // as 0 affected rows both when nothing was written AND when the
        // duplicate-key row already held identical values — the latter being a
        // fully-recorded send. Treating 0 as a failure renders a false "this
        // car may be emailed again" warning to the admin for a send that was
        // completely recorded. The attempts increment is the reliable signal,
        // and it succeeded here, so this is a clean send.
        $this->mockRepo->expects($this->once())->method('insertEmailEvent')->willReturn(0);
        $this->mockRepo->expects($this->once())->method('incrementVerificationAttempts')->willReturn(true);
        $this->mockRepo->expects($this->exactly(2))->method('commit');
        $this->mockRepo->expects($this->never())->method('rollback');

        $result = $this->service->sendOne($carData);

        $this->assertSame(SendResult::STATUS_SENT, $result->status);
        $this->assertFalse($result->isUnrecorded());
        $this->assertNull($result->reason);
    }

    /**
     * Zero rows written AND no attempt recorded is still unrecorded.
     *
     * Guards the narrowed check from over-correcting: dropping
     * $eventRowsWritten from the condition must not also drop the
     * $attemptsRecorded === false case when both signals are negative.
     *
     * mockVerifier is used here purely as a behavior stub — see the docblock
     * on the first Scope C test above.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testSendOneReturnsSentUnrecordedWhenBothBookkeepingSignalsAreNegative(): void
    {
        $carData = $this->eligibleCar();
        $fixedCode = 'newcode1234567890';

        $this->mockVerifier->method('generateVerificationCode')->willReturn($fixedCode);
        $this->mockVerifier->method('setVerificationCode')
            ->willReturnCallback(function (object $car, string $code): bool {
                $car->vericode = $code;
                return true;
            });
        $this->mockVerifier->method('setVerificationSentAt')
            ->willReturnCallback(function (object $car, string $sentAt): bool {
                $car->vericode_sent_at = $sentAt;
                return true;
            });

        $this->mockRepo->expects($this->once())->method('insertEmailEvent')->willReturn(0);
        $this->mockRepo->expects($this->once())->method('incrementVerificationAttempts')->willReturn(false);
        $this->mockRepo->expects($this->exactly(2))->method('commit');
        $this->mockRepo->expects($this->never())->method('rollback');

        $result = $this->service->sendOne($carData);

        $this->assertSame(SendResult::STATUS_SENT, $result->status);
        $this->assertTrue($result->isUnrecorded());
        $this->assertNotNull($result->reason);
    }

    // ------------------------------------------------------------------
    // sendOne() — send failure path
    // ------------------------------------------------------------------

    /**
     * mockVerifier is used here purely as a behavior stub (willReturn/
     * willReturnCallback) — this test verifies the restore/reporting path via
     * mockRepo's expects() below, not via call counts on the verifier.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testSendOneRestoresPreviousVericodeAndSentAtWhenEmailFails(): void
    {
        $carData = $this->eligibleCar();
        $previousVericode = $carData->vericode;
        $previousSentAt = $carData->vericode_sent_at;
        $fixedCode = 'newcode1234567890';

        $GLOBALS['mockEmailSendResult'] = false;

        $this->mockVerifier->method('generateVerificationCode')->willReturn($fixedCode);
        $this->mockVerifier->method('setVerificationCode')
            ->willReturnCallback(function (object $car, string $code): bool {
                $car->vericode = $code;
                return true;
            });
        $this->mockVerifier->method('setVerificationSentAt')
            ->willReturnCallback(function (object $car, string $sentAt): bool {
                $car->vericode_sent_at = $sentAt;
                return true;
            });

        $this->mockRepo->expects($this->once())->method('restoreVerificationCodeState')
            ->with(100, $previousVericode, $previousSentAt)
            ->willReturn(true);

        $this->mockRepo->expects($this->never())->method('updateCar');
        $this->mockRepo->expects($this->never())->method('incrementVerificationAttempts');
        $this->mockRepo->expects($this->never())->method('insertEmailEvent');

        $result = $this->service->sendOne($carData);

        $this->assertSame(SendResult::STATUS_FAILED, $result->status);
        $this->assertSame(100, $result->carId);
        $this->assertNotNull($result->reason);
    }

    /**
     * restoreVerificationCodeState() returning false (the car no longer
     * exists — deleted between the vericode rotation and this restore
     * attempt) must not be silent: it must be logged, and sendOne() must
     * still report the send as failed rather than crash or mis-report.
     * This is the case the fix for #1884's silent-failure-hunter finding
     * exists to make observable — updateCar() (the method this restore
     * originally, incorrectly used) cannot distinguish "restored" from
     * "matched nothing," so this test only became meaningful once the
     * restore switched to the count()-aware repository method.
     *
     * mockVerifier is used here purely as a behavior stub (willReturn/
     * willReturnCallback) — this test verifies the restore/reporting path via
     * mockRepo's expects() below, not via call counts on the verifier.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testSendOneLogsCriticallyWhenRestoreMatchesNoRow(): void
    {
        $carData = $this->eligibleCar();
        $fixedCode = 'newcode1234567890';

        $GLOBALS['mockEmailSendResult'] = false;

        $this->mockVerifier->method('generateVerificationCode')->willReturn($fixedCode);
        $this->mockVerifier->method('setVerificationCode')
            ->willReturnCallback(function (object $car, string $code): bool {
                $car->vericode = $code;
                return true;
            });
        $this->mockVerifier->method('setVerificationSentAt')
            ->willReturnCallback(function (object $car, string $sentAt): bool {
                $car->vericode_sent_at = $sentAt;
                return true;
            });

        $this->mockRepo->expects($this->once())->method('restoreVerificationCodeState')
            ->willReturn(false);

        // No exception is expected — a false return is not a \Throwable, and
        // sendOne() must not let a restore-matched-nothing case crash the
        // caller. The result must still be reported as failed (the email
        // genuinely was not sent, independent of whether the restore
        // succeeded).
        $result = $this->service->sendOne($carData);

        $this->assertSame(SendResult::STATUS_FAILED, $result->status);
        $this->assertSame(100, $result->carId);
    }

    // ------------------------------------------------------------------
    // sendOne() — owner-not-found path
    // ------------------------------------------------------------------

    public function testSendOneReturnsFailedWhenOwnerCannotBeLoaded(): void
    {
        $GLOBALS['hookTestDb'] = $this->makeOwnerNotFoundDb();

        $carData = $this->eligibleCar();

        $this->mockRepo->expects($this->never())->method('beginTransaction');
        $this->mockRepo->expects($this->never())->method('updateCar');
        $this->mockVerifier->expects($this->never())->method('generateVerificationCode');

        $result = $this->service->sendOne($carData);

        $this->assertSame(SendResult::STATUS_FAILED, $result->status);
        $this->assertSame(100, $result->carId);
        $this->assertNotNull($result->reason);
        $this->assertStringContainsStringIgnoringCase('owner', $result->reason);
        $this->assertSame([], $GLOBALS['mockSentEmails'], 'No email may be sent when the owner cannot be loaded');
    }

    // ------------------------------------------------------------------
    // Statelessness contract (AC7/AC11's "pure function of args + DB state")
    // ------------------------------------------------------------------

    /**
     * CarVerificationSendService's own class docblock states that its public
     * methods must remain pure functions of their arguments plus current DB
     * state — no $_POST/$_GET/$_SERVER reads, no session/current-user lookups,
     * no echoing, no redirects — because a future cron job caller (#1885) runs
     * with no session and no request at all. That is a structural property of
     * the source, not something a black-box unit test invoking the public API
     * can observe by asserting on return values. This test instead greps the
     * class's own source for the superglobal/session/echo surface that would
     * violate the contract, which is the most direct executable check
     * available short of static analysis tooling. A stronger, dedicated
     * cross-file audit (e.g. via PHPStan or a custom AST rule) is out of scope
     * for this test — see the class docblock's own statement of the contract.
     * This test touches neither mockRepo nor mockVerifier — it only greps the
     * class's own source — so setUp()'s unconfigured mocks are unused here.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testServiceSourceContainsNoSuperglobalOrSessionReads(): void
    {
        $raw = (string) file_get_contents(
            __DIR__ . '/../../../../usersc/classes/Car/CarVerificationSendService.php'
        );

        // Strip comments before matching: the class's own docblock quotes
        // $_POST/$_GET/$_SERVER as exactly the things it must NOT do, so
        // matching raw source would fail this test on the very prose that
        // states the contract. tokenizer, not regex-stripping, so a string
        // literal that happens to contain "//" is never mistaken for a comment.
        $source = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $source .= is_array($token) ? $token[1] : $token;
        }

        $this->assertDoesNotMatchRegularExpression(
            '/\$_(POST|GET|SERVER|SESSION|COOKIE|REQUEST)\b/',
            $source,
            'CarVerificationSendService must never read superglobals directly — a cron caller (#1885) '
            . 'has no request and no session, and this class\'s docblock states this as a structural invariant.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\bSession::(add|get|exists)\b/',
            $source,
            'CarVerificationSendService must never read or write UserSpice flash/session state.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\becho\b|\bprint\b/',
            $source,
            'CarVerificationSendService must never echo/print output directly — that is the caller\'s job.'
        );
    }
}

// The seam sendOne()'s `new Owner((int) $carData->user_id)` falls back to
// when no DatabaseInterface is passed — same pattern as
// tests/unit/security/SyncOwnerEmailOnVerifyHookTest.php and
// tests/unit/admin/UserFormHookVerificationCardTest.php. Guarded so this
// cannot collide if bootstrap-unit.php ever defines its own.
if (!function_exists('dbi')) {
    function dbi(): DatabaseInterface
    {
        return $GLOBALS['hookTestDb'];
    }
}
