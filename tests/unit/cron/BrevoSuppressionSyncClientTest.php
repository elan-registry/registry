<?php

declare(strict_types=1);

use ElanRegistry\Cron\BrevoClientReadiness;
use ElanRegistry\Cron\BrevoSuppressionSyncClient;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\NoSdkBrevoSuppressionSyncClient;
use Tests\Support\SendinblueSettingsFakeDatabase;
use Tests\Support\ThrowingBrevoSuppressionSyncClient;

require_once __DIR__ . '/../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../Support/SendinblueSettingsFakeDatabase.php';
require_once __DIR__ . '/../../Support/ThrowingBrevoSuppressionSyncClient.php';
require_once __DIR__ . '/../../Support/NoSdkBrevoSuppressionSyncClient.php';

/**
 * Unit tests for BrevoSuppressionSyncClient's pre-flight paths (#1923) and
 * its poll catch (#2425).
 *
 * The pre-flight cases return *before* the outbound HTTP call. The poll catch
 * is reached through the `createApi()` seam, with no SDK and no network. The
 * SDK load faults are reached through a scratch site root. See the note at
 * the foot of this class.
 *
 * Every path here must return null *and* log. Null is the client's only signal
 * that a poll failed, and callers page on `getCount()` and must stop on null
 * rather than conclude the suppression list has ended (see the class
 * docblock) — so a silent null is the one outcome that must never happen.
 */
#[Group('fast')]
final class BrevoSuppressionSyncClientTest extends TestCase
{
    private ?string $sdkRoot = null;

    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];
    }

    protected function tearDown(): void
    {
        if ($this->sdkRoot !== null) {
            $dir = $this->sdkRoot . 'usersc/plugins/sendinblue/vendor';
            @unlink($dir . '/autoload.php');
            foreach ([$dir, dirname($dir), dirname($dir, 2), dirname($dir, 3), $this->sdkRoot] as $path) {
                @rmdir($path);
            }
        }
    }

    /**
     * Point BrevoApiFactory::loadSdk() at a fake site root that holds the
     * given autoloader. Null: no autoloader file.
     */
    private function clientWithSdkAutoloader(?string $autoloaderSource): BrevoSuppressionSyncClient
    {
        global $abs_us_root, $us_url_root;
        $root = sys_get_temp_dir() . '/brevo-sync-sdk-' . bin2hex(random_bytes(4)) . '/';
        $dir = $root . 'usersc/plugins/sendinblue/vendor';
        $this->assertTrue(mkdir($dir, 0777, true));
        $this->sdkRoot = $root;
        if ($autoloaderSource !== null) {
            file_put_contents($dir . '/autoload.php', $autoloaderSource);
        }
        $abs_us_root = $root;
        $us_url_root = '';

        return new BrevoSuppressionSyncClient(new SendinblueSettingsFakeDatabase(key: 'test-api-key'));
    }

    private function fetch(SendinblueSettingsFakeDatabase $db): ?\Brevo\Client\Model\GetTransacBlockedContacts
    {
        return (new BrevoSuppressionSyncClient($db))->fetchBlockedContacts(
            new DateTimeImmutable('2026-09-07'),
            new DateTimeImmutable('2026-09-09'),
            100,
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
     * A Brevo error body can echo a recipient address. The poll catch must
     * log the ExceptionLogSummary (class, code, throw place), never the
     * message (#2425). The test checks the class and the code.
     */
    public function testPollFailureLogsClassAndCodeButNoAddress(): void
    {
        global $mockLogEntries;

        $client = new ThrowingBrevoSuppressionSyncClient(new SendinblueSettingsFakeDatabase());
        $result = $client->fetchBlockedContacts(
            new DateTimeImmutable('2026-09-07'),
            new DateTimeImmutable('2026-09-09'),
            100,
            0
        );

        $this->assertNull($result);
        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $mockLogEntries[0]['category']);
        $this->assertStringContainsString(
            sprintf('RuntimeException (code %d)', ThrowingBrevoSuppressionSyncClient::EXCEPTION_CODE),
            $mockLogEntries[0]['message']
        );

        $address = ThrowingBrevoSuppressionSyncClient::LEAKED_ADDRESS;
        $localPart = strstr($address, '@', true);
        $this->assertIsString($localPart);
        foreach ($mockLogEntries as $entry) {
            $logged = $entry['message'] . ' ' . json_encode($entry['metadata']);
            $this->assertStringNotContainsStringIgnoringCase($address, $logged);
            $this->assertStringNotContainsStringIgnoringCase($localPart, $logged);
        }
    }

    // --- readiness() (#2480) ---------------------------------------------

    /**
     * @return array<string, array{SendinblueSettingsFakeDatabase, BrevoClientReadiness, string}>
     */
    public static function keyReadReadiness(): array
    {
        return [
            'table absent' => [
                new SendinblueSettingsFakeDatabase(sqlState: '42S02'),
                BrevoClientReadiness::NOT_CONFIGURED,
                LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED,
            ],
            'key not set' => [
                new SendinblueSettingsFakeDatabase(key: null),
                BrevoClientReadiness::NOT_CONFIGURED,
                LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED,
            ],
            'read failed' => [
                new SendinblueSettingsFakeDatabase(sqlState: 'HY000'),
                BrevoClientReadiness::FAILED,
                LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
            ],
        ];
    }

    /** The readiness case matches the log category the key read writes. */
    #[DataProvider('keyReadReadiness')]
    public function testReadinessFollowsTheKeyRead(
        SendinblueSettingsFakeDatabase $db,
        BrevoClientReadiness $expected,
        string $expectedCategory
    ): void {
        global $mockLogEntries;

        $this->assertSame($expected, (new BrevoSuppressionSyncClient($db))->readiness());

        $this->assertCount(1, $mockLogEntries);
        $this->assertSame($expectedCategory, $mockLogEntries[0]['category']);
    }

    /** A set key with no SDK means the plugin files are missing: a fault. */
    public function testReadinessIsFailedWhenTheKeyIsSetAndTheSdkDoesNotLoad(): void
    {
        $client = new NoSdkBrevoSuppressionSyncClient(new SendinblueSettingsFakeDatabase());

        $this->assertSame(BrevoClientReadiness::FAILED, $client->readiness());
    }

    /** READY says only that a poll can start. The poll itself can still fail. */
    public function testReadinessIsReadyWhenTheKeyIsSetAndTheApiObjectIsBuilt(): void
    {
        global $mockLogEntries;

        $client = new ThrowingBrevoSuppressionSyncClient(new SendinblueSettingsFakeDatabase());

        $this->assertSame(BrevoClientReadiness::READY, $client->readiness());
        $this->assertSame([], $mockLogEntries, 'A READY check writes no log row');
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function sdkLoadFailureProvider(): array
    {
        return [
            'no autoloader file' => [null, 'SDK autoloader not found'],
            'autoloader throws' => ["<?php throw new \\RuntimeException('broken');", 'failed to load'],
            'autoloader defines no API class' => ['<?php // empty', 'is still undefined'],
        ];
    }

    /**
     * With a key set, every SDK load fault is FAILED with one CronJobFailure
     * row. A missing autoloader is not "not configured": a key means Brevo
     * is meant to run here, so the plugin files are missing.
     *
     * Separate process: the autoloader path comes from globals, and a loaded
     * autoloader stays loaded for the rest of the process.
     */
    #[DataProvider('sdkLoadFailureProvider')]
    #[RunInSeparateProcess]
    public function testReadinessWithKeyAndSdkLoadFailureIsFailed(?string $autoloader, string $expectedMessage): void
    {
        global $mockLogEntries;

        $client = $this->clientWithSdkAutoloader($autoloader);

        $this->assertSame(BrevoClientReadiness::FAILED, $client->readiness());
        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $mockLogEntries[0]['category']);
        $this->assertStringContainsString($expectedMessage, $mockLogEntries[0]['message']);
    }

    // NOTE: no test passes a valid key through the real createApi() with a
    // real SDK. That reaches the real `new GuzzleHttp\Client()` and makes a
    // live HTTPS call to api.brevo.com. A unit test must not depend on the
    // network, so the poll catch is tested through the createApi() seam
    // (ThrowingBrevoSuppressionSyncClient), and the loadSdk() fault branches
    // through a scratch site root.
}
