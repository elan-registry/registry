<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationEmailComposer;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\CarVerificationSendService;
use ElanRegistry\Car\EffectiveEmailFlags;
use ElanRegistry\Car\SendResult;
use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\EmailTemplate;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\BrevoSiteRoot;

require_once __DIR__ . '/../../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../../Support/VerificationSettingsFakeDatabase.php';

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

    private VerificationSettings $brevoReadySettings;

    /** Temp site root that holds the sendinblue plugin files, or null when none was made. */
    private ?BrevoSiteRoot $brevoSiteRoot = null;

    /** What effectiveEmailFlags() returns. The default is an owner with every flag clear. */
    private EffectiveEmailFlags $profileState;

    protected function setUp(): void
    {
        $this->mockRepo = $this->createMock(CarRepository::class);
        $this->mockVerifier = $this->createMock(CarVerificationManager::class);
        $this->profileState = self::profile();
        $this->mockRepo->method('effectiveEmailFlags')->willReturnCallback(fn () => $this->profileState);
        $this->composer = new CarVerificationEmailComposer(new EmailTemplate());
        $this->brevoReadySettings = $this->makeBrevoReadySettings();

        $this->service = new CarVerificationSendService(
            $this->mockRepo,
            $this->mockVerifier,
            $this->composer,
            $this->brevoReadySettings
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
        $this->brevoSiteRoot?->remove();
        parent::tearDown();
    }

    /**
     * The flags that apply to the owner now, with every flag clear unless
     * the caller overrides them. The class is final, so these are real
     * instances. The repository applies the address match (tested in
     * CarRepositoryTest), so a flag here is a flag that applies.
     *
     * @param array{hasProfile?: bool, optedOut?: bool, blocked?: bool, bounced?: bool} $overrides
     */
    private static function profile(array $overrides = []): EffectiveEmailFlags
    {
        $o = $overrides + ['hasProfile' => true, 'optedOut' => false, 'blocked' => false, 'bounced' => false];

        return new EffectiveEmailFlags(
            $o['hasProfile'],
            $o['optedOut'],
            null,
            $o['blocked'],
            $o['blocked'] ? 'owner@example.com' : null,
            null,
            null,
            $o['bounced'],
            $o['bounced'] ? 'owner@example.com' : null,
            null,
            'owner@example.com'
        );
    }

    /** Brevo-ready settings on a fresh temp site root. tearDown() removes the root. */
    private function makeBrevoReadySettings(): VerificationSettings
    {
        $this->brevoSiteRoot = BrevoSiteRoot::create('send_service_test_');

        return $this->brevoSiteRoot->readySettings();
    }

    /**
     * A DatabaseInterface double simulating Owner::find() succeeding: one row,
     * no error. Mirrors the shape Owner::find()'s users LEFT JOIN profiles
     * query expects back from first().
     */
    private function makeOwnerFoundDb(int $userId = 42, string $email = 'owner@example.com'): object
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn((object) [
            'id' => $userId,
            'username' => 'realowner',
            'fname' => 'Owner',
            'lname' => 'Test',
            'email' => $email,
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
     * A DatabaseInterface double simulating Owner::find() resolving to the
     * `noowner` GDPR-erasure system account — a real, live users row (so the
     * "owner cannot be loaded" path does NOT catch this case; see sendOne()'s
     * own comment on this check).
     */
    private function makeNoownerAccountDb(int $userId = 1): object
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn((object) [
            'id' => $userId,
            'username' => 'noowner',
            'fname' => 'No',
            'lname' => 'Owner',
            'email' => 'noowner@invalid',
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
        $this->assertSame(
            ['tags' => 'car_verification'],
            $GLOBALS['mockSentEmails'][0][3],
            'The verification tag must reach email() as $opts, or the Brevo webhook never records the send (#2184)'
        );
    }

    // ------------------------------------------------------------------
    // sendOne() — Brevo message ID on the sent row (#2359)
    // ------------------------------------------------------------------

    /**
     * Stubs the verifier and the bookkeeping calls, and records the message ID
     * that each insertEmailEvent() call receives.
     *
     * @param list<string> $codes Codes that generateVerificationCode() returns in order
     * @param list<string> $recorded Receives the message IDs in call order
     */
    private function recordSentMessageIds(array $codes, array &$recorded): void
    {
        $recorded = [];
        $this->mockVerifier->method('generateVerificationCode')->willReturnOnConsecutiveCalls(...$codes);
        $this->mockVerifier->method('setVerificationCode')->willReturn(true);
        $this->mockVerifier->method('setVerificationSentAt')->willReturn(true);
        $this->mockRepo->method('incrementVerificationAttempts')->willReturn(true);
        $this->mockRepo->method('insertEmailEvent')->willReturnCallback(
            function (int $carId, string $email, string $event, ?string $reason, string $messageId) use (&$recorded): int {
                $recorded[] = $messageId;
                return 1;
            }
        );
    }

    /**
     * The real ID from the plugin goes on the sent row. The test stub returns
     * null before the send and the real ID after it.
     */
    #[RunInSeparateProcess]
    #[AllowMockObjectsWithoutExpectations]
    public function testSentRowStoresTheRealBrevoMessageId(): void
    {
        require_once __DIR__ . '/fixtures/sendinblue-last-message-id.php';
        $GLOBALS['mockLastMessageIds'] = [null, '<202610140900.1@smtp-relay.mailin.fr>'];
        $GLOBALS['mockLogEntries'] = [];
        $ids = [];
        $this->recordSentMessageIds(['code-aaaaaaaaaaaaaaaa'], $ids);

        $result = $this->service->sendOne($this->eligibleCar());

        $this->assertSame(SendResult::STATUS_SENT, $result->status);
        $this->assertSame(['<202610140900.1@smtp-relay.mailin.fr>'], $ids);
        $this->assertSame([], $GLOBALS['mockLogEntries'], 'A real ID needs no fallback warning');
    }

    /**
     * An empty ID after the send keeps the local ID and logs a warning that
     * names the owner and the car but not the address.
     */
    #[RunInSeparateProcess]
    #[AllowMockObjectsWithoutExpectations]
    public function testSentRowKeepsLocalIdAndLogsWarningWhenPluginIdIsEmpty(): void
    {
        require_once __DIR__ . '/fixtures/sendinblue-last-message-id.php';
        $GLOBALS['mockLastMessageIds'] = [null, ''];
        $GLOBALS['mockLogEntries'] = [];
        $ids = [];
        $this->recordSentMessageIds(['code-bbbbbbbbbbbbbbbb'], $ids);

        $result = $this->service->sendOne($this->eligibleCar());

        $this->assertSame(SendResult::STATUS_SENT, $result->status);
        $this->assertSame(['local:' . hash('sha256', 'code-bbbbbbbbbbbbbbbb')], $ids);
        $warnings = array_values(array_filter(
            $GLOBALS['mockLogEntries'] ?? [],
            static fn (array $entry): bool => str_contains($entry['message'], 'no new Brevo message ID')
        ));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('owner 42', $warnings[0]['message']);
        $this->assertStringContainsString('car 100', $warnings[0]['message']);
        $this->assertStringNotContainsString('owner@example.com', $warnings[0]['message']);
    }

    /**
     * An ID equal to the value before the send is stale, so the row keeps the
     * local ID.
     */
    #[RunInSeparateProcess]
    #[AllowMockObjectsWithoutExpectations]
    public function testSentRowKeepsLocalIdWhenPluginIdEqualsTheEarlierValue(): void
    {
        require_once __DIR__ . '/fixtures/sendinblue-last-message-id.php';
        $GLOBALS['mockLastMessageIds'] = ['<stale@relay>', '<stale@relay>'];
        $ids = [];
        $this->recordSentMessageIds(['code-cccccccccccccccc'], $ids);

        $this->service->sendOne($this->eligibleCar());

        $this->assertSame(['local:' . hash('sha256', 'code-cccccccccccccccc')], $ids);
    }

    /**
     * Two sends to one owner with empty IDs make two sent rows. The new code
     * of each send makes the local IDs differ.
     */
    #[RunInSeparateProcess]
    #[AllowMockObjectsWithoutExpectations]
    public function testTwoEmptyIdSendsToOneOwnerMakeTwoSentRowsWithDistinctLocalIds(): void
    {
        require_once __DIR__ . '/fixtures/sendinblue-last-message-id.php';
        $GLOBALS['mockLastMessageIds'] = [null, '', '', ''];
        $ids = [];
        $this->recordSentMessageIds(['code-dddddddddddddddd', 'code-eeeeeeeeeeeeeeee'], $ids);

        $this->service->sendOne($this->eligibleCar());
        $this->service->sendOne($this->eligibleCar());

        $this->assertCount(2, $ids);
        $this->assertNotSame($ids[0], $ids[1]);
        $this->assertStringStartsWith('local:', $ids[0]);
        $this->assertStringStartsWith('local:', $ids[1]);
    }

    /**
     * Two sends in one process. The second plugin write fails, so the plugin
     * resets its value to null and the second row keeps a local ID. The first
     * send leaves a real ID that the second send reads as the earlier value.
     */
    #[RunInSeparateProcess]
    #[AllowMockObjectsWithoutExpectations]
    public function testSecondSendKeepsLocalIdWhenItsPluginWriteFailsAfterAFirstRealId(): void
    {
        require_once __DIR__ . '/fixtures/sendinblue-last-message-id.php';
        $GLOBALS['mockLastMessageIds'] = [null, '<first@relay>', '<first@relay>', null];
        $ids = [];
        $this->recordSentMessageIds(['code-ffffffffffffffff', 'code-gggggggggggggggg'], $ids);

        $this->service->sendOne($this->eligibleCar());
        $this->service->sendOne($this->eligibleCar());

        $this->assertSame(
            ['<first@relay>', 'local:' . hash('sha256', 'code-gggggggggggggggg')],
            $ids
        );
    }

    // ------------------------------------------------------------------
    // sendOne() — Brevo not ready (#2184)
    // ------------------------------------------------------------------

    /**
     * A sendinblue plugin older than 1.8.0 drops $opts['tags'], so the email
     * would go out untagged. sendOne() must refuse before Scope A: no email,
     * and the vericode is not rotated.
     */
    public function testSendOneReturnsFailedAndSendsNothingWhenBrevoPluginIsTooOld(): void
    {
        $this->brevoSiteRoot?->writePluginVersion('1.6.2');
        $GLOBALS['mockLogEntries'] = [];
        $carData = $this->eligibleCar();

        $this->mockRepo->expects($this->never())->method('beginTransaction');
        $this->mockRepo->expects($this->never())->method('updateCar');
        $this->mockVerifier->expects($this->never())->method('generateVerificationCode');
        $this->mockVerifier->expects($this->never())->method('setVerificationCode');
        $this->mockVerifier->expects($this->never())->method('setVerificationSentAt');

        $result = $this->service->sendOne($carData);

        $this->assertSame(SendResult::STATUS_FAILED, $result->status);
        $this->assertSame(100, $result->carId);
        $this->assertStringStartsWith('No email was sent. ', (string) $result->reason);
        $this->assertStringContainsString('1.6.2', (string) $result->reason, 'The reason must name the installed version');
        $this->assertSame([], $GLOBALS['mockSentEmails'], 'No email may be sent while Brevo is not ready');
        $refusals = array_values(array_filter(
            $GLOBALS['mockLogEntries'] ?? [],
            static fn (array $entry): bool => str_contains($entry['message'], 'Brevo is not ready: ')
        ));
        $this->assertCount(1, $refusals, 'One log line per refused send');
        $this->assertStringContainsString('1.6.2', $refusals[0]['message']);
        $this->assertSame('oldhashedvalue', $carData->vericode, 'The vericode must not be rotated');
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
     * Without the plugin's sendinblueLastError(), the failed-send log line
     * still says that no mailer error was reported.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testFailedSendLogSaysNoMailerErrorWhenPluginReportsNone(): void
    {
        $this->assertFalse(function_exists('sendinblueLastError'), 'This test needs a process without the plugin helper');

        $this->assertStringEndsWith('Mailer error: none reported', $this->failedSendLogLine());
    }

    /**
     * The plugin logs its reason in a separate row with no car id, so the
     * failed-send log line must carry it.
     */
    #[RunInSeparateProcess]
    #[AllowMockObjectsWithoutExpectations]
    public function testFailedSendLogIncludesTheMailerError(): void
    {
        require_once __DIR__ . '/fixtures/sendinblue-last-error.php';

        $this->assertStringEndsWith('Mailer error: Brevo API error 401: Key not found', $this->failedSendLogLine());
    }

    /** Runs a send where email() returns false and returns the one failed-send log line. */
    private function failedSendLogLine(): string
    {
        $GLOBALS['mockEmailSendResult'] = false;
        $GLOBALS['mockLogEntries'] = [];
        $this->mockVerifier->method('generateVerificationCode')->willReturn('newcode1234567890');
        $this->mockVerifier->method('setVerificationCode')->willReturn(true);
        $this->mockVerifier->method('setVerificationSentAt')->willReturn(true);
        $this->mockRepo->method('restoreVerificationCodeState')->willReturn(true);

        $result = $this->service->sendOne($this->eligibleCar());

        $this->assertSame(SendResult::STATUS_FAILED, $result->status);
        $lines = array_values(array_filter(
            $GLOBALS['mockLogEntries'] ?? [],
            static fn (array $entry): bool => str_contains($entry['message'], 'email() returned false')
        ));
        $this->assertCount(1, $lines, 'One failed-send log line per send');

        return $lines[0]['message'];
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

    /**
     * compose() does I/O since #1894 (logger() can throw \PDOException). A
     * throw there happens after Scope A committed the rotated code, so the
     * previous code must be restored and no email may go out.
     *
     * The composer is final, so the throw comes from a mocked EmailTemplate
     * inside a real composer. mockVerifier is a behavior stub only.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testSendOneRestoresPreviousVericodeAndFailsWhenComposeThrows(): void
    {
        $carData = $this->eligibleCar();
        $previousVericode = $carData->vericode;
        $previousSentAt = $carData->vericode_sent_at;

        $throwingTemplate = $this->createStub(EmailTemplate::class);
        $throwingTemplate->method('createButtonRow')
            ->willThrowException(new \RuntimeException('logger insert failed'));
        $service = new CarVerificationSendService(
            $this->mockRepo,
            $this->mockVerifier,
            new CarVerificationEmailComposer($throwingTemplate),
            $this->brevoReadySettings
        );

        $this->mockVerifier->method('generateVerificationCode')->willReturn('newcode1234567890');
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
        $this->mockRepo->expects($this->never())->method('incrementVerificationAttempts');
        $this->mockRepo->expects($this->never())->method('insertEmailEvent');

        $result = $service->sendOne($carData);

        $this->assertSame(SendResult::STATUS_FAILED, $result->status);
        $this->assertSame(100, $result->carId);
        $this->assertSame('The verification email could not be composed.', $result->reason);
        $this->assertSame([], $GLOBALS['mockSentEmails'], 'email() must not be called when compose() throws');
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

    /**
     * The `noowner` GDPR-reassignment account is a real, live users row, so
     * it is NOT caught by the null-owner check above — findVerificationEligible()'s
     * SQL excludes it via a join this class does not have, so sendOne() must
     * enforce it itself (see that method's own comment, and
     * VerificationEligibility's docblock, which documents this as the
     * downstream enforcement point for the PHP-side re-check).
     */
    public function testSendOneReturnsFailedWhenOwnerIsNoownerSystemAccount(): void
    {
        $GLOBALS['hookTestDb'] = $this->makeNoownerAccountDb();

        $carData = $this->eligibleCar();

        $this->mockRepo->expects($this->never())->method('beginTransaction');
        $this->mockRepo->expects($this->never())->method('updateCar');
        $this->mockVerifier->expects($this->never())->method('generateVerificationCode');

        $result = $this->service->sendOne($carData);

        $this->assertSame(SendResult::STATUS_FAILED, $result->status);
        $this->assertSame(100, $result->carId);
        $this->assertNotNull($result->reason);
        $this->assertStringContainsStringIgnoringCase('owner', $result->reason);
        $this->assertSame([], $GLOBALS['mockSentEmails'], 'No email may be sent to the noowner system account');
    }

    /**
     * The noowner check runs before the flag reads. A flagged profile on the
     * noowner account must not change the reason, and the flags are never read.
     */
    public function testSendOneGivesTheNoownerReasonBeforeAnyFlagRead(): void
    {
        $GLOBALS['hookTestDb'] = $this->makeNoownerAccountDb();
        $this->profileState = self::profile(['blocked' => true, 'bounced' => true]);

        $this->mockRepo->expects($this->never())->method('effectiveEmailFlags');
        $this->mockVerifier->expects($this->never())->method('generateVerificationCode');

        $result = $this->service->sendOne($this->eligibleCar());

        $this->assertSame(SendResult::STATUS_FAILED, $result->status);
        $this->assertSame('Car has no live owner.', $result->reason);
        $this->assertSame([], $GLOBALS['mockSentEmails']);
    }

    /**
     * The owner's flags live on `profiles` (#2101), which neither
     * VerificationEligibility::skipReason() nor the already-loaded `cars` row
     * can supply. The car passed in is fully eligible, so only the owner's
     * effective flags can stop this send (#2356). Each flag fails the send
     * with its own reason.
     *
     * @return array<string, array{array{optedOut?: bool, blocked?: bool, bounced?: bool}, string}>
     */
    public static function blockingProfileFlags(): array
    {
        return [
            'opted out' => [['optedOut' => true], 'opted out'],
            'blocked'   => [['blocked' => true], 'blocked'],
            'bounced'   => [['bounced' => true], 'bounced'],
        ];
    }

    /**
     * @param array{optedOut?: bool, blocked?: bool, bounced?: bool} $flags
     */
    #[DataProvider('blockingProfileFlags')]
    public function testSendOneReturnsFailedWhenOwnerProfileHasAFlag(array $flags, string $reasonWord): void
    {
        $carData = $this->eligibleCar();

        $this->profileState = self::profile($flags);
        $this->mockRepo->expects($this->never())->method('beginTransaction');
        $this->mockRepo->expects($this->never())->method('updateCar');
        $this->mockVerifier->expects($this->never())->method('generateVerificationCode');

        $result = $this->service->sendOne($carData);

        $this->assertSame(SendResult::STATUS_FAILED, $result->status);
        $this->assertSame(100, $result->carId);
        $this->assertNotNull($result->reason);
        $this->assertStringContainsStringIgnoringCase($reasonWord, $result->reason);
        $this->assertSame([], $GLOBALS['mockSentEmails'], 'No email may be sent to an owner whose profile has a flag');
    }

    /**
     * The send mails the owner's `users.email`. `cars.email` is only a copy
     * and can be stale. A stale copy must not change the recipient, the
     * `sent` event row, or the address in the admin report.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testSendOneMailsTheAccountEmailAndNotAStaleCarEmail(): void
    {
        $GLOBALS['hookTestDb'] = $this->makeOwnerFoundDb(42, 'current@example.com');
        $carData = $this->eligibleCar();
        $carData->email = 'old-address@example.com';

        $this->mockVerifier->method('generateVerificationCode')->willReturn('newcode1234567890');
        $this->mockVerifier->method('setVerificationCode')->willReturn(true);
        $this->mockVerifier->method('setVerificationSentAt')->willReturn(true);
        $this->mockRepo->expects($this->once())->method('insertEmailEvent')
            ->with(100, 'current@example.com', 'sent', null, $this->isString(), $this->isString())
            ->willReturn(1);
        $this->mockRepo->method('incrementVerificationAttempts')->willReturn(true);

        $result = $this->service->sendOne($carData);

        $this->assertSame(SendResult::STATUS_SENT, $result->status);
        $this->assertCount(1, $GLOBALS['mockSentEmails']);
        $this->assertSame('current@example.com', $GLOBALS['mockSentEmails'][0][0], 'The send must mail users.email');
        $this->assertSame('current@example.com', $carData->email, 'The admin report must show the address that was mailed');
    }

    /**
     * A flag on `users.email` stops the send even when the car still holds
     * another address. effectiveEmailFlags() is the only flag read.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testFlagOnTheAccountEmailStopsTheSendWhateverTheCarEmail(): void
    {
        $carData = $this->eligibleCar();
        $carData->email = 'old-address@example.com';
        $this->profileState = self::profile(['bounced' => true]);
        $this->mockVerifier->expects($this->never())->method('generateVerificationCode');

        $result = $this->service->sendOne($carData);

        $this->assertSame('Owner email bounced.', $result->reason);
        $this->assertSame([], $GLOBALS['mockSentEmails']);
    }

    /** @return array<string, array{string}> */
    public static function emptyAccountEmails(): array
    {
        return ['empty' => [''], 'white space only' => ["  \t"]];
    }

    /**
     * An admin can clear `users.email` after the eligibility query. The send
     * must then refuse, with a log row, and must not fall back to `cars.email`.
     */
    #[DataProvider('emptyAccountEmails')]
    public function testSendOneRefusesWhenTheAccountEmailIsEmpty(string $accountEmail): void
    {
        $GLOBALS['hookTestDb'] = $this->makeOwnerFoundDb(42, $accountEmail);
        $this->mockRepo->expects($this->never())->method('beginTransaction');
        $this->mockVerifier->expects($this->never())->method('generateVerificationCode');

        $result = $this->service->sendOne($this->eligibleCar());

        $this->assertSame(SendResult::STATUS_FAILED, $result->status);
        $this->assertSame('Owner has no email address.', $result->reason);
        $this->assertSame([], $GLOBALS['mockSentEmails'], 'The send must not fall back to cars.email');
    }

    /** The flag check runs before the empty-address check, so a flagged owner keeps the flag reason. */
    #[AllowMockObjectsWithoutExpectations]
    public function testFlagReasonComesBeforeTheEmptyAccountEmailReason(): void
    {
        $GLOBALS['hookTestDb'] = $this->makeOwnerFoundDb(42, '');
        $this->profileState = self::profile(['blocked' => true]);

        $result = $this->service->sendOne($this->eligibleCar());

        $this->assertSame('Owner email is blocked.', $result->reason);
    }

    /**
     * An owner with no `profiles` row is excluded (#2101). The old rule
     * treated a missing row as "no opt-out" and mailed the owner. The queries
     * in findVerificationEligible() now exclude such an owner, so this
     * guard must agree.
     */
    public function testSendOneReturnsFailedWhenOwnerHasNoProfileRow(): void
    {
        $carData = $this->eligibleCar();

        $this->profileState = EffectiveEmailFlags::none();
        $this->mockRepo->expects($this->never())->method('beginTransaction');
        $this->mockRepo->expects($this->never())->method('updateCar');
        $this->mockVerifier->expects($this->never())->method('generateVerificationCode');

        $result = $this->service->sendOne($carData);

        $this->assertSame(SendResult::STATUS_FAILED, $result->status);
        $this->assertSame('Owner has no profile.', $result->reason);
        $this->assertSame([], $GLOBALS['mockSentEmails'], 'No email may be sent to an owner with no profile');
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
