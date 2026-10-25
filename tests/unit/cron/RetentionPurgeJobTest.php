<?php

declare(strict_types=1);

use ElanRegistry\Cron\AbstractCronJob;
use ElanRegistry\Cron\CronJobGuard;
use ElanRegistry\Cron\HealthcheckPinger;
use ElanRegistry\Cron\RetentionPurgeJob;
use ElanRegistry\Exceptions\RetentionPurgeException;
use ElanRegistry\LogCategories;
use ElanRegistry\Retention\RetentionResult;
use ElanRegistry\Retention\RetentionRule;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\AbstractCronJobFakeDatabase;
use Tests\Support\RecordingHealthcheckTransport;

require_once __DIR__ . '/../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../Support/AbstractCronJobFakeDatabase.php';
require_once __DIR__ . '/../../Support/RecordingHealthcheckTransport.php';

/**
 * Stub rule: returns a fixed result, or throws, and records the $now it got.
 */
final class StubRetentionRule implements RetentionRule
{
    /** @var list<DateTimeImmutable> */
    public array $nowSeen = [];

    public function __construct(
        private readonly string $ruleName,
        private readonly ?RetentionResult $result = null,
        private readonly ?Throwable $throws = null,
    ) {
    }

    public function name(): string
    {
        return $this->ruleName;
    }

    public function purge(DateTimeImmutable $now): RetentionResult
    {
        $this->nowSeen[] = $now;
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result ?? RetentionResult::purged($this->ruleName, 0, 0);
    }
}

/**
 * Stub rule whose name() throws. purge() must never run.
 */
final class NameThrowingRetentionRule implements RetentionRule
{
    public int $purgeCalls = 0;

    public function name(): string
    {
        throw new RuntimeException('name failed');
    }

    public function purge(DateTimeImmutable $now): RetentionResult
    {
        $this->purgeCalls++;

        return RetentionResult::purged('unnamed', 0, 0);
    }
}

/**
 * Unit tests for RetentionPurgeJob (#2384).
 */
#[Group('fast')]
final class RetentionPurgeJobTest extends TestCase
{
    private const PING_BASE = 'https://hc-ping.com/KEY123/retention-purge-prod';

    /** @var array<string, array{env: string|null, getenv: string|false}> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        foreach (['HEALTHCHECKS_PING_KEY', 'HEALTHCHECKS_SLUG_SUFFIX'] as $name) {
            $this->savedEnv[$name] = ['env' => $_ENV[$name] ?? null, 'getenv' => getenv($name)];
            putenv($name);
        }
        $_ENV['HEALTHCHECKS_PING_KEY'] = 'KEY123';
        $_ENV['HEALTHCHECKS_SLUG_SUFFIX'] = '-prod';
    }

    protected function tearDown(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

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
     * @param list<RetentionRule> $rules
     */
    private function makeJob(
        AbstractCronJobFakeDatabase $db,
        array $rules,
        RecordingHealthcheckTransport $transport,
        ?DateTimeImmutable $now = null,
    ): RetentionPurgeJob {
        return new RetentionPurgeJob($db, $rules, $now, new HealthcheckPinger($transport));
    }

    private function db(): AbstractCronJobFakeDatabase
    {
        return new AbstractCronJobFakeDatabase(guardIntervalHours: 20);
    }

    /**
     * @return list<array{user_id: int, category: string, message: string, metadata: mixed}>
     */
    private function logsIn(string $category): array
    {
        global $mockLogEntries;

        return array_values(array_filter(
            $mockLogEntries,
            static fn (array $e): bool => $e['category'] === $category
        ));
    }

    // --- constants ----------------------------------------------------------

    public function testJobNameConstantMatchesGuardAllowlist(): void
    {
        $this->assertContains(
            RetentionPurgeJob::JOB_NAME,
            (new ReflectionClassConstant(CronJobGuard::class, 'ALLOWED_JOB_NAMES'))->getValue()
        );
    }

    public function testGuardIntervalIsTwentyHours(): void
    {
        $job = $this->makeJob($this->db(), [], new RecordingHealthcheckTransport());
        $method = new ReflectionMethod($job, 'guardIntervalHours');

        $this->assertSame(20, $method->invoke($job));
    }

    public function testHealthcheckSlugIsRetentionPurge(): void
    {
        $job = $this->makeJob($this->db(), [], new RecordingHealthcheckTransport());
        $method = new ReflectionMethod($job, 'healthcheckSlug');

        $this->assertSame('retention-purge', $method->invoke($job));
    }

    public function testJobNameMethodReturnsConstant(): void
    {
        $job = $this->makeJob($this->db(), [], new RecordingHealthcheckTransport());
        $method = new ReflectionMethod($job, 'jobName');

        $this->assertSame('retention_purge', $method->invoke($job));
    }

    public function testRefusesWhileSwitchOffIsNotOverridden(): void
    {
        $declaring = (new ReflectionMethod(RetentionPurgeJob::class, 'refusesWhileSwitchOff'))
            ->getDeclaringClass()->getName();

        $this->assertSame(AbstractCronJob::class, $declaring);
    }

    public function testRunExecutesWithVerificationSwitchOff(): void
    {
        $transport = new RecordingHealthcheckTransport();
        $rule = new StubRetentionRule('r1');
        $db = new AbstractCronJobFakeDatabase(guardIntervalHours: 20, verificationEnabled: false);

        $this->makeJob($db, [$rule], $transport)->run();

        $this->assertCount(1, $rule->nowSeen, 'The rule must run with the switch off');
        $this->assertSame([self::PING_BASE], $transport->urls);
    }

    // --- execute() ----------------------------------------------------------

    public function testEachRuleGetsTheSameFixedNow(): void
    {
        $now = new DateTimeImmutable('2026-10-25 03:00:00');
        $first = new StubRetentionRule('r1');
        $second = new StubRetentionRule('r2');

        $this->makeJob($this->db(), [$first, $second], new RecordingHealthcheckTransport(), $now)->run();

        $this->assertCount(1, $first->nowSeen);
        $this->assertCount(1, $second->nowSeen);
        $this->assertSame('2026-10-25 03:00:00', $first->nowSeen[0]->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-25 03:00:00', $second->nowSeen[0]->format('Y-m-d H:i:s'));
    }

    public function testOneRetentionPurgeLineForEachRuleWithCounts(): void
    {
        $rules = [
            new StubRetentionRule('alpha', RetentionResult::purged('alpha', 7, 3)),
            new StubRetentionRule('beta', RetentionResult::purged('beta', 0, 0)),
        ];

        $this->makeJob($this->db(), $rules, new RecordingHealthcheckTransport())->run();

        $lines = $this->logsIn(LogCategories::LOG_CATEGORY_RETENTION_PURGE);
        $this->assertCount(2, $lines);
        $this->assertSame("Retention rule 'alpha': 7 deleted, 3 cleared.", $lines[0]['message']);
        $this->assertSame("Retention rule 'beta': 0 deleted, 0 cleared.", $lines[1]['message']);
        $this->assertSame([], $this->logsIn(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE));
    }

    public function testSkippedRuleIsASuccessWithOneSkipLine(): void
    {
        $transport = new RecordingHealthcheckTransport();
        $db = $this->db();
        $rule = new StubRetentionRule('gone', RetentionResult::skipped('gone', 'table absent'));

        $this->makeJob($db, [$rule], $transport)->run();

        $lines = $this->logsIn(LogCategories::LOG_CATEGORY_RETENTION_PURGE);
        $this->assertCount(1, $lines);
        $this->assertSame("Retention rule 'gone' skipped: table absent", $lines[0]['message']);
        $this->assertSame([self::PING_BASE], $transport->urls, 'A skip pings success, not /fail');
        $this->assertSame(0, $db->failureStampWrites());
        $this->assertSame([], $this->logsIn(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE));
    }

    public function testThrowingFirstRuleStillRunsSecondThenRunFails(): void
    {
        $transport = new RecordingHealthcheckTransport();
        $db = $this->db();
        $first = new StubRetentionRule('broken', throws: new RuntimeException('secret@example.com'));
        $second = new StubRetentionRule('fine', RetentionResult::purged('fine', 2, 1));

        $this->makeJob($db, [$first, $second], $transport)->run();

        $this->assertCount(1, $second->nowSeen, 'The second rule must run after the first failed');
        $this->assertSame(1, $db->failureStampWrites(), 'last_failure_at must be stamped');
        $this->assertSame([self::PING_BASE . '/fail'], $transport->urls);

        $lines = $this->logsIn(LogCategories::LOG_CATEGORY_RETENTION_PURGE);
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith("Retention rule 'broken' failed: RuntimeException", $lines[0]['message']);
        $this->assertSame("Retention rule 'fine': 2 deleted, 1 cleared.", $lines[1]['message']);

        $this->assertCount(1, $this->logsIn(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE));
        global $mockLogEntries;
        foreach ($mockLogEntries as $entry) {
            $this->assertStringNotContainsString('secret@example.com', $entry['message'], 'Log lines must not hold the exception message');
        }
    }

    public function testFailureLineNamesTheStepAndSqlStateButNotTheMessage(): void
    {
        $db = $this->db();
        $rule = new StubRetentionRule('broken', throws: RetentionPurgeException::forStep(
            'delete failed: ERROR #HY000: row for secret@example.com',
            'delete',
            'HY000'
        ));

        $this->makeJob($db, [$rule], new RecordingHealthcheckTransport())->run();

        $lines = $this->logsIn(LogCategories::LOG_CATEGORY_RETENTION_PURGE);
        $this->assertCount(1, $lines);
        $this->assertStringEndsWith(' (step: delete, SQLSTATE: HY000)', $lines[0]['message']);
        $this->assertStringNotContainsString('secret@example.com', $lines[0]['message']);
    }

    public function testRuleWhoseNameThrowsDoesNotStopTheNextRuleAndTheRunFails(): void
    {
        $transport = new RecordingHealthcheckTransport();
        $db = $this->db();
        $first = new NameThrowingRetentionRule();
        $second = new StubRetentionRule('fine', RetentionResult::purged('fine', 4, 0));

        $this->makeJob($db, [$first, $second], $transport)->run();

        $this->assertSame(0, $first->purgeCalls, 'A rule with no name must not purge');
        $this->assertCount(1, $second->nowSeen, 'The next rule must run after name() threw');
        $this->assertSame(1, $db->failureStampWrites(), 'last_failure_at must be stamped');
        $this->assertSame([self::PING_BASE . '/fail'], $transport->urls);

        $lines = $this->logsIn(LogCategories::LOG_CATEGORY_RETENTION_PURGE);
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith("Retention rule 'rule #0' failed: RuntimeException", $lines[0]['message']);
        $this->assertSame("Retention rule 'fine': 4 deleted, 0 cleared.", $lines[1]['message']);
    }

    public function testExecuteThrowsRetentionPurgeExceptionNamingEveryFailedRule(): void
    {
        $rules = [
            new StubRetentionRule('one', throws: new RetentionPurgeException('db down')),
            new StubRetentionRule('two'),
            new StubRetentionRule('three', throws: new LogicException('bad')),
        ];
        $job = $this->makeJob($this->db(), $rules, new RecordingHealthcheckTransport());

        try {
            (new ReflectionMethod($job, 'execute'))->invoke($job);
            $this->fail('execute() must throw when a rule failed');
        } catch (RetentionPurgeException $e) {
            $this->assertStringContainsString('one', $e->getMessage());
            $this->assertStringContainsString('three', $e->getMessage());
            $this->assertStringNotContainsString('two', $e->getMessage(), 'A passing rule must not be named');
        }
    }

    public function testZeroRulesIsASuccess(): void
    {
        $transport = new RecordingHealthcheckTransport();
        $db = $this->db();

        $this->makeJob($db, [], $transport)->run();

        $this->assertSame([self::PING_BASE], $transport->urls);
        $this->assertSame(0, $db->failureStampWrites());
        $this->assertSame([], $this->logsIn(LogCategories::LOG_CATEGORY_RETENTION_PURGE));
        $this->assertSame([], $this->logsIn(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE));
    }

    public function testLostClaimRunsNoRule(): void
    {
        $rule = new StubRetentionRule('r1');
        $db = new AbstractCronJobFakeDatabase(claimSucceeds: false, guardIntervalHours: 20);

        $this->makeJob($db, [$rule], new RecordingHealthcheckTransport())->run();

        $this->assertSame([], $rule->nowSeen);
    }

    // --- source checks ------------------------------------------------------

    public function testShimNamesTheJobAndPolicy(): void
    {
        $path = __DIR__ . '/../../../users/cron/retention_purge.php';
        $this->assertFileExists($path);
        $source = (string) file_get_contents($path);

        $this->assertStringContainsString('new RetentionPurgeJob(', $source);
        $this->assertStringContainsString('RetentionPolicy::rules(', $source);
        $this->assertStringContainsString('->run()', $source);
    }

    public function testJobSourceNeverReadsTheVerificationSwitch(): void
    {
        $source = (string) file_get_contents(
            __DIR__ . '/../../../usersc/classes/Cron/RetentionPurgeJob.php'
        );

        $this->assertStringNotContainsString('isEnabled(', $source);
    }
}
