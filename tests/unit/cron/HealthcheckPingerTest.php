<?php

declare(strict_types=1);

use ElanRegistry\Cron\HealthcheckPinger;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingHealthcheckTransport;

require_once __DIR__ . '/../../Support/RecordingHealthcheckTransport.php';

/**
 * Unit tests for HealthcheckPinger (#1875).
 *
 * The pinger must never throw on a transport fault, and must never put the
 * ping key or the hc-ping.com host in a log line. Every failure scenario
 * asserts both.
 */
#[Group('fast')]
final class HealthcheckPingerTest extends TestCase
{
    private const KEY = 'KEY123';
    private const SUFFIX = '-prod';
    private const SLUG = 'send-verification-batch';

    /** @var array<string, array{env: string|null, getenv: string|false}> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        foreach (['HEALTHCHECKS_PING_KEY', 'HEALTHCHECKS_SLUG_SUFFIX'] as $name) {
            $this->savedEnv[$name] = [
                'env' => $_ENV[$name] ?? null,
                'getenv' => getenv($name),
            ];
            unset($_ENV[$name]);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
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

    private function configure(string $key = self::KEY, string $suffix = self::SUFFIX): void
    {
        $_ENV['HEALTHCHECKS_PING_KEY'] = $key;
        $_ENV['HEALTHCHECKS_SLUG_SUFFIX'] = $suffix;
    }

    /**
     * @return list<array{category: string, message: string}>
     */
    private function logs(): array
    {
        global $mockLogEntries;

        return array_values($mockLogEntries ?? []);
    }

    /** Clears the log, then runs one pinger method against the transport. */
    private function pingWith(string $method, RecordingHealthcheckTransport $transport): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        (new HealthcheckPinger($transport))->$method(self::SLUG);
    }

    private function assertNoSecretInLogs(): void
    {
        $logs = $this->logs();
        $this->assertNotEmpty($logs, 'The scenario must have logged, or the secret check proves nothing');
        foreach ($logs as $entry) {
            $this->assertStringNotContainsString(self::KEY, $entry['message']);
            $this->assertStringNotContainsString('hc-ping.com', $entry['message']);
        }
    }

    // --- URL shape ------------------------------------------------------

    public function testSuccessRequestsTheLiteralSuccessUrl(): void
    {
        $this->configure();
        $transport = new RecordingHealthcheckTransport();

        (new HealthcheckPinger($transport))->success(self::SLUG);

        $this->assertSame(['https://hc-ping.com/KEY123/send-verification-batch-prod'], $transport->urls);
        $this->assertSame([], $this->logs(), 'A 200 response must not log');
    }

    public function testFailRequestsTheLiteralFailUrl(): void
    {
        $this->configure();
        $transport = new RecordingHealthcheckTransport();

        (new HealthcheckPinger($transport))->fail(self::SLUG);

        $this->assertSame(['https://hc-ping.com/KEY123/send-verification-batch-prod/fail'], $transport->urls);
        $this->assertSame([], $this->logs());
    }

    // --- Env sources ----------------------------------------------------

    public function testReadsKeyAndSuffixFromGetenvWhenEnvArrayIsEmpty(): void
    {
        putenv('HEALTHCHECKS_PING_KEY=GETENVKEY');
        putenv('HEALTHCHECKS_SLUG_SUFFIX=-test');
        $transport = new RecordingHealthcheckTransport();

        (new HealthcheckPinger($transport))->success('brevo-event-reconciliation');

        $this->assertSame(['https://hc-ping.com/GETENVKEY/brevo-event-reconciliation-test'], $transport->urls);
    }

    public function testReadsKeyAndSuffixFromEnvArrayWhenGetenvIsEmpty(): void
    {
        $this->configure('ENVKEY', '-test');
        $transport = new RecordingHealthcheckTransport();

        (new HealthcheckPinger($transport))->success(self::SLUG);

        $this->assertSame(['https://hc-ping.com/ENVKEY/send-verification-batch-test'], $transport->urls);
    }

    /**
     * `$_ENV` wins over `getenv()`, as in BrevoDevOverride. A swapped read
     * order would pass both single-source tests above.
     */
    public function testEnvArrayWinsWhenBothSourcesAreSet(): void
    {
        $this->configure('ENVKEY', '-prod');
        putenv('HEALTHCHECKS_PING_KEY=GETENVKEY');
        putenv('HEALTHCHECKS_SLUG_SUFFIX=-test');
        $transport = new RecordingHealthcheckTransport();

        (new HealthcheckPinger($transport))->success(self::SLUG);

        $this->assertSame(['https://hc-ping.com/ENVKEY/send-verification-batch-prod'], $transport->urls);
    }

    // --- Missing configuration -----------------------------------------

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function incompleteConfigProvider(): array
    {
        return [
            'key missing' => [null, '-prod'],
            'key empty' => ['', '-prod'],
            'suffix missing' => ['KEY123', null],
            'suffix empty' => ['KEY123', ''],
            'both missing' => [null, null],
        ];
    }

    #[DataProvider('incompleteConfigProvider')]
    public function testIncompleteConfigSendsNothingAndLogsOnce(?string $key, ?string $suffix): void
    {
        if ($key !== null) {
            $_ENV['HEALTHCHECKS_PING_KEY'] = $key;
        }
        if ($suffix !== null) {
            $_ENV['HEALTHCHECKS_SLUG_SUFFIX'] = $suffix;
        }

        foreach (['success', 'fail'] as $method) {
            $transport = new RecordingHealthcheckTransport();
            $this->pingWith($method, $transport);

            $this->assertSame([], $transport->urls, "{$method}() must send no request");
            $this->assertCount(1, $this->logs(), "{$method}() must log exactly once");
            $this->assertSame(LogCategories::LOG_CATEGORY_HEALTHCHECK_PING, $this->logs()[0]['category']);
            $this->assertStringContainsString('not sent', $this->logs()[0]['message']);
            $keyEmpty = $key === null || $key === '';
            $suffixEmpty = $suffix === null || $suffix === '';
            $message = $this->logs()[0]['message'];
            $keyEmpty
                ? $this->assertStringContainsString('HEALTHCHECKS_PING_KEY', $message)
                : $this->assertStringNotContainsString('HEALTHCHECKS_PING_KEY', $message);
            $suffixEmpty
                ? $this->assertStringContainsString('HEALTHCHECKS_SLUG_SUFFIX', $message)
                : $this->assertStringNotContainsString('HEALTHCHECKS_SLUG_SUFFIX', $message);
            $this->assertNoSecretInLogs();
        }
    }

    // --- Transport faults ----------------------------------------------

    public function testTransportThrowLogsOnceWithoutKeyOrHostForBothMethods(): void
    {
        $this->configure();

        foreach (['success', 'fail'] as $method) {
            $transport = new RecordingHealthcheckTransport();
            // A message that holds the URL, as a careless transport could produce.
            $transport->throw = new RuntimeException('cURL failed for https://hc-ping.com/KEY123/x');
            $this->pingWith($method, $transport);

            $this->assertCount(1, $transport->urls, "{$method}() must have tried once");
            $this->assertCount(1, $this->logs(), "{$method}() must log exactly once");
            $this->assertSame(LogCategories::LOG_CATEGORY_HEALTHCHECK_PING, $this->logs()[0]['category']);
            $this->assertStringContainsString('RuntimeException', $this->logs()[0]['message']);
            $this->assertNoSecretInLogs();
        }
    }

    /**
     * @return array<int, array{0: int}>
     */
    public static function nonSuccessStatusProvider(): array
    {
        return [[199], [301], [400], [404], [500]];
    }

    #[DataProvider('nonSuccessStatusProvider')]
    public function testNonSuccessStatusLogsOnceWithoutKeyOrHost(int $status): void
    {
        $this->configure();

        foreach (['success', 'fail'] as $method) {
            $transport = new RecordingHealthcheckTransport();
            $transport->status = $status;
            $this->pingWith($method, $transport);

            $this->assertCount(1, $this->logs(), "{$method}() must log exactly once for HTTP {$status}");
            $this->assertSame(LogCategories::LOG_CATEGORY_HEALTHCHECK_PING, $this->logs()[0]['category']);
            $this->assertStringContainsString("HTTP {$status}", $this->logs()[0]['message']);
            $this->assertNoSecretInLogs();
        }
    }

    /**
     * @return array<int, array{0: int}>
     */
    public static function successStatusProvider(): array
    {
        return [[200], [201], [204]];
    }

    #[DataProvider('successStatusProvider')]
    public function testSuccessStatusDoesNotLog(int $status): void
    {
        $this->configure();

        foreach (['success', 'fail'] as $method) {
            $transport = new RecordingHealthcheckTransport();
            $transport->status = $status;
            $this->pingWith($method, $transport);

            $this->assertCount(1, $transport->urls);
            $this->assertSame([], $this->logs(), "A 2xx response to {$method}() is not a fault");
        }
    }
}
