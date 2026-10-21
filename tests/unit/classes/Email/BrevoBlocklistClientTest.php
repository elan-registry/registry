<?php

declare(strict_types=1);

use ElanRegistry\Email\BrevoApiFactory;
use ElanRegistry\Email\BrevoBlocklistClient;
use ElanRegistry\Email\BrevoUnblockResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\SendinblueSettingsFakeDatabase;
use Tests\Support\ThrowingBrevoBlocklistClient;

require_once __DIR__ . '/../../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../../Support/SendinblueSettingsFakeDatabase.php';
require_once __DIR__ . '/../../../Support/ThrowingBrevoBlocklistClient.php';

/**
 * Unit tests for BrevoBlocklistClient::unblock() (#2357).
 *
 * Two kinds of test:
 *
 * - The no-SDK tests use the protected `createApi()` seam. They need neither
 *   the SDK nor a network.
 * - The HTTP-status and path-encoding tests run the real SDK against a
 *   Guzzle mock handler, in a separate process so the SDK classes do not
 *   leak into other tests. They skip when the gitignored sendinblue plugin
 *   is not installed (CI), as the SDK is not there.
 */
#[Group('fast')]
final class BrevoBlocklistClientTest extends TestCase
{
    private const SDK_AUTOLOAD = __DIR__ . '/../../../../usersc/plugins/sendinblue/vendor/autoload.php';

    /** The fake site root of {@see self::clientWithSdkAutoloader()}, or null. */
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
     * Build an object from a class name that is a string, so PHPStan (no Guzzle) does not resolve it.
     *
     * @param class-string|string $class
     */
    private function make(string $class, mixed ...$args): object
    {
        return new $class(...$args);
    }

    private function requireSdk(): void
    {
        if (!file_exists(self::SDK_AUTOLOAD)) {
            $this->markTestSkipped('The sendinblue plugin SDK is not installed');
        }
        require_once self::SDK_AUTOLOAD;
    }

    /**
     * Client whose SDK answers come from a Guzzle mock queue.
     *
     * Guzzle classes are named by string: the PHPStan run has no Guzzle
     * (only the SDK stubs), and the tests that call this skip when the SDK
     * is absent.
     *
     * @param list<object>               $queue   Guzzle responses or exceptions, in order
     * @param list<array<string, mixed>> $history Filled with each sent request
     */
    private function clientWithQueue(array $queue, array &$history): BrevoBlocklistClient
    {
        $this->requireSdk();
        $mockHandler = $this->make('GuzzleHttp\Handler\MockHandler', $queue);
        $stack = call_user_func(['GuzzleHttp\HandlerStack', 'create'], $mockHandler);
        $this->assertIsObject($stack);
        $stack->push(call_user_func_array(['GuzzleHttp\Middleware', 'history'], [&$history]));

        return new class (new SendinblueSettingsFakeDatabase('test-api-key'), $stack) extends BrevoBlocklistClient {
            public function __construct(
                \ElanRegistry\DatabaseInterface $db,
                private readonly object $stack
            ) {
                parent::__construct($db);
            }

            protected function createApi(string $apiKey): object
            {
                $config = (new \Brevo\Client\Configuration())->setApiKey('api-key', $apiKey);

                return new \Brevo\Client\Api\TransactionalEmailsApi(
                    new \GuzzleHttp\Client(['handler' => $this->stack]),
                    $config
                );
            }
        };
    }

    /**
     * @param array<string, string> $headers
     */
    private function response(int $status, array $headers = [], string $body = ''): object
    {
        return $this->make('GuzzleHttp\Psr7\Response', $status, $headers, $body);
    }

    #[RunInSeparateProcess]
    public function test_unblock_status204_returnsRemoved(): void
    {
        $this->requireSdk();
        $history = [];
        $client = $this->clientWithQueue([$this->response(204)], $history);

        $result = $client->unblock('owner@example.org');

        $this->assertSame(BrevoUnblockResult::OUTCOME_REMOVED, $result->outcome());
        $this->assertTrue($result->isSuccess());
        $this->assertNull($result->reason());
        $this->assertCount(1, $history);
        $this->assertSame('DELETE', $history[0]['request']->getMethod());
    }

    #[RunInSeparateProcess]
    public function test_unblock_status404_returnsNotBlockedAsSuccess(): void
    {
        $this->requireSdk();
        $history = [];
        $client = $this->clientWithQueue(
            [$this->response(404, ['Content-Type' => 'application/json'], '{"code":"document_not_found","message":"x"}')],
            $history
        );

        $result = $client->unblock('owner@example.org');

        $this->assertSame(BrevoUnblockResult::OUTCOME_NOT_BLOCKED, $result->outcome());
        $this->assertTrue($result->isSuccess());
    }

    #[RunInSeparateProcess]
    public function test_unblock_status500_returnsFailedWithStatusOnly(): void
    {
        $this->requireSdk();
        $history = [];
        $client = $this->clientWithQueue([$this->response(500, [], 'boom owner@example.org')], $history);

        $result = $client->unblock('owner@example.org');

        $this->assertSame(BrevoUnblockResult::OUTCOME_FAILED, $result->outcome());
        $this->assertFalse($result->isSuccess());
        $this->assertSame(BrevoUnblockResult::REASON_HTTP, $result->reason());
        $this->assertSame(500, $result->httpStatus());
        $this->assertStringStartsWith('Brevo\Client\ApiException (code 500) at ', (string) $result->detail());
        $this->assertStringNotContainsString('owner@example.org', var_export($result, true));
    }

    #[RunInSeparateProcess]
    public function test_unblock_connectionFailure_returnsFailedTransportWithNoStatus(): void
    {
        $this->requireSdk();
        $history = [];
        $client = $this->clientWithQueue(
            [$this->make(
                'GuzzleHttp\\Exception\\ConnectException',
                'cURL error 7 for https://x/smtp/blockedContacts/owner@example.org',
                $this->make('GuzzleHttp\\Psr7\\Request', 'DELETE', 'https://x/smtp/blockedContacts/owner%40example.org')
            )],
            $history
        );

        $result = $client->unblock('owner@example.org');

        $this->assertSame(BrevoUnblockResult::REASON_TRANSPORT, $result->reason());
        $this->assertNull($result->httpStatus());
        $this->assertStringNotContainsString('owner@example.org', var_export($result, true));
    }

    /**
     * The SDK encodes the path value once. `+` and `@` must reach Brevo as
     * `%2B` and `%40`, not as `%252B` and `%2540`: a double-encoded address
     * gets a 404 from Brevo, which reads as "not blocked" and leaves the block.
     */
    #[RunInSeparateProcess]
    public function test_unblock_plusAndAtInAddress_areEncodedOnceInRequestPath(): void
    {
        $this->requireSdk();
        $history = [];
        $client = $this->clientWithQueue([$this->response(204)], $history);

        $client->unblock('a+b@example.org');

        $this->assertCount(1, $history);
        $path = $history[0]['request']->getUri()->getPath();
        $this->assertStringEndsWith('/smtp/blockedContacts/a%2Bb%40example.org', $path);
        $this->assertStringNotContainsString('%25', $path);
    }

    public function test_unblock_noApiKey_returnsFailedNoApiKeyWithNoCall(): void
    {
        $client = new RecordingSeamBrevoBlocklistClient(new SendinblueSettingsFakeDatabase(null));

        $result = $client->unblock('owner@example.org');

        $this->assertSame(BrevoUnblockResult::OUTCOME_FAILED, $result->outcome());
        $this->assertSame(BrevoUnblockResult::REASON_NO_API_KEY, $result->reason());
        $this->assertNull($result->httpStatus());
        $this->assertSame(BrevoApiFactory::CAUSE_KEY_NOT_SET, $result->detail());
        $this->assertSame(0, $client->createApiCalls, 'No key: the API object is not built');
    }

    public function test_unblock_apiKeyQueryFails_returnsFailedNoApiKey(): void
    {
        $client = new RecordingSeamBrevoBlocklistClient(new SendinblueSettingsFakeDatabase('k', '42S02'));

        $result = $client->unblock('owner@example.org');

        $this->assertSame(BrevoUnblockResult::REASON_NO_API_KEY, $result->reason());
        $this->assertSame(0, $client->createApiCalls);
    }

    public function test_unblock_apiKeyQueryFailsWithADatabaseError_returnsFailedDbError(): void
    {
        // Not 42S02 (table absent): a failed query is not a missing key.
        $client = new RecordingSeamBrevoBlocklistClient(new SendinblueSettingsFakeDatabase('k', 'HY000'));

        $result = $client->unblock('owner@example.org');

        $this->assertSame(BrevoUnblockResult::OUTCOME_FAILED, $result->outcome());
        $this->assertSame(BrevoUnblockResult::REASON_DB_ERROR, $result->reason());
        $this->assertSame(BrevoApiFactory::CAUSE_DB_ERROR, $result->detail());
        $this->assertSame(0, $client->createApiCalls);
    }

    public function test_unblock_apiKeyQueryThrows_returnsFailedAndKeepsNoMessage(): void
    {
        global $mockLogEntries;
        $client = new RecordingSeamBrevoBlocklistClient(new ThrowingQuerySendinblueDatabase());

        $result = $client->unblock('owner@example.org');

        $this->assertSame(BrevoUnblockResult::OUTCOME_FAILED, $result->outcome());
        $this->assertSame(BrevoUnblockResult::REASON_TRANSPORT, $result->reason());
        $this->assertNull($result->httpStatus());
        $this->assertSame(0, $client->createApiCalls);
        $this->assertStringNotContainsString('owner@example.org', print_r($result, true));
        $this->assertSame([], $mockLogEntries, 'The client logs nothing');
    }

    public function test_unblock_sdkNotLoaded_returnsFailedNoSdk(): void
    {
        $client = new RecordingSeamBrevoBlocklistClient(new SendinblueSettingsFakeDatabase('test-api-key'));
        $client->api = null;

        $result = $client->unblock('owner@example.org');

        $this->assertSame(BrevoUnblockResult::OUTCOME_FAILED, $result->outcome());
        $this->assertSame(BrevoUnblockResult::REASON_NO_SDK, $result->reason());
        $this->assertSame(1, $client->createApiCalls, 'The seam was asked for the API object');
    }

    /**
     * Point the real createApi() seam at a fake site root, so loadSdk() reads
     * the given autoloader. Null: no autoloader file.
     */
    private function clientWithSdkAutoloader(?string $autoloaderSource): BrevoBlocklistClient
    {
        global $abs_us_root, $us_url_root;
        $root = sys_get_temp_dir() . '/brevo-sdk-' . bin2hex(random_bytes(4)) . '/';
        $dir = $root . 'usersc/plugins/sendinblue/vendor';
        $this->assertTrue(mkdir($dir, 0777, true));
        $this->sdkRoot = $root;
        if ($autoloaderSource !== null) {
            file_put_contents($dir . '/autoload.php', $autoloaderSource);
        }
        $abs_us_root = $root;
        $us_url_root = '';

        return new BrevoBlocklistClient(new SendinblueSettingsFakeDatabase('test-api-key'));
    }

    /**
     * @return array<string, array{0: string|null, 1: string, 2: string}>
     */
    public static function sdkLoadProvider(): array
    {
        return [
            'no autoloader file (not installed)' => [null, BrevoUnblockResult::REASON_NO_SDK, BrevoApiFactory::CAUSE_AUTOLOADER_ABSENT],
            'autoloader throws' => [
                "<?php throw new \\RuntimeException('broken for owner@example.org');",
                BrevoUnblockResult::REASON_SDK_LOAD_FAILED,
                BrevoApiFactory::CAUSE_AUTOLOADER_FAILED,
            ],
            'autoloader defines no API class' => ['<?php // empty', BrevoUnblockResult::REASON_SDK_LOAD_FAILED, BrevoApiFactory::CAUSE_API_CLASS_MISSING],
        ];
    }

    /**
     * Only a missing autoloader means "not set up". A broken load is a fault
     * that a retry or a fix can solve, so it gets its own reason.
     */
    #[DataProvider('sdkLoadProvider')]
    #[RunInSeparateProcess]
    public function test_unblock_sdkLoadFailure_mapsTheCauseToTheReasonAndDetail(?string $autoloader, string $reason, string $cause): void
    {
        $client = $this->clientWithSdkAutoloader($autoloader);

        $result = $client->unblock('owner@example.org');

        $this->assertSame(BrevoUnblockResult::OUTCOME_FAILED, $result->outcome());
        $this->assertSame($reason, $result->reason());
        // An autoloader that threw adds its ExceptionLogSummary after the cause.
        $this->assertStringStartsWith($cause, (string) $result->detail());
        if ($cause === BrevoApiFactory::CAUSE_AUTOLOADER_FAILED) {
            $this->assertStringContainsString('RuntimeException', (string) $result->detail());
        }
        $this->assertStringNotContainsString('owner@example.org', var_export($result, true));
        $this->assertStringNotContainsString('broken for', var_export($result, true));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blankAddressProvider(): array
    {
        return [
            'empty'            => [''],
            'spaces'           => ['   '],
            'tab and newline'  => ["\t\n"],
        ];
    }

    #[DataProvider('blankAddressProvider')]
    public function test_unblock_blankAddress_returnsFailedNoAddressAndMakesNoCall(string $address): void
    {
        $client = new RecordingSeamBrevoBlocklistClient(new SendinblueSettingsFakeDatabase('test-api-key'));
        $client->api = new RecordingUnblockApi();

        $result = $client->unblock($address);

        $this->assertSame(BrevoUnblockResult::OUTCOME_FAILED, $result->outcome());
        $this->assertSame(BrevoUnblockResult::REASON_NO_ADDRESS, $result->reason());
        $this->assertNull($result->httpStatus());
        $this->assertSame(0, $client->createApiCalls, 'A blank address builds no API object');
        $this->assertSame([], $client->api->addresses, 'A blank address makes no Brevo call');
    }

    public function test_unblock_apiReceivesTheRawAddressUnchanged(): void
    {
        $client = new RecordingSeamBrevoBlocklistClient(new SendinblueSettingsFakeDatabase('test-api-key'));
        $client->api = new RecordingUnblockApi();

        $result = $client->unblock('a+b@example.org');

        $this->assertSame(BrevoUnblockResult::OUTCOME_REMOVED, $result->outcome());
        $this->assertSame(['a+b@example.org'], $client->api->addresses, 'The client does not encode: the SDK does');
        $this->assertSame('test-api-key', $client->lastApiKey);
    }

    public function test_unblock_exceptionMessageHoldingAnAddress_doesNotReachTheResultOrTheLog(): void
    {
        global $mockLogEntries;
        $client = new ThrowingBrevoBlocklistClient(new SendinblueSettingsFakeDatabase('test-api-key'));

        $result = $client->unblock('owner@example.org');

        $this->assertSame(BrevoUnblockResult::OUTCOME_FAILED, $result->outcome());
        $this->assertSame(BrevoUnblockResult::REASON_TRANSPORT, $result->reason());
        $this->assertNull($result->httpStatus(), 'The exception code is not an HTTP status');
        $this->assertStringStartsWith(
            sprintf('RuntimeException (code %d) at ', ThrowingBrevoBlocklistClient::EXCEPTION_CODE),
            (string) $result->detail(),
            'The detail is the log-safe summary'
        );
        $dump = var_export($result, true) . json_encode($mockLogEntries);
        $this->assertStringNotContainsString(ThrowingBrevoBlocklistClient::LEAKED_ADDRESS, $dump);
        $this->assertStringNotContainsString('owner@example.org', $dump);
        $this->assertStringNotContainsString('cURL error', $dump);
        $this->assertSame([], $mockLogEntries, 'The client logs nothing');
    }
}

/**
 * BrevoBlocklistClient with a scripted createApi() seam, for the no-SDK tests.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 */
final class RecordingSeamBrevoBlocklistClient extends BrevoBlocklistClient
{
    public ?object $api = null;
    public int $createApiCalls = 0;
    public ?string $lastApiKey = null;

    protected function createApi(string $apiKey): ?object
    {
        $this->createApiCalls++;
        $this->lastApiKey = $apiKey;

        return $this->api;
    }
}

/**
 * Settings database whose query throws with an address in the message.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 */
final class ThrowingQuerySendinblueDatabase extends SendinblueSettingsFakeDatabase
{
    public function query(string $sql, array $params = []): self
    {
        throw new \RuntimeException('connection lost while reading settings for owner@example.org');
    }
}

/**
 * Stand-in for the SDK API object that records the addresses it receives.
 */
final class RecordingUnblockApi
{
    /** @var list<string> */
    public array $addresses = [];

    public function smtpBlockedContactsEmailDelete(string $email): void
    {
        $this->addresses[] = $email;
    }
}
