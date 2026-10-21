<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationEmailComposer;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\CarVerificationSendService;
use ElanRegistry\Car\VerificationBatchSender;
use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\Cron\HealthcheckPinger;
use ElanRegistry\Cron\SendVerificationBatchJob;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\AbstractCronJobFakeDatabase;
use Tests\Support\BrevoSiteRoot;
use Tests\Support\RecordingHealthcheckTransport;
use Tests\Support\SwitchableVerificationSettingsFakeDatabase;
use Tests\Support\VerificationSettingsFakeDatabase;

require_once __DIR__ . '/../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../Support/AbstractCronJobFakeDatabase.php';
require_once __DIR__ . '/../../Support/VerificationSettingsFakeDatabase.php';
require_once __DIR__ . '/../../Support/SwitchableVerificationSettingsFakeDatabase.php';
require_once __DIR__ . '/../../Support/RecordingHealthcheckTransport.php';

/**
 * Unit tests for SendVerificationBatchJob (#1885).
 *
 * VerificationSettings, CarVerificationSendService, and VerificationBatchSender
 * are all declared `final` (unlike BrevoEventReconciliationJob's CarRepository
 * collaborator), so none of the three can be doubled with
 * createMock()/createStub() — PHPUnit's mock generator refuses to subclass a
 * final class. Instead, this suite constructs REAL instances of all three,
 * wired down to a mocked CarRepository (not final) for the eligibility query,
 * and a VerificationSettingsFakeDatabase for VerificationSettings' own reads —
 * the same "double the lowest mockable layer, use the real object above it"
 * approach the codebase already uses wherever a collaborator is final (see
 * VerificationBatchSenderTest.php, which does exactly this to test
 * VerificationBatchSender itself). This keeps CarVerificationSendService's
 * documented single-source-of-truth eligibility delegation
 * (findEligible() -> CarRepository::findVerificationEligible(), verbatim, no
 * hand-rolled fake substitute) genuinely exercised rather than assumed.
 *
 * CarVerificationManager and CarVerificationEmailComposer (CarVerificationSendService's
 * other two constructor dependencies) are exercised for real too. Most tests
 * here never reach them, either failing the eligibility query, finding zero
 * eligible cars, or being short-circuited before processBatch() reaches
 * sendOne(). The one exception is
 * testUnrecordedCarsAreFoldedIntoThePersistedFailedCount, which must drive a
 * genuine send all the way to the bookkeeping step to populate the
 * 'unrecorded' bucket at all — it runs the real compose-and-send path over
 * the bootstrap's email mock and the dbi() owner seam at the foot of this
 * file.
 *
 * Tests drive execute() via runNow(), which reaches it without the enabled
 * check or the guard claim — those belong to AbstractCronJob and are covered
 * by AbstractCronJobTest. runNow()'s crash isolation is identical to run()'s,
 * so anything escaping execute() would still be caught and logged.
 */
#[Group('fast')]
final class SendVerificationBatchJobTest extends TestCase
{
    private const CURRENT_USER_ID = 0;

    /** Temp site root that holds the sendinblue plugin files, or null when none was made. */
    private ?BrevoSiteRoot $brevoSiteRoot = null;

    /** Records every healthchecks.io ping URL that a job built by makeJob() sends. */
    private RecordingHealthcheckTransport $pingTransport;

    /** @var array<string, array{env: string|null, getenv: string|false}> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        $this->pingTransport = new RecordingHealthcheckTransport();
        foreach (['HEALTHCHECKS_PING_KEY', 'HEALTHCHECKS_SLUG_SUFFIX'] as $name) {
            $this->savedEnv[$name] = ['env' => $_ENV[$name] ?? null, 'getenv' => getenv($name)];
            putenv($name);
        }
        $_ENV['HEALTHCHECKS_PING_KEY'] = 'KEY123';
        $_ENV['HEALTHCHECKS_SLUG_SUFFIX'] = '-prod';

        $GLOBALS['mockSentEmails'] = [];
        unset($GLOBALS['mockEmailSendResult']);
        $GLOBALS['hookTestDb'] = $this->makeOwnerFoundDb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['mockSentEmails'], $GLOBALS['mockEmailSendResult'], $GLOBALS['hookTestDb']);
        $this->brevoSiteRoot?->remove();
        foreach ($this->savedEnv as $name => $saved) {
            if ($saved['env'] === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $saved['env'];
            }
            putenv($saved['getenv'] === false ? $name : "{$name}={$saved['getenv']}");
        }
        parent::tearDown();
    }

    /**
     * Brevo-ready settings. The temp site root is made once per test, because
     * makeSendSvc() can run more than once in a test. tearDown() removes it.
     */
    private function makeBrevoReadySettings(): VerificationSettings
    {
        $this->brevoSiteRoot ??= BrevoSiteRoot::create('send_batch_job_test_');

        return $this->brevoSiteRoot->readySettings();
    }

    /**
     * The send batch reads the verification switch itself (#2358), so the
     * fake must report it. The switch is on unless a test turns it off.
     */
    private function makeSettings(int $batchSize = 5, bool $enabled = true): VerificationSettings
    {
        return new VerificationSettings(new VerificationSettingsFakeDatabase(
            firstRowValue: (object) ['batch_size' => $batchSize, 'enabled' => $enabled ? 1 : 0],
        ));
    }

    /**
     * @param CarRepository&\PHPUnit\Framework\MockObject\Stub $repo
     */
    private function makeSendSvc(CarRepository $repo): CarVerificationSendService
    {
        // CarVerificationManager (not final) is constructed for real, over the
        // same repo double. CarVerificationEmailComposer is final and cannot
        // be doubled at all, so it too is constructed for real — with no
        // constructor args it builds its own EmailTemplate() internally.
        // The settings report Brevo ready, so a test that reaches sendOne()
        // (testUnrecordedCarsAreFoldedIntoThePersistedFailedCount) gets past
        // its Brevo guard to the send and the bookkeeping it asserts on.
        return new CarVerificationSendService(
            $repo,
            new CarVerificationManager($repo),
            new CarVerificationEmailComposer(),
            $this->makeBrevoReadySettings(),
        );
    }

    /**
     * @param CarRepository&\PHPUnit\Framework\MockObject\Stub $repo
     */
    private function makeSender(CarRepository $repo, CarVerificationSendService $sendSvc): VerificationBatchSender
    {
        return new VerificationBatchSender($repo, $sendSvc, self::CURRENT_USER_ID);
    }

    private function makeJob(
        VerificationSettings $settings,
        CarVerificationSendService $sendSvc,
        VerificationBatchSender $sender,
        ?AbstractCronJobFakeDatabase $db = null,
    ): SendVerificationBatchJob {
        return new SendVerificationBatchJob(
            $db ?? new AbstractCronJobFakeDatabase(),
            $settings,
            $sendSvc,
            $sender,
            new HealthcheckPinger($this->pingTransport),
        );
    }

    // --- jobName() / guardIntervalHours() ---------------------------------

    public function testJobNameReturnsSendVerificationBatch(): void
    {
        $reflection = new ReflectionClass(SendVerificationBatchJob::class);
        $method = $reflection->getMethod('jobName');

        $repo = $this->createStub(CarRepository::class);
        $sendSvc = $this->makeSendSvc($repo);
        $job = $this->makeJob($this->makeSettings(), $sendSvc, $this->makeSender($repo, $sendSvc));

        $this->assertSame('send_verification_batch', $method->invoke($job));
    }

    public function testGuardIntervalHoursReturnsTwenty(): void
    {
        $reflection = new ReflectionClass(SendVerificationBatchJob::class);
        $method = $reflection->getMethod('guardIntervalHours');

        $repo = $this->createStub(CarRepository::class);
        $sendSvc = $this->makeSendSvc($repo);
        $job = $this->makeJob($this->makeSettings(), $sendSvc, $this->makeSender($repo, $sendSvc));

        $this->assertSame(20, $method->invoke($job));
    }

    // --- execute() — eligibility query -------------------------------------

    public function testExecuteCallsFindEligibleWithBatchSizeAsLimitAndOffsetZero(): void
    {
        // CarVerificationSendService::findEligible() delegates verbatim to
        // CarRepository::findVerificationEligible() — asserting on the
        // repository mock proves the real, undoubled delegation actually ran.
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())
            ->method('findVerificationEligible')
            ->with(7, 0)
            ->willReturn([]);

        $sendSvc = $this->makeSendSvc($repo);
        $sender = $this->makeSender($repo, $sendSvc);

        $this->makeJob($this->makeSettings(batchSize: 7), $sendSvc, $sender)->runNow();
    }

    /**
     * makeSettings() builds a real VerificationSettings over a fake DB row, so
     * the read-side clamp in batchSize() runs. A stored 255 must reach the
     * query as 25 (#2363).
     */
    public function testExecuteClampsAnOutOfRangeStoredBatchSizeToTwentyFive(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())
            ->method('findVerificationEligible')
            ->with(25, 0)
            ->willReturn([]);

        $sendSvc = $this->makeSendSvc($repo);
        $sender = $this->makeSender($repo, $sendSvc);

        $this->makeJob($this->makeSettings(batchSize: 255), $sendSvc, $sender)->runNow();
    }

    // --- execute() — eligible ids pass through unchanged -------------------

    public function testEligibleCarIdsPassThroughUnchangedToProcessBatch(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->method('findVerificationEligible')->willReturn([
            (object) ['id' => 11],
            (object) ['id' => 42],
            (object) ['id' => 7],
        ]);
        // The real VerificationBatchSender::processBatch() calls findById()
        // once per submitted id — asserting the exact id list it re-reads is
        // an equally strong proxy for "the ids reached processBatch()
        // unchanged" as mocking processBatch() itself would be, and exercises
        // one layer deeper (the real, undoubled VerificationBatchSender).
        $repo->expects($this->exactly(3))
            ->method('findById')
            ->willReturnCallback(function (int $id): ?object {
                $this->assertContains($id, [11, 42, 7]);
                return null; // "car no longer exists" — short-circuits before sendOne().
            });

        $sendSvc = $this->makeSendSvc($repo);
        $sender = $this->makeSender($repo, $sendSvc);

        $this->makeJob($this->makeSettings(batchSize: 5), $sendSvc, $sender)->runNow();
    }

    public function testEligibleCarIdsAreCastToInt(): void
    {
        // findVerificationEligible()'s row shape carries ->id as whatever the
        // DB driver returns it as — a numeric string is realistic (PDO
        // without native prepares returns int columns as strings by default).
        $repo = $this->createMock(CarRepository::class);
        $repo->method('findVerificationEligible')->willReturn([(object) ['id' => '99']]);
        $repo->expects($this->once())
            ->method('findById')
            ->with($this->identicalTo(99))
            ->willReturn(null);

        $sendSvc = $this->makeSendSvc($repo);
        $sender = $this->makeSender($repo, $sendSvc);

        $this->makeJob($this->makeSettings(batchSize: 5), $sendSvc, $sender)->runNow();
    }

    // --- execute() — zero eligible cars -------------------------------------

    public function testZeroEligibleCarsCallsProcessBatchWithEmptyArray(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->method('findVerificationEligible')->willReturn([]);
        $repo->expects($this->never())->method('findById');

        $sendSvc = $this->makeSendSvc($repo);
        $sender = $this->makeSender($repo, $sendSvc);

        $this->makeJob($this->makeSettings(batchSize: 5), $sendSvc, $sender)->runNow();

        $this->assertSame([], $this->logsContaining('failed'), 'A quiet night must not log a failure');
    }

    public function testZeroEligibleCarsProducesNoError(): void
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('findVerificationEligible')->willReturn([]);

        $sendSvc = $this->makeSendSvc($repo);
        $sender = $this->makeSender($repo, $sendSvc);

        $this->makeJob($this->makeSettings(batchSize: 5), $sendSvc, $sender)->runNow();

        global $mockLogEntries;
        $failureLogs = array_values(array_filter(
            $mockLogEntries ?? [],
            static fn (array $entry): bool => $entry['category'] === LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE
        ));
        $this->assertSame([], $failureLogs, 'Zero eligible cars is routine, not a fault');

        $verificationLogs = $this->logsContaining('no eligible cars');
        $this->assertNotEmpty($verificationLogs, 'A quiet run should still be logged so an operator sees a positive "did it run" signal');
        $this->assertSame(LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $verificationLogs[0]['category']);
    }

    public function testEligibilityQueryFailureIsLoggedAndSendsNothing(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->method('findVerificationEligible')->willThrowException(new CarDatabaseException('query failed for owner@example.org'));
        $repo->expects($this->never())->method('findById');

        $sendSvc = $this->makeSendSvc($repo);
        $sender = $this->makeSender($repo, $sendSvc);

        $this->makeJob($this->makeSettings(batchSize: 5), $sendSvc, $sender)->runNow();

        $log = $this->logsContaining('eligible-car query FAILED');
        $this->assertNotEmpty($log, 'An eligibility query failure must be logged, not silent');
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $log[0]['category']);
        // The summary, not the message: a repository message can hold an address.
        $this->assertStringContainsString('CarDatabaseException (code 0)', $log[0]['message']);
        $this->assertStringNotContainsString('owner@example.org', $log[0]['message']);
    }

    // --- execute() — recordRunCounts() writes the three columns -------------

    public function testRecordRunCountsWritesTheThreeNewColumnsFromProcessBatchResult(): void
    {
        // Three cars: one whose findById() throws (-> failed bucket), one
        // that no longer exists (-> skipped bucket), and one for which
        // findById() returns a car object that VerificationEligibility::skipReason()
        // will judge ineligible (-> skipped bucket too). This keeps the whole
        // chain real (no processBatch() stub) while still landing a
        // deterministic, non-1/1/1-by-coincidence count split, using only
        // CarRepository as the mocked boundary.
        $repo = $this->createStub(CarRepository::class);
        $repo->method('findVerificationEligible')->willReturn([
            (object) ['id' => 1],
            (object) ['id' => 2],
            (object) ['id' => 3],
        ]);
        $repo->method('findById')->willReturnCallback(function (int $id) {
            if ($id === 1) {
                throw new CarDatabaseException('boom');
            }
            // id 2 and 3: "car no longer exists" -> both land in skipped.
            return null;
        });

        $sendSvc = $this->makeSendSvc($repo);
        $sender = $this->makeSender($repo, $sendSvc);

        $recordingDb = new class extends AbstractCronJobFakeDatabase {
            /** @var list<array{sql: string, params: array<mixed>}> */
            public array $updateCalls = [];

            public function query(string $sql, array $params = []): AbstractCronJobFakeDatabase
            {
                if (stripos($sql, 'last_sent_count') !== false) {
                    $this->updateCalls[] = ['sql' => $sql, 'params' => $params];
                    return $this;
                }

                return parent::query($sql, $params);
            }

            public function error(): bool
            {
                if ($this->updateCalls !== []) {
                    return false;
                }

                return parent::error();
            }

            public function count(): int
            {
                if ($this->updateCalls !== []) {
                    return 1;
                }

                return parent::count();
            }
        };

        $job = new SendVerificationBatchJob($recordingDb, $this->makeSettings(batchSize: 5), $sendSvc, $sender, new HealthcheckPinger($this->pingTransport));
        $job->runNow();

        $this->assertCount(1, $recordingDb->updateCalls, 'recordRunCounts() must issue exactly one UPDATE');
        $this->assertSame(
            [0, 2, 1, SendVerificationBatchJob::JOB_NAME],
            $recordingDb->updateCalls[0]['params'],
            'The UPDATE must bind sent=0, skipped=2 (both "no longer exists" cars), failed=1 (the findById() throw), and the job name'
        );
        $this->assertStringContainsString('last_sent_count', $recordingDb->updateCalls[0]['sql']);
        $this->assertStringContainsString('last_skipped_count', $recordingDb->updateCalls[0]['sql']);
        $this->assertStringContainsString('last_failed_count', $recordingDb->updateCalls[0]['sql']);
        $this->assertStringContainsString('WHERE job_name = ?', $recordingDb->updateCalls[0]['sql']);
    }

    public function testUnrecordedCarsAreFoldedIntoThePersistedFailedCount(): void
    {
        // There is no fourth column for the 'unrecorded' bucket, so execute()
        // deliberately adds it to the failed count it persists. Without this
        // test the merge is invisible: the sibling test above happens to
        // produce zero unrecorded cars, so it passes either way.
        //
        // Reaching the bucket for real requires a genuine send whose
        // bookkeeping then fails — insertEmailEvent() throwing after the mail
        // has gone out, the same seam
        // VerificationBatchSenderTest::testUnrecordedSendResultRoutesToUnrecordedBucketNotSent
        // uses.
        $car = $this->makeSendableCar(1);

        $repo = $this->createStub(CarRepository::class);
        $repo->method('findVerificationEligible')->willReturn([(object) ['id' => 1]]);
        $repo->method('findById')->willReturn($car);
        $repo->method('effectiveEmailFlags')->willReturn(
            new \ElanRegistry\Car\EffectiveEmailFlags(true, false, null, false, null, null, null, false, null, null, 'owner@example.com')
        );
        // The vericode rotate-and-stamp writes CarVerificationManager performs
        // before the mail goes out — these must succeed for the send to reach
        // the bookkeeping step that is the point of this test.
        $repo->method('updateVerificationCode')->willReturn(true);
        $repo->method('updateVerificationSentAt')->willReturn(true);
        $repo->method('insertEmailEvent')->willThrowException(new RuntimeException('DB connection lost'));
        $repo->method('incrementVerificationAttempts')->willReturn(true);

        $sendSvc = $this->makeSendSvc($repo);
        $sender = $this->makeSender($repo, $sendSvc);

        $recordingDb = new class extends AbstractCronJobFakeDatabase {
            /** @var list<array{sql: string, params: array<mixed>}> */
            public array $updateCalls = [];

            public function query(string $sql, array $params = []): AbstractCronJobFakeDatabase
            {
                if (stripos($sql, 'last_sent_count') !== false) {
                    $this->updateCalls[] = ['sql' => $sql, 'params' => $params];
                    return $this;
                }

                return parent::query($sql, $params);
            }

            public function error(): bool
            {
                return $this->updateCalls !== [] ? false : parent::error();
            }

            public function count(): int
            {
                return $this->updateCalls !== [] ? 1 : parent::count();
            }
        };

        $job = new SendVerificationBatchJob($recordingDb, $this->makeSettings(batchSize: 5), $sendSvc, $sender, new HealthcheckPinger($this->pingTransport));
        $job->runNow();

        $this->assertCount(1, $recordingDb->updateCalls, 'recordRunCounts() must issue exactly one UPDATE');
        $this->assertSame(
            [0, 0, 1, SendVerificationBatchJob::JOB_NAME],
            $recordingDb->updateCalls[0]['params'],
            'An unrecorded car must persist as failed=1, not sent=1 — it risks a duplicate send '
            . 'and must not be reported as a clean run'
        );

        // The log line keeps all four buckets distinct even though the
        // persisted column merges two of them.
        $log = $this->logsContaining('cron batch complete');
        $this->assertNotEmpty($log);
        $this->assertStringContainsString('0 sent, 1 unrecorded, 0 skipped, 0 failed', $log[0]['message']);
    }

    public function testRecordRunCountsFailureIsLoggedAndDoesNotEscapeExecute(): void
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('findVerificationEligible')->willReturn([(object) ['id' => 1]]);
        $repo->method('findById')->willReturn(null); // -> skipped, no exception anywhere.

        $sendSvc = $this->makeSendSvc($repo);
        $sender = $this->makeSender($repo, $sendSvc);

        // A small dedicated fake that fails only the recordRunCounts()
        // UPDATE, rather than layering more conditionals onto the shared
        // AbstractCronJobFakeDatabase (whose error() already branches on
        // verification-settings vs. job-row reads).
        $db = new class extends AbstractCronJobFakeDatabase {
            private bool $lastWasCountsUpdate = false;

            public function query(string $sql, array $params = []): AbstractCronJobFakeDatabase
            {
                $this->lastWasCountsUpdate = stripos($sql, 'last_sent_count') !== false;
                return parent::query($sql, $params);
            }

            public function error(): bool
            {
                return $this->lastWasCountsUpdate || parent::error();
            }
        };

        $job = new SendVerificationBatchJob($db, $this->makeSettings(batchSize: 5), $sendSvc, $sender, new HealthcheckPinger($this->pingTransport));
        $job->runNow();

        $log = $this->logsContaining('run counts could not be written');
        $this->assertNotEmpty($log, 'A failed counts write must be logged');
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $log[0]['category']);

        // The run itself must still be reported as having completed — no
        // AbstractCronJob-level "job failed" line for this best-effort write.
        $this->assertSame([], $this->logsContaining('failed (manual run): '));
    }

    public function testRecordRunCountsLogsWhenConfirmationSelectFindsNoRow(): void
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('findVerificationEligible')->willReturn([(object) ['id' => 1]]);
        $repo->method('findById')->willReturn(null); // -> skipped, no exception anywhere.

        $sendSvc = $this->makeSendSvc($repo);
        $sender = $this->makeSender($repo, $sendSvc);

        // The UPDATE succeeds cleanly; the confirmation SELECT that follows it
        // finds nothing — er_cron_job_runs has no row for this job at all.
        $db = new class extends AbstractCronJobFakeDatabase {
            private bool $lastWasCountsUpdate = false;

            private bool $lastWasConfirmationSelect = false;

            public function query(string $sql, array $params = []): AbstractCronJobFakeDatabase
            {
                $this->lastWasCountsUpdate = stripos($sql, 'last_sent_count') !== false;
                $this->lastWasConfirmationSelect = stripos($sql, 'SELECT job_name') !== false;

                if ($this->lastWasCountsUpdate || $this->lastWasConfirmationSelect) {
                    return $this;
                }

                return parent::query($sql, $params);
            }

            public function error(): bool
            {
                if ($this->lastWasCountsUpdate || $this->lastWasConfirmationSelect) {
                    return false;
                }

                return parent::error();
            }

            public function count(): int
            {
                if ($this->lastWasCountsUpdate) {
                    return 1;
                }

                if ($this->lastWasConfirmationSelect) {
                    return 0;
                }

                return parent::count();
            }

            public function first(bool $assoc = false): array|object
            {
                if ($this->lastWasConfirmationSelect) {
                    return [];
                }

                return parent::first($assoc);
            }
        };

        $job = new SendVerificationBatchJob($db, $this->makeSettings(batchSize: 5), $sendSvc, $sender, new HealthcheckPinger($this->pingTransport));
        $job->runNow();

        $log = $this->logsContaining('the row appears not to be seeded');
        $this->assertNotEmpty($log, 'A genuinely missing er_cron_job_runs row must be logged');
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $log[0]['category']);
        $this->assertStringContainsString('no er_cron_job_runs row to write run counts to', $log[0]['message']);
    }

    public function testRecordRunCountsDoesNotFalselyLogRowMissingOnRepeatedIdenticalWrite(): void
    {
        // Regression test. MySQL's rowCount() reports rows CHANGED, not rows
        // MATCHED, so re-writing the same three counts a second night running
        // yields count() === 0 on a perfectly healthy, present row. A
        // count()===0 missing-row check therefore logged a false
        // CRON_JOB_FAILURE every steady-state night — the confirmation SELECT
        // is what distinguishes "row absent" from "row already held these
        // values".
        $repo = $this->createStub(CarRepository::class);
        $repo->method('findVerificationEligible')->willReturn([(object) ['id' => 1]]);
        $repo->method('findById')->willReturn(null); // -> skipped, no exception anywhere.

        $sendSvc = $this->makeSendSvc($repo);
        $sender = $this->makeSender($repo, $sendSvc);

        $db = new class extends AbstractCronJobFakeDatabase {
            private bool $lastWasCountsUpdate = false;

            private bool $lastWasConfirmationSelect = false;

            public function query(string $sql, array $params = []): AbstractCronJobFakeDatabase
            {
                $this->lastWasCountsUpdate = stripos($sql, 'last_sent_count') !== false;
                $this->lastWasConfirmationSelect = stripos($sql, 'SELECT job_name') !== false;

                if ($this->lastWasCountsUpdate || $this->lastWasConfirmationSelect) {
                    return $this;
                }

                return parent::query($sql, $params);
            }

            public function error(): bool
            {
                if ($this->lastWasCountsUpdate || $this->lastWasConfirmationSelect) {
                    return false;
                }

                return parent::error();
            }

            public function count(): int
            {
                // The UPDATE changed nothing: the row already held exactly
                // these three values. This is the steady state, not a fault.
                if ($this->lastWasCountsUpdate) {
                    return 0;
                }

                if ($this->lastWasConfirmationSelect) {
                    return 1;
                }

                return parent::count();
            }

            public function first(bool $assoc = false): array|object
            {
                // The row is genuinely there — the confirmation SELECT finds it.
                if ($this->lastWasConfirmationSelect) {
                    $row = ['job_name' => SendVerificationBatchJob::JOB_NAME];

                    return $assoc ? $row : (object) $row;
                }

                return parent::first($assoc);
            }
        };

        $job = new SendVerificationBatchJob($db, $this->makeSettings(batchSize: 5), $sendSvc, $sender, new HealthcheckPinger($this->pingTransport));
        $job->runNow();

        $this->assertSame(
            [],
            $this->logsContaining('the row appears not to be seeded'),
            'An UPDATE that changed 0 rows because the row already held these counts must NOT be '
            . 'reported as a missing row — that false CRON_JOB_FAILURE would fire every healthy night'
        );
    }

    // --- Site-wide verification switch --------------------------------------

    public function testRunNowSendsNothingWhenVerificationSwitchIsOff(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->never())->method('findVerificationEligible');
        $repo->expects($this->never())->method('findById');

        $sendSvc = $this->makeSendSvc($repo);
        $sender = $this->makeSender($repo, $sendSvc);
        $db = new AbstractCronJobFakeDatabase();

        $this->makeJob($this->makeSettings(batchSize: 5, enabled: false), $sendSvc, $sender, $db)->runNow();

        $this->assertSame([], $db->sqlLog(), 'A refused manual run must issue no query');
    }

    /**
     * The claim stamps last_run_at. A claim while the switch is off would
     * make the first run after the switch goes on look not due.
     */
    public function testRunSendsNothingAndIssuesNoClaimWhenVerificationSwitchIsOff(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->never())->method('findVerificationEligible');
        $repo->expects($this->never())->method('findById');

        $sendSvc = $this->makeSendSvc($repo);
        $sender = $this->makeSender($repo, $sendSvc);
        $db = new AbstractCronJobFakeDatabase();

        $this->makeJob($this->makeSettings(batchSize: 5, enabled: false), $sendSvc, $sender, $db)->run();

        $this->assertSame([], $db->sqlLog(), 'A refused run must issue no enabled read and no claim');
    }

    public function testFirstRunAfterTheSwitchGoesOnClaimsAndSends(): void
    {
        $repo = $this->createMock(CarRepository::class);
        // Called once, by the second run only.
        $repo->expects($this->once())->method('findVerificationEligible')->willReturn([]);

        $sendSvc = $this->makeSendSvc($repo);
        $sender = $this->makeSender($repo, $sendSvc);
        $db = new AbstractCronJobFakeDatabase();
        // The switch is read through this fake, so the test can flip it
        // between two runs of the same job.
        $settingsDb = new SwitchableVerificationSettingsFakeDatabase();
        $job = new SendVerificationBatchJob(
            $db,
            new VerificationSettings($settingsDb),
            $sendSvc,
            $sender,
            new HealthcheckPinger($this->pingTransport),
        );

        $job->run();
        $this->assertSame([], $db->sqlLog(), 'The run while the switch is off must not claim');
        $this->assertSame([], $this->pingTransport->urls, 'The run while the switch is off must not ping');

        $settingsDb->on = true;
        $job->run();

        $claims = array_filter($db->sqlLog(), static fn (string $sql): bool => stripos($sql, 'last_run_at = NOW()') !== false);
        $this->assertCount(1, $claims, 'The first run after the switch goes on must claim');
        $this->assertSame([self::PING_URL], $this->pingTransport->urls, 'The first run after the switch goes on must ping success');
    }

    // --- healthchecks.io ping (#1875) ----------------------------------------

    private const PING_URL = 'https://hc-ping.com/KEY123/send-verification-batch-prod';

    public function testRunPingsSuccessWhenTheEligibleSetIsEmpty(): void
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('findVerificationEligible')->willReturn([]);
        $sendSvc = $this->makeSendSvc($repo);

        $this->makeJob($this->makeSettings(), $sendSvc, $this->makeSender($repo, $sendSvc))->run();

        $this->assertSame([self::PING_URL], $this->pingTransport->urls);
    }

    public function testRunPingsFailWhenTheEligibilityQueryFails(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->method('findVerificationEligible')->willThrowException(new CarDatabaseException('query failed'));
        $repo->expects($this->never())->method('findById');
        $sendSvc = $this->makeSendSvc($repo);

        $this->makeJob($this->makeSettings(), $sendSvc, $this->makeSender($repo, $sendSvc))->run();

        $this->assertSame([self::PING_URL . '/fail'], $this->pingTransport->urls);
        $log = $this->logsContaining('eligible-car query FAILED');
        $this->assertNotEmpty($log, 'The failure must still be logged');
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $log[0]['category']);
    }

    /**
     * @return array<string, array{0: int|null}>
     */
    public static function brokenPingProvider(): array
    {
        return ['transport throws' => [null], 'healthchecks.io returns 500' => [500]];
    }

    /**
     * A broken ping must not stop the batch or its bookkeeping. Two cars are
     * really sent (mocked mail function), then the counts UPDATE is recorded.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('brokenPingProvider')]
    public function testBatchStillSendsAndRecordsTheRunWhenThePingFails(?int $status): void
    {
        if ($status === null) {
            $this->pingTransport->throw = new RuntimeException('network down');
        } else {
            $this->pingTransport->status = $status;
        }

        $repo = $this->createStub(CarRepository::class);
        $repo->method('findVerificationEligible')->willReturn([(object) ['id' => 1], (object) ['id' => 2]]);
        $repo->method('findById')->willReturnCallback(fn (int $id): object => $this->makeSendableCar($id));
        $repo->method('effectiveEmailFlags')->willReturn(
            new \ElanRegistry\Car\EffectiveEmailFlags(true, false, null, false, null, null, null, false, null, null, 'owner@example.com')
        );
        $repo->method('updateVerificationCode')->willReturn(true);
        $repo->method('updateVerificationSentAt')->willReturn(true);
        $repo->method('insertEmailEvent')->willReturn(1);
        $repo->method('incrementVerificationAttempts')->willReturn(true);

        $sendSvc = $this->makeSendSvc($repo);
        $sender = $this->makeSender($repo, $sendSvc);

        $recordingDb = new class extends AbstractCronJobFakeDatabase {
            /** @var list<array{sql: string, params: array<mixed>}> */
            public array $updateCalls = [];

            public function query(string $sql, array $params = []): AbstractCronJobFakeDatabase
            {
                if (stripos($sql, 'last_sent_count') !== false) {
                    $this->updateCalls[] = ['sql' => $sql, 'params' => $params];
                    return $this;
                }

                return parent::query($sql, $params);
            }

            public function error(): bool
            {
                return $this->updateCalls !== [] ? false : parent::error();
            }

            public function count(): int
            {
                return $this->updateCalls !== [] ? 1 : parent::count();
            }
        };

        $job = $this->makeJob($this->makeSettings(batchSize: 5), $sendSvc, $sender, $recordingDb);
        $job->run();

        $this->assertCount(1, $this->pingTransport->urls, 'The ping must have been tried');
        $this->assertCount(2, $GLOBALS['mockSentEmails'], 'Both cars must be sent although the ping fails');
        $this->assertCount(1, $recordingDb->updateCalls, 'The run counts must still be recorded');
        $this->assertSame([2, 0, 0, SendVerificationBatchJob::JOB_NAME], $recordingDb->updateCalls[0]['params']);
        $this->assertNotEmpty($this->logsContaining('cron batch complete'));
        $jobFailures = array_filter(
            $GLOBALS['mockLogEntries'] ?? [],
            static fn (array $entry): bool => $entry['category'] === LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE
        );
        $this->assertSame([], array_values($jobFailures), 'A ping fault must not be reported as a job failure');
    }

    public function testRunPingsNothingAndSendsNothingWhenTheSwitchIsOff(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->never())->method('findVerificationEligible');
        $repo->expects($this->never())->method('findById');
        $sendSvc = $this->makeSendSvc($repo);

        $this->makeJob($this->makeSettings(enabled: false), $sendSvc, $this->makeSender($repo, $sendSvc))->run();

        $this->assertSame([], $this->pingTransport->urls);
        $this->assertSame([], $GLOBALS['mockSentEmails']);
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * A minimal car row that passes VerificationEligibility::skipReason() and
     * carries the fields sendOne()/compose() read, so a test can drive a real
     * send through to the bookkeeping step. Mirrors
     * VerificationBatchSenderTest::makeEligibleCar().
     */
    private function makeSendableCar(int $id): object
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

    /**
     * DatabaseInterface double simulating Owner::find() succeeding — sendOne()
     * reads the owner via `new Owner(...)`, which falls back to the dbi() seam
     * at the foot of this file. Mirrors
     * VerificationBatchSenderTest::makeOwnerFoundDb().
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
     * @return list<array{category: string, message: string}>
     */
    private function logsContaining(string $needle): array
    {
        global $mockLogEntries;

        return array_values(array_filter(
            $mockLogEntries ?? [],
            static fn (array $entry): bool => str_contains((string) $entry['message'], $needle)
        ));
    }
}

// The seam CarVerificationSendService::sendOne()'s `new Owner((int)
// $carData->user_id)` falls back to when no DatabaseInterface is passed —
// same pattern as tests/unit/cars/services/VerificationBatchSenderTest.php.
// Guarded (and reading the SAME $GLOBALS['hookTestDb'] key those files use)
// so this cannot collide regardless of which file PHPUnit loads first under
// processIsolation="false".
if (!function_exists('dbi')) {
    function dbi(): DatabaseInterface
    {
        return $GLOBALS['hookTestDb'];
    }
}
