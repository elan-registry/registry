<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * Behavioral (real-process, real-DB) tests for app/api/webhooks/brevo.php —
 * the gate-only placeholder webhook receiver (#1926).
 *
 * Complements BrevoWebhookStubEndpointTest's source-text assertions (unit
 * tier) with the actual runtime behavior the plan's Test Plan calls for:
 * exit code / HTTP status behavior, the `er_verification_settings` state
 * actually driving the branch taken, and a real row landing in `logs` for
 * the enabled-but-misconfigured case. Uses only synthetic invocation — no
 * real Brevo payload is parsed by this stub at all (that is #1887's job),
 * so there is no payload shape to construct; the "hit" here is simply a
 * bare POST to the endpoint.
 *
 * Invoked in a subprocess (php -r ...), following the same pattern as
 * ErrorPageHeadersTest::renderErrorPage(): brevo.php requires the full
 * users/init.php bootstrap and unconditionally calls
 * http_response_code()/exit, which would terminate the PHPUnit process if
 * required in-process.
 */
#[Group('integration')]
final class BrevoWebhookStubTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            putenv($var);
        }

        // Restore the switch to its safe default so this test never leaks
        // an "enabled" state into whichever test runs next.
        if ($this->databaseConnected) {
            $this->db->query('UPDATE er_verification_settings SET enabled = 0 WHERE id = 1');

            // The throttle test's drop-notice rows would otherwise persist and
            // suppress the "first hit within the hour" expectation on the next
            // run of this test within the same hour.
            $this->db->query(
                'DELETE FROM logs WHERE logtype = ? AND lognote = ?',
                [
                    LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
                    'Brevo webhook events are being ACCEPTED AND DISCARDED — the receiver is a '
                        . 'placeholder until #1887 lands. Bounces/unsubscribes are not being recorded.',
                ]
            );
        }

        parent::tearDown();
    }

    private function exposeTestDatabaseToSubprocess(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if ($value !== false && $value !== '') {
                putenv("$var=$value");
            }
        }
    }

    private function setSwitchEnabled(bool $enabled): void
    {
        $this->db->query(
            'UPDATE er_verification_settings SET enabled = ? WHERE id = 1',
            [$enabled ? 1 : 0]
        );
        $this->assertFalse($this->db->error(), 'Failed to set up er_verification_settings.enabled for test');
    }

    /**
     * @return array{output: string, exitCode: int}
     */
    private function invokeWebhookStub(): array
    {
        $endpointFile = dirname(__DIR__, 2) . '/app/api/webhooks/brevo.php';
        $projectRoot = dirname(__DIR__, 2);

        $this->exposeTestDatabaseToSubprocess();

        $script = sprintf(
            'require %s; \Dotenv\Dotenv::createMutable(%s, ".env.test.local")->load(); ' .
            '$_SERVER["DOCUMENT_ROOT"] = %s; $_SERVER["PHP_SELF"] = "/app/api/webhooks/brevo.php"; ' .
            '$_SERVER["REQUEST_METHOD"] = "POST"; chdir(%s); require %s;',
            var_export($projectRoot . '/vendor/autoload.php', true),
            var_export($projectRoot, true),
            var_export($projectRoot, true),
            var_export(dirname($endpointFile), true),
            var_export($endpointFile, true)
        );

        $output = shell_exec('php -r ' . escapeshellarg($script) . ' 2>&1; echo "___EXIT:$?"');
        $output = $output !== null ? $output : '';

        [$body, $exitMarker] = array_pad(explode('___EXIT:', $output), 2, '0');

        return ['output' => trim($body), 'exitCode' => (int) trim($exitMarker)];
    }

    public function testSwitchOffRespondsSuccessfullyWithEmptyBodyAndNoLogging(): void
    {
        $this->setSwitchEnabled(false);

        $before = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
            '%Webhook received%'
        );

        $result = $this->invokeWebhookStub();

        $this->assertSame(0, $result['exitCode'], 'The subprocess must exit cleanly (no fatal error): ' . $result['output']);
        $this->assertSame('', $result['output'], 'Switch-off must produce no output body and no warnings/errors');

        $after = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
            '%Webhook received%'
        );

        $this->assertSame($before, $after, 'Switch-off must not write any VerificationConfigWarning log row');
    }

    /**
     * Switch on but Brevo not configured (the plugin's plg_sendinblue key is
     * empty / override.php absent in this test environment by construction
     * — no Brevo credentials are ever installed for the integration test
     * schema): the stub must still respond 2xx (no fatal, clean exit) and
     * log exactly one refusal.
     */
    public function testSwitchOnWithBrevoNotReadyLogsRefusalAndStillSucceeds(): void
    {
        $this->setSwitchEnabled(true);

        $before = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
            '%Webhook received but Brevo prerequisites are not met%'
        );

        $result = $this->invokeWebhookStub();

        $this->assertSame(0, $result['exitCode'], 'The subprocess must exit cleanly (no fatal error): ' . $result['output']);
        $this->assertSame('', $result['output'], 'The stub must never emit a response body');

        $after = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
            '%Webhook received but Brevo prerequisites are not met%'
        );

        $this->assertSame(
            $before + 1,
            $after,
            'Enabled-but-not-ready must write exactly one VerificationConfigWarning refusal row'
        );
    }

    /**
     * Enabled AND Brevo-ready: the "enabled and ready" branch now writes a
     * rate-limited placeholder-drop notice (previously a pure no-op). Calling
     * the webhook twice within the same hour must write only ONE new log row,
     * not two — proving the throttle (a lookback SELECT for an existing
     * matching notice within the last hour) actually works rather than
     * logging unconditionally on every hit.
     */
    public function testEnabledAndReadyLogsThrottledDropNoticeOnlyOncePerHour(): void
    {
        $this->setSwitchEnabled(true);
        $this->makeBrevoReady();

        try {
            $dropNotice = 'Brevo webhook events are being ACCEPTED AND DISCARDED — the receiver is a '
                . 'placeholder until #1887 lands. Bounces/unsubscribes are not being recorded.';

            $before = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, $dropNotice);

            $first = $this->invokeWebhookStub();
            $this->assertSame(0, $first['exitCode'], 'First hit must exit cleanly: ' . $first['output']);
            $this->assertSame('', $first['output']);

            $afterFirst = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, $dropNotice);
            $this->assertSame($before + 1, $afterFirst, 'The first hit within the hour must write exactly one drop-notice row');

            $second = $this->invokeWebhookStub();
            $this->assertSame(0, $second['exitCode'], 'Second hit must exit cleanly: ' . $second['output']);
            $this->assertSame('', $second['output']);

            $afterSecond = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, $dropNotice);
            $this->assertSame(
                $afterFirst,
                $afterSecond,
                'A second hit within the same hour must NOT write a second drop-notice row (throttle must hold)'
            );
        } finally {
            $this->cleanUpBrevoReadyFixture();
        }
    }

    /** @var object|array<string, mixed>|null Snapshot of the existing plg_sendinblue row (if any), for restoration. */
    private object|array|null $existingBrevoRow = null;

    /** Whether makeBrevoReady() itself inserted the plg_sendinblue row (vs. reusing an existing one). */
    private bool $brevoRowInsertedByTest = false;

    /** Whether makeBrevoReady() itself created the override.php file. */
    private bool $brevoOverrideCreatedByTest = false;

    /**
     * Makes VerificationSettings::brevoReady() report true for the duration
     * of a test by ensuring a plg_sendinblue row with a non-empty key exists
     * and the real override.php file is in place — mirroring
     * VerificationToggleEndpointBehaviorTest::testAdminCanEnableWhenBrevoIsReady()'s
     * approach, since brevo.php's subprocess reads the real filesystem/DB, not
     * a double. Call cleanUpBrevoReadyFixture() in a finally block afterward.
     */
    private function makeBrevoReady(): void
    {
        $overridePath = dirname(__DIR__, 2) . '/usersc/plugins/sendinblue/override.php';
        $this->brevoOverrideCreatedByTest = !file_exists($overridePath);
        if ($this->brevoOverrideCreatedByTest) {
            file_put_contents($overridePath, "<?php\n");
        }

        $this->existingBrevoRow = $this->db->query('SELECT id, `key` FROM plg_sendinblue LIMIT 1')->first();

        if (is_object($this->existingBrevoRow) && isset($this->existingBrevoRow->id)) {
            $this->db->query('UPDATE plg_sendinblue SET `key` = ? WHERE id = ?', ['sib-test-key', $this->existingBrevoRow->id]);
            $this->brevoRowInsertedByTest = false;
        } else {
            $this->db->insert('plg_sendinblue', ['key' => 'sib-test-key']);
            $this->brevoRowInsertedByTest = true;
        }
    }

    private function cleanUpBrevoReadyFixture(): void
    {
        $overridePath = dirname(__DIR__, 2) . '/usersc/plugins/sendinblue/override.php';

        if ($this->brevoOverrideCreatedByTest && file_exists($overridePath)) {
            unlink($overridePath);
        }

        if ($this->brevoRowInsertedByTest) {
            $this->db->query('DELETE FROM plg_sendinblue WHERE `key` = ?', ['sib-test-key']);
        } elseif (is_object($this->existingBrevoRow) && isset($this->existingBrevoRow->id)) {
            $this->db->query('UPDATE plg_sendinblue SET `key` = ? WHERE id = ?', [$this->existingBrevoRow->key, $this->existingBrevoRow->id]);
        }
    }
}
