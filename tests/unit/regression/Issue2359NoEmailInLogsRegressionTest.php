<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EffectiveEmailFlags;
use ElanRegistry\Car\EmailEventMatch;
use ElanRegistry\Car\EmailEventMatcher;
use ElanRegistry\Cron\BrevoEventReconciliationJob;
use ElanRegistry\Cron\BrevoSuppressionSyncJob;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\AbstractCronJobFakeDatabase;
use Tests\Support\FakeBrevoBlockedContact;
use Tests\Support\FakeBrevoEvent;
use Tests\Support\FakeBrevoEventReconciliationClient;
use Tests\Support\FakeBrevoSuppressionSyncClient;
use Tests\Support\SpyEmailEventApplier;

require_once __DIR__ . '/../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../Support/AbstractCronJobFakeDatabase.php';
require_once __DIR__ . '/../../Support/FakeBrevoEvent.php';
require_once __DIR__ . '/../../Support/FakeBrevoEventReconciliationClient.php';
require_once __DIR__ . '/../../Support/FakeBrevoBlockedContact.php';
require_once __DIR__ . '/../../Support/FakeBrevoSuppressionSyncClient.php';
require_once __DIR__ . '/../../Support/SpyEmailEventApplier.php';

/**
 * Issue #2359: no log line holds an email address, and no `catch` block of the
 * Brevo event paths reads an exception message.
 *
 * `CarRepository` puts the address in its exception messages, so a log line
 * that holds `$e->getMessage()` can leak an address into the `logs` table.
 *
 * Part 1 is a tokenizer scan of the touched files. It fails when a `catch`
 * block calls `->getMessage()`. The scan has negative controls: it runs on
 * in-memory source that has the forbidden call, and must report it.
 *
 * Part 2 is behaviour. It makes the two Brevo jobs throw an exception that has
 * an address in its message, on every entry point and on each `catch` that
 * handles a database failure, and shows that no log row holds the address.
 * The webhook path (matcher, applier and plugin-function failures) has its
 * behaviour tests in BrevoWebhookEventProcessorTest.
 *
 * @issue 2359
 * @link https://github.com/elan-registry/registry/issues/2359
 */
#[Group('regression')]
final class Issue2359NoEmailInLogsRegressionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** An address that is easy to find in a log row. */
    private const ADDRESS = 'leak-test-2359@example.org';

    private const NOW = '2026-09-09 03:00:00';

    /** Functions whose result holds no address: a hash, a type name, a length. Lower case. */
    private const ADDRESS_SAFE_CALLS = ['addressloghash', 'get_debug_type', 'gettype', 'strlen'];

    /**
     * The files that the criterion names, plus the two classes of the event
     * path that hold no `catch` block today. The value is the least number of
     * `catch` blocks that the file has, so a rewrite that removes the blocks
     * (and so the scan) fails here instead of passing with nothing scanned.
     *
     * @var array<string, int>
     */
    private const SCANNED_FILES = [
        'app/api/webhooks/brevo.php' => 3,
        'usersc/classes/Car/BrevoWebhookEventProcessor.php' => 3,
        'usersc/classes/Cron/AbstractCronJob.php' => 2,
        'usersc/classes/Cron/BrevoEventReconciliationJob.php' => 4,
        'usersc/classes/Cron/BrevoSuppressionSyncJob.php' => 5,
        'app/admin/scripts/maintenance/27-Reconcile-Brevo-Events.php' => 1,
        'app/admin/scripts/maintenance/28-Reconcile-Brevo-Suppressions.php' => 1,
        'usersc/classes/Car/EmailEventApplier.php' => 2,
        'usersc/classes/Car/EmailEventMatcher.php' => 0,
    ];

    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];
    }

    // ------------------------------------------------------------------
    // Part 1: source scan
    // ------------------------------------------------------------------

    /**
     * Find the `catch` blocks of a source and the `->getMessage()` calls in them
     *
     * @return array{catches: int, offending: list<int>} The number of `catch`
     *         blocks, and the line of each `->getMessage()` call inside one
     */
    public static function scanCatchBlocks(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $catches = 0;
        $offending = [];

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CATCH) {
                continue;
            }
            $catches++;

            // Skip the "(Type $e)" part, then find the "{" of the block.
            $j = $i + 1;
            while ($j < $count && $tokens[$j] !== '{') {
                $j++;
            }

            $depth = 0;
            for (; $j < $count; $j++) {
                $token = $tokens[$j];
                if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'getmessage') {
                    $previous = self::previousCodeToken($tokens, $j);
                    if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                        $offending[] = $token[2];
                    }
                }
            }
        }

        // A call in a nested catch block is inside two blocks.
        return ['catches' => $catches, 'offending' => array_values(array_unique($offending))];
    }

    /**
     * Find `->getMessage()` calls on a variable that is named like an exception
     *
     * Part of the scan above sees a `catch` block only. A helper method that
     * receives the exception (for example `logFailure(..., CarDatabaseException $e)`)
     * has no `catch` of its own, so this finds a call there. A getter of another
     * object, such as Brevo's `$reason->getMessage()`, is not an exception and
     * is not found.
     *
     * @return list<int> The line of each call
     */
    public static function exceptionMessageCalls(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $lines = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_VARIABLE
                || preg_match('/^\$(e|ex|exception|throwable|previous|\w*Exception|\w*Failure)$/', $token[1]) !== 1
            ) {
                continue;
            }
            $arrowIndex = self::nextCodeIndex($tokens, $i);
            $arrow = $arrowIndex === null ? null : $tokens[$arrowIndex];
            if (!is_array($arrow) || !in_array($arrow[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }
            $methodIndex = self::nextCodeIndex($tokens, $arrowIndex);
            $method = $methodIndex === null ? null : $tokens[$methodIndex];
            if (is_array($method) && $method[0] === T_STRING && strtolower($method[1]) === 'getmessage') {
                $lines[] = $method[2];
            }
        }

        return $lines;
    }

    /**
     * Find an address in the arguments of a `logger(` call
     *
     * The scans above look at exception messages. This scan looks at the
     * arguments of each `logger(` call (also inside a string, as in `"{$email}"`).
     * It reports a variable that is named like an address, an `['email']` key,
     * and an `->email` property. A call to `addressLogHash(` is allowed: it
     * returns a hash, not the address. A call to `get_debug_type(` or `strlen(`
     * and a comparison such as `$x === ''` also give no address. A message that a caller builds first and
     * then passes in a variable is not seen. The behaviour tests in Part 2 and in
     * BrevoWebhookEventProcessorTest cover that case.
     *
     * @return list<array{line: int, found: string}> Each hit
     */
    public static function addressesInLoggerCalls(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $hits = [];

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || strtolower($tokens[$i][1]) !== 'logger') {
                continue;
            }
            $previous = self::previousCodeToken($tokens, $i);
            if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }
            $openIndex = self::nextCodeIndex($tokens, $i);
            if ($openIndex === null || $tokens[$openIndex] !== '(') {
                continue;
            }

            $depth = 0;
            // Parenthesis depth at which an addressLogHash( call ends, or null outside one.
            $allowedUntil = null;
            for ($j = $openIndex; $j < $count; $j++) {
                $token = $tokens[$j];
                if ($token === '(') {
                    $depth++;
                    continue;
                }
                if ($token === ')') {
                    $depth--;
                    if ($allowedUntil !== null && $depth < $allowedUntil) {
                        $allowedUntil = null;
                    }
                    if ($depth === 0) {
                        break;
                    }
                    continue;
                }
                if (!is_array($token)) {
                    continue;
                }
                if ($token[0] === T_STRING && in_array(strtolower($token[1]), self::ADDRESS_SAFE_CALLS, true)) {
                    $next = self::nextCodeIndex($tokens, $j);
                    if ($next !== null && $tokens[$next] === '(' && $allowedUntil === null) {
                        $allowedUntil = $depth + 1;
                    }
                    continue;
                }
                if ($allowedUntil !== null) {
                    continue;
                }
                if ($token[0] === T_VARIABLE && self::isComparedToALiteral($tokens, $j)) {
                    // "$email === ''" gives a bool, not the address.
                    continue;
                }
                if ($token[0] === T_VARIABLE && preg_match('/^\$(email|address|addr|recipient|normalized|\w*Email|\w*Address)$/i', $token[1]) === 1) {
                    $hits[] = ['line' => $token[2], 'found' => $token[1]];
                } elseif ($token[0] === T_OBJECT_OPERATOR || $token[0] === T_NULLSAFE_OBJECT_OPERATOR) {
                    $next = self::nextCodeIndex($tokens, $j);
                    if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING && strtolower($tokens[$next][1]) === 'email') {
                        $hits[] = ['line' => $tokens[$next][2], 'found' => '->email'];
                    }
                } elseif ($token[0] === T_CONSTANT_ENCAPSED_STRING && in_array(strtolower(trim($token[1], '\'"')), ['email', 'recipient_email'], true)) {
                    $previousToken = self::previousCodeToken($tokens, $j);
                    if ($previousToken === '[') {
                        $hits[] = ['line' => $token[2], 'found' => "[{$token[1]}]"];
                    }
                }
            }
        }

        return $hits;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isComparedToALiteral(array $tokens, int $index): bool
    {
        $next = self::nextCodeIndex($tokens, $index);

        return $next !== null && is_array($tokens[$next])
            && in_array($tokens[$next][0], [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL], true);
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextCodeIndex(array $tokens, int $index): ?int
    {
        $count = count($tokens);
        for ($i = $index + 1; $i < $count; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function previousCodeToken(array $tokens, int $index): array|string|null
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function scannedFileProvider(): array
    {
        $cases = [];
        foreach (self::SCANNED_FILES as $path => $minimumCatches) {
            $cases[$path] = [$path, $minimumCatches];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function scannedPathProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::SCANNED_FILES) as $path) {
            $cases[$path] = [$path];
        }

        return $cases;
    }

    #[DataProvider('scannedFileProvider')]
    public function testNoCatchBlockCallsGetMessage(string $path, int $minimumCatches): void
    {
        $source = file_get_contents(self::ROOT . '/' . $path);
        $this->assertIsString($source, "{$path} must be readable");

        $scan = self::scanCatchBlocks($source);

        $this->assertGreaterThanOrEqual($minimumCatches, $scan['catches'], "{$path}: the scan must find the catch blocks");
        $this->assertSame(
            [],
            $scan['offending'],
            "{$path}: a catch block calls ->getMessage() on line(s) " . implode(', ', $scan['offending'])
            . '. CarRepository puts the address in its exception messages. Log get_class() and getCode() only.'
        );
    }

    #[DataProvider('scannedPathProvider')]
    public function testNoExceptionVariableCallsGetMessageAnywhereInTheFile(string $path): void
    {
        $source = file_get_contents(self::ROOT . '/' . $path);
        $this->assertIsString($source, "{$path} must be readable");

        $this->assertSame(
            [],
            self::exceptionMessageCalls($source),
            "{$path}: an exception variable calls ->getMessage() (a helper that logs a caught exception is not a catch block)"
        );
    }

    #[DataProvider('scannedPathProvider')]
    public function testNoLoggerCallHoldsAnAddress(string $path): void
    {
        $source = file_get_contents(self::ROOT . '/' . $path);
        $this->assertIsString($source, "{$path} must be readable");

        $hits = self::addressesInLoggerCalls($source);

        $this->assertSame(
            [],
            $hits,
            "{$path}: a logger() call has an address in its arguments: "
            . implode(', ', array_map(static fn (array $h): string => "{$h['found']} (line {$h['line']})", $hits))
            . '. Log the user ID and EmailEventMatcher::addressLogHash() instead.'
        );
    }

    public function testLoggerScanSeesAnAddressInAVariableAnInterpolationAndAnArrayKey(): void
    {
        $source = <<<'PHP'
        <?php
        logger(0, 'x', 'bounce for ' . $email);
        logger(0, 'x', "bounce for {$recipient}");
        logger(0, 'x', sprintf('row %s', $row['email']));
        logger(0, 'x', sprintf('row %s', $row->email));
        logger(0, 'x', sprintf('row %s', $normalized));
        PHP;

        $hits = self::addressesInLoggerCalls($source);

        $this->assertSame([2, 3, 4, 5, 6], array_column($hits, 'line'));
    }

    public function testLoggerScanAllowsTheHashHelperAndOtherCode(): void
    {
        $source = <<<'PHP'
        <?php
        logger(0, 'x', sprintf('user %d address %s', $userId, EmailEventMatcher::addressLogHash($email)));
        logger(0, 'x', 'hash ' . addressLogHash($address) . ' for user ' . $userId);
        $note = 'mail ' . $email;
        $other->logger('x', $email);
        PHP;

        $this->assertSame([], self::addressesInLoggerCalls($source));
    }

    public function testLoggerScanAllowsATypeNameAndAComparison(): void
    {
        $source = <<<'PHP'
        <?php
        logger(0, 'x', sprintf('email=%s', get_debug_type($rawEmail)));
        logger(0, 'x', sprintf('email=%s', $email === '' ? '(empty)' : '(set)'));
        PHP;

        $this->assertSame([], self::addressesInLoggerCalls($source));
    }

    public function testExceptionCallScanSeesAHelperAndIgnoresAnotherObject(): void
    {
        $source = <<<'PHP'
        <?php
        function logFailure(CarDatabaseException $e): void
        {
            logger(0, 'x', $e->getMessage());
        }
        function reasonText($reason): ?string
        {
            return $reason->getMessage();
        }
        PHP;

        $this->assertSame([4], self::exceptionMessageCalls($source));
    }

    public function testScanSeesAGetMessageCallInACatchBlock(): void
    {
        $source = <<<'PHP'
        <?php
        function a(): void
        {
            try {
                run();
            } catch (\Throwable $e) {
                if ($e instanceof X) {
                    logger(0, 'x', "failed: {$e->getCode()}");
                }
                logger(0, 'x', 'failed: ' . $e->getMessage());
            }
        }
        PHP;

        $scan = self::scanCatchBlocks($source);

        $this->assertSame(1, $scan['catches']);
        $this->assertSame([10], $scan['offending']);
    }

    public function testScanSeesANullsafeCallAndACallInANestedCatch(): void
    {
        $source = <<<'PHP'
        <?php
        try {
            a();
        } catch (\Exception $outer) {
            try {
                b();
            } catch (\Exception $inner) {
                $x = $inner
                    ?->getMessage();
            }
        }
        PHP;

        $scan = self::scanCatchBlocks($source);

        $this->assertSame(2, $scan['catches']);
        $this->assertSame([9], $scan['offending']);
    }

    public function testScanSeesACallInAnInlineHtmlIslandOfACatchBlock(): void
    {
        $source = "<?php try { a(); } catch (\\Throwable \$e) { ?>\n<p><?= htmlspecialchars(\$e->getMessage()) ?></p>\n<?php } ?>";

        $scan = self::scanCatchBlocks($source);

        $this->assertSame(1, $scan['catches']);
        $this->assertCount(1, $scan['offending']);
    }

    public function testScanIgnoresGetMessageOutsideACatchBlockAndInCommentsAndStrings(): void
    {
        $source = <<<'PHP'
        <?php
        $note = $e->getMessage();
        try {
            a();
        } catch (\Throwable $e) {
            // Never call $e->getMessage() here.
            logger(0, 'x', 'no ->getMessage() in a log line');
        }
        PHP;

        $scan = self::scanCatchBlocks($source);

        $this->assertSame(1, $scan['catches']);
        $this->assertSame([], $scan['offending']);
    }

    // ------------------------------------------------------------------
    // Part 2: behaviour of the two Brevo jobs
    // ------------------------------------------------------------------

    /**
     * Fail when a log entry (message or metadata) holds the address, and when
     * no entry exists, because a test with no log line proves nothing.
     */
    private function assertLogsHoldNoAddress(string $expectedFragment): void
    {
        global $mockLogEntries;

        $this->assertNotEmpty($mockLogEntries, 'The failure must be logged');
        $found = false;
        foreach ($mockLogEntries as $entry) {
            $text = strtolower((string) json_encode($entry));
            $this->assertStringNotContainsString(self::ADDRESS, $text, 'A log row holds the address');
            $this->assertStringNotContainsString('leak-test-2359', $text, 'A log row holds the local part of the address');
            if (str_contains($text, strtolower($expectedFragment))) {
                $found = true;
            }
        }
        $this->assertTrue($found, "A log row must show the failure: {$expectedFragment}");
    }

    private static function leakingRuntimeException(): RuntimeException
    {
        return new RuntimeException('something failed for ' . self::ADDRESS, 7);
    }

    private static function leakingDatabaseException(): CarDatabaseException
    {
        return new CarDatabaseException('query failed for ' . self::ADDRESS, 1062);
    }

    private function matchedMatcher(): EmailEventMatcher
    {
        $matcher = $this->createStub(EmailEventMatcher::class);
        $matcher->method('matchByMessageId')->willReturn(EmailEventMatch::matched(10, null, '2026-09-08 04:00:00'));

        return $matcher;
    }

    private function cronDatabase(): AbstractCronJobFakeDatabase
    {
        return new AbstractCronJobFakeDatabase(enabled: true, claimSucceeds: true, guardIntervalHours: 20);
    }

    /**
     * @param \Closure(CarRepository&\PHPUnit\Framework\MockObject\Stub): void|null $configureRepo
     */
    private function reconciliationJob(
        SpyEmailEventApplier $applier,
        ?EmailEventMatcher $matcher = null,
        ?\Closure $configureRepo = null
    ): BrevoEventReconciliationJob {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('deleteEmailEventsOlderThan')->willReturn(0);
        if ($configureRepo !== null) {
            $configureRepo($repo);
        }

        return new BrevoEventReconciliationJob(
            $this->cronDatabase(),
            $repo,
            $matcher ?? $this->matchedMatcher(),
            $applier,
            new FakeBrevoEventReconciliationClient([
                new FakeBrevoEvent(email: self::ADDRESS, event: 'hardBounces', messageId: 'm-2359'),
            ]),
            new DateTimeImmutable(self::NOW)
        );
    }

    /**
     * @param \Closure(CarRepository&\PHPUnit\Framework\MockObject\Stub): void|null $configureRepo
     */
    private function suppressionJob(SpyEmailEventApplier $applier, ?\Closure $configureRepo = null): BrevoSuppressionSyncJob
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('findUserIdsByAddress')->willReturn([10]);
        $repo->method('effectiveEmailFlags')->willReturn(
            new EffectiveEmailFlags(true, false, null, false, null, null, null, false, null, null, self::ADDRESS)
        );
        if ($configureRepo !== null) {
            $configureRepo($repo);
        }

        return new BrevoSuppressionSyncJob(
            $this->cronDatabase(),
            $repo,
            new EmailEventMatcher($repo),
            $applier,
            new FakeBrevoSuppressionSyncClient([
                FakeBrevoSuppressionSyncClient::page([
                    FakeBrevoBlockedContact::withReason(self::ADDRESS, 'hardBounce', 'mailbox full'),
                ]),
            ]),
            new DateTimeImmutable(self::NOW)
        );
    }

    /**
     * Call one entry point. `runNowWithSummary()` rethrows by design.
     */
    private function enter(object $job, string $entry): void
    {
        try {
            $job->{$entry}();
        } catch (RuntimeException $e) {
            // runNowWithSummary() rethrows after it logs. A test of run() and
            // runNow() must not see an exception here.
            $this->assertSame('runNowWithSummary', $entry, "{$entry}() must not throw");
            $this->assertSame(self::leakingRuntimeException()->getMessage(), $e->getMessage());
        }
    }

    /**
     * @return array<string, array{string, string}> Job, entry point
     */
    public static function crashEntryProvider(): array
    {
        $cases = [];
        foreach (['reconciliation', 'suppression'] as $job) {
            foreach (['run', 'runNow', 'runNowWithSummary'] as $entry) {
                $cases["{$job} {$entry}()"] = [$job, $entry];
            }
        }

        return $cases;
    }

    /** A crash of the job reaches the catch of the entry point, which logs the class and code only. */
    #[DataProvider('crashEntryProvider')]
    public function testCrashLogsClassAndCodeNotTheExceptionMessage(string $job, string $entry): void
    {
        $applier = new SpyEmailEventApplier();
        $applier->failWith(self::leakingRuntimeException());
        $instance = $job === 'reconciliation'
            ? $this->reconciliationJob($applier)
            : $this->suppressionJob($applier);

        $this->enter($instance, $entry);

        $this->assertCount(1, $applier->calls, 'The crash must come from the apply step');
        $this->assertLogsHoldNoAddress('RuntimeException');
    }

    /**
     * @return array<string, array{string}> Entry point
     */
    public static function nonThrowingEntryProvider(): array
    {
        return ['run()' => ['run'], 'runNow()' => ['runNow'], 'runNowWithSummary()' => ['runNowWithSummary']];
    }

    /** The event-write catch of the reconciliation job. */
    #[DataProvider('nonThrowingEntryProvider')]
    public function testReconciliationEventWriteFailureLogsNoAddress(string $entry): void
    {
        $applier = new SpyEmailEventApplier();
        $applier->failWith(self::leakingDatabaseException());

        $this->enter($this->reconciliationJob($applier), $entry);

        $this->assertCount(1, $applier->calls);
        $this->assertLogsHoldNoAddress('write FAILED');
    }

    /** The user-lookup catch of the reconciliation job. */
    #[DataProvider('nonThrowingEntryProvider')]
    public function testReconciliationUserLookupFailureLogsNoAddress(string $entry): void
    {
        $matcher = $this->createStub(EmailEventMatcher::class);
        $matcher->method('matchByMessageId')->willThrowException(self::leakingDatabaseException());
        $applier = new SpyEmailEventApplier();

        $this->enter($this->reconciliationJob($applier, $matcher), $entry);

        $this->assertSame([], $applier->calls);
        $this->assertLogsHoldNoAddress('user lookup FAILED');
    }

    /** The prune catch of the reconciliation job, for a database failure and for a PDO failure. */
    #[DataProvider('nonThrowingEntryProvider')]
    public function testReconciliationPruneFailureLogsNoAddress(string $entry): void
    {
        $job = $this->reconciliationJob(
            new SpyEmailEventApplier(),
            null,
            static function ($repo): void {
                $repo->method('deleteEmailEventsOlderThan')
                    ->willThrowException(new CarDatabaseException('delete failed for ' . self::ADDRESS, 1451));
            }
        );

        $this->enter($job, $entry);

        $this->assertLogsHoldNoAddress('retention prune FAILED');
    }

    public function testReconciliationPrunePdoFailureLogsNoAddress(): void
    {
        $job = $this->reconciliationJob(
            new SpyEmailEventApplier(),
            null,
            static function ($repo): void {
                $repo->method('deleteEmailEventsOlderThan')
                    ->willThrowException(new PDOException('SQLSTATE failed near ' . self::ADDRESS));
            }
        );

        $job->runNow();

        $this->assertLogsHoldNoAddress('retention prune FAILED');
    }

    /** The event-write catch of the suppression import. */
    #[DataProvider('nonThrowingEntryProvider')]
    public function testSuppressionWriteFailureLogsNoAddress(string $entry): void
    {
        $applier = new SpyEmailEventApplier();
        $applier->failWith(self::leakingDatabaseException());

        $this->enter($this->suppressionJob($applier), $entry);

        $this->assertCount(1, $applier->calls);
        $this->assertLogsHoldNoAddress('write FAILED');
    }

    /** The user-lookup catch of the suppression import. */
    #[DataProvider('nonThrowingEntryProvider')]
    public function testSuppressionUserLookupFailureLogsNoAddress(string $entry): void
    {
        $applier = new SpyEmailEventApplier();
        $job = $this->suppressionJob(
            $applier,
            static function ($repo): void {
                $repo->method('findUserIdsByAddress')->willThrowException(
                    new CarDatabaseException('lookup failed for ' . self::ADDRESS, 2006)
                );
            }
        );

        $this->enter($job, $entry);

        $this->assertSame([], $applier->calls);
        $this->assertLogsHoldNoAddress('user lookup FAILED');
    }

    /** The flag pre-read catch of the suppression import. The write still happens. */
    #[DataProvider('nonThrowingEntryProvider')]
    public function testSuppressionFlagPreReadFailureLogsNoAddress(string $entry): void
    {
        $applier = new SpyEmailEventApplier();
        $job = $this->suppressionJob(
            $applier,
            static function ($repo): void {
                $repo->method('effectiveEmailFlags')->willThrowException(
                    new CarDatabaseException('profile read failed for ' . self::ADDRESS, 2013)
                );
            }
        );

        $this->enter($job, $entry);

        $this->assertCount(1, $applier->calls, 'A failed pre-read must not stop the write');
        $this->assertLogsHoldNoAddress('could not read current flags');
    }
}
