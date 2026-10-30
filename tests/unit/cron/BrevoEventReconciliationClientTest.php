<?php

declare(strict_types=1);

use ElanRegistry\Cron\BrevoClientReadiness;
use ElanRegistry\Cron\BrevoEventReconciliationClient;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\SendinblueSettingsFakeDatabase;

require_once __DIR__ . '/../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../Support/SendinblueSettingsFakeDatabase.php';

/**
 * Unit tests for BrevoEventReconciliationClient's pre-flight paths (#1889).
 *
 * Scope is deliberately every path that returns *before* the outbound HTTP
 * call. The Brevo SDK is vendored in this tree and does autoload, so anything
 * reaching past the credential and SDK gates makes a live call to
 * api.brevo.com — see the note at the foot of this class.
 *
 * Every path here must return null *and* log (#2061: null means the poll
 * failed, distinct from a genuine empty page). The log is the only signal an
 * operator has beyond the return value, so a silent null is the one outcome
 * that must never happen.
 */
#[Group('fast')]
final class BrevoEventReconciliationClientTest extends TestCase
{
    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];
    }

    private function fetch(SendinblueSettingsFakeDatabase $db): ?array
    {
        return (new BrevoEventReconciliationClient($db))->fetchEvents(
            new DateTimeImmutable('2026-09-07'),
            new DateTimeImmutable('2026-09-09'),
            1000,
            0,
            'hardBounces'
        );
    }

    /**
     * A missing plg_sendinblue table means the plugin was never installed —
     * an expected steady state on any environment without Brevo, not a fault.
     * Logging it as a failure would put a "cron job failure" in the log on
     * every claimed run forever, drowning the category an operator filters on.
     */
    public function testMissingSendinblueTableIsLoggedAsASkipNotAFailure(): void
    {
        global $mockLogEntries;

        $this->assertNull($this->fetch(new SendinblueSettingsFakeDatabase(sqlState: '42S02')));

        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('plg_sendinblue table absent', $mockLogEntries[0]['message']);
    }

    /**
     * Any other SQLSTATE means the key may well exist and the poll is being
     * skipped for an unrelated infrastructure fault — a real failure, and one
     * that must not send an operator off to check a correct Brevo config.
     */
    public function testOtherDatabaseErrorIsLoggedAsAFailure(): void
    {
        global $mockLogEntries;

        $this->assertNull($this->fetch(new SendinblueSettingsFakeDatabase(sqlState: 'HY000')));

        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('DB connectivity/grants', $mockLogEntries[0]['message']);
    }

    /** An environment with no key configured is correctly configured for what it is. */
    public function testUnconfiguredKeyIsLoggedAsASkipNotAFailure(): void
    {
        global $mockLogEntries;

        $this->assertNull($this->fetch(new SendinblueSettingsFakeDatabase(key: null)));

        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('no Brevo API key configured', $mockLogEntries[0]['message']);
    }

    /**
     * @return array<string, array{SendinblueSettingsFakeDatabase, BrevoClientReadiness, string}>
     */
    public static function readinessWithoutKeyProvider(): array
    {
        return [
            'table absent (42S02)' => [
                new SendinblueSettingsFakeDatabase(sqlState: '42S02'),
                BrevoClientReadiness::NOT_CONFIGURED,
                LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED,
            ],
            'empty key' => [
                new SendinblueSettingsFakeDatabase(key: ''),
                BrevoClientReadiness::NOT_CONFIGURED,
                LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED,
            ],
            'no row' => [
                new SendinblueSettingsFakeDatabase(key: null),
                BrevoClientReadiness::NOT_CONFIGURED,
                LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED,
            ],
            'other DB error (HY000)' => [
                new SendinblueSettingsFakeDatabase(sqlState: 'HY000'),
                BrevoClientReadiness::FAILED,
                LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
            ],
        ];
    }

    /** T9: each no-key cause maps to one readiness case and one log row of the matching category. */
    #[DataProvider('readinessWithoutKeyProvider')]
    public function testReadinessWithoutKeyMapsCauseToCaseAndLogCategory(
        SendinblueSettingsFakeDatabase $db,
        BrevoClientReadiness $expected,
        string $expectedCategory
    ): void {
        global $mockLogEntries;

        $this->assertSame($expected, (new BrevoEventReconciliationClient($db))->readiness());

        $this->assertCount(1, $mockLogEntries);
        $this->assertSame($expectedCategory, $mockLogEntries[0]['category']);
    }

    /**
     * T9: key present and SDK class loaded gives READY with no log row.
     *
     * Separate process: when the SDK is not installed the test defines a stub
     * class, which must not leak into other tests.
     */
    #[RunInSeparateProcess]
    public function testReadinessWithKeyAndSdkLoadedIsReady(): void
    {
        global $mockLogEntries;

        if (!class_exists('\Brevo\Client\Api\TransactionalEmailsApi')) {
            eval('namespace Brevo\\Client\\Api; class TransactionalEmailsApi {}');
        }

        $client = new BrevoEventReconciliationClient(new SendinblueSettingsFakeDatabase(key: 'test-api-key'));

        $this->assertSame(BrevoClientReadiness::READY, $client->readiness());
        $this->assertSame([], $mockLogEntries);
    }

    /**
     * T10 (found in #2420): a poll that throws with an address in the
     * message reaches the poll catch, returns null, and logs no address.
     */
    public function testFetchEventsExceptionMessageAddressIsNotLogged(): void
    {
        global $mockLogEntries;

        $client = new class (new SendinblueSettingsFakeDatabase(key: 'test-api-key')) extends BrevoEventReconciliationClient {
            /** @return object */
            protected function createApi(string $apiKey): object
            {
                return new class {
                    public function getEmailEventReport(mixed ...$args): never
                    {
                        throw new \RuntimeException('Brevo rejected the request for owner@example.com');
                    }
                };
            }
        };

        $result = $client->fetchEvents(
            new DateTimeImmutable('2026-09-07'),
            new DateTimeImmutable('2026-09-09'),
            1000,
            0,
            'hardBounces'
        );

        $this->assertNull($result);
        $this->assertNotEmpty($mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $mockLogEntries[0]['category']);
        foreach ($mockLogEntries as $entry) {
            $this->assertStringNotContainsString('owner@example.com', (string) json_encode($entry));
        }
    }

    // NOTE: no test passes a valid key through the real createApi(). Where the
    // Brevo SDK autoloads, that reaches the real `new GuzzleHttp\Client()` and
    // makes a live HTTPS call to api.brevo.com. A unit test must not depend
    // on the network, so the poll path is tested through the createApi() seam
    // (#2480) and the READY case through a stub SDK class.
    //
    // loadSdk()'s "autoloader missing" and "autoloader throws" branches are
    // still not unit-testable: the branch depends on whether a real file
    // exists at a path fixed by a private class constant. They are single
    // log-and-return statements, left to code review rather than reshaping
    // the class to make a constant injectable purely for a test.
}
