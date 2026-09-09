<?php

declare(strict_types=1);

use ElanRegistry\Cron\BrevoEventReconciliationClient;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
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
 * Every path here must return [] *and* log. The class's whole contract is
 * that a failed poll is never distinguishable in the return value (see its
 * class docblock), which makes the log the only signal an operator has — so a
 * silent [] is the one outcome that must never happen.
 */
#[Group('fast')]
final class BrevoEventReconciliationClientTest extends TestCase
{
    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];
    }

    private function fetch(SendinblueSettingsFakeDatabase $db): array
    {
        return (new BrevoEventReconciliationClient($db))->fetchEvents(
            new DateTimeImmutable('2026-09-07'),
            new DateTimeImmutable('2026-09-09'),
            1000,
            0
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

        $this->assertSame([], $this->fetch(new SendinblueSettingsFakeDatabase(sqlState: '42S02')));

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

        $this->assertSame([], $this->fetch(new SendinblueSettingsFakeDatabase(sqlState: 'HY000')));

        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('DB connectivity/grants', $mockLogEntries[0]['message']);
    }

    /** An environment with no key configured is correctly configured for what it is. */
    public function testUnconfiguredKeyIsLoggedAsASkipNotAFailure(): void
    {
        global $mockLogEntries;

        $this->assertSame([], $this->fetch(new SendinblueSettingsFakeDatabase(key: null)));

        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('no Brevo API key configured', $mockLogEntries[0]['message']);
    }

    // NOTE: there is deliberately no test that passes a *valid* key through
    // fetchEvents(). The Brevo SDK is vendored in this working tree and its
    // classes autoload, so getting past apiKey() and loadSdk() reaches the
    // real `new GuzzleHttp\Client()` and makes a live HTTPS call to
    // api.brevo.com — verified: an early draft of this file did exactly that
    // and failed on Brevo's 401. A unit test must not depend on the network,
    // so every case here is one that returns before the HTTP call.
    //
    // loadSdk()'s three branches are consequently not unit-testable: which one
    // is taken depends on whether a real file exists at a path fixed by a
    // private class constant, and the "SDK present" branch falls through to
    // that live call. They are single log-and-return-false statements, left to
    // code review and manual verification rather than reshaping the class to
    // make a constant injectable purely for a test. The integration suite
    // covers the job end to end with the client substituted.
}
