<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Cron\SendVerificationBatchJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2364, #2361: the real Verification tab shows the "System Status & Feature
 * Switch" panel directly before the Automatic Sending card, in every health
 * state. A `?status=` value filters the queue only when it is on the fixed
 * list, and the page never prints any other value.
 *
 * Each request runs app/admin/index.php in its own `php -r` subprocess, as
 * AdminDeliverabilityActionsAuthTest does. The test reaches two real states:
 *
 * - Not healthy: the site-wide switch is off.
 * - Healthy: the switch is on, Brevo is ready (the key row in
 *   plg_sendinblue plus the plugin files on this checkout, see
 *   docs/development/ENVIRONMENT.md "Brevo Plugin Local Setup"), cron ran
 *   just now, the unmatched counter is 0, and no CronJobFailure row is in the
 *   lookback window.
 *
 * The test reads the "All healthy" and "Needs attention" badges, so a state
 * that was not reached fails the test. It does not skip.
 *
 * The test changes `er_verification_settings` (id = 1) and moves the
 * `logs` rows of type CronJobFailure out of the lookback window. tearDown
 * restores both. A CronJobFailure row that another process writes while a
 * healthy request runs makes the healthy case fail.
 *
 * Set VERIFICATION_TAB_RENDER_ADMIN_DIR to a copy of app/admin to render
 * another version of the tab. The directory must hold index.php.
 */
#[Group('integration')]
#[Group('admin')]
final class AdminVerificationTabRenderTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    private const PERMISSION_ADMIN = 2;

    private const PERMISSION_EDITOR = 3;

    /** Days that the healthy case moves CronJobFailure rows back. Greater than CronJobFailureLogReader::LOOKBACK_DAYS. */
    private const SHIFT_DAYS = 60;

    private int $adminId = 0;

    private int $editorId = 0;

    /** @var array{enabled: int, unmatched_recipient_count: int, last_cron_request_at: ?string}|null */
    private ?array $originalSettings = null;

    /** @var array<string, mixed>|null The send_verification_batch row of er_cron_job_runs, as found */
    private ?array $originalAutoSendRow = null;

    /** @var list<int> logs ids whose logdate this test moved back */
    private array $shiftedLogIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->shiftedLogIds = [];
        $this->originalSettings = null;
        $this->originalAutoSendRow = null;

        // securePage() treats `users.permissions = 0` as a banned account.
        $this->adminId = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($this->adminId, self::PERMISSION_ADMIN);

        $this->editorId = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($this->editorId, self::PERMISSION_EDITOR);

        $row = $this->db->query(
            'SELECT enabled, unmatched_recipient_count, last_cron_request_at FROM er_verification_settings WHERE id = 1'
        )->first();
        $this->assertIsObject($row, 'er_verification_settings must have its id = 1 row');
        $this->originalSettings = [
            'enabled'                   => (int) $row->enabled,
            'unmatched_recipient_count' => (int) $row->unmatched_recipient_count,
            'last_cron_request_at'      => $row->last_cron_request_at === null ? null : (string) $row->last_cron_request_at,
        ];
    }

    protected function tearDown(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            putenv($var);
        }
        if ($this->databaseConnected) {
            if ($this->originalSettings !== null) {
                $this->db->query(
                    'UPDATE er_verification_settings
                        SET enabled = ?, unmatched_recipient_count = ?, last_cron_request_at = ?
                      WHERE id = 1',
                    [
                        $this->originalSettings['enabled'],
                        $this->originalSettings['unmatched_recipient_count'],
                        $this->originalSettings['last_cron_request_at'],
                    ]
                );
            }
            if ($this->originalAutoSendRow !== null) {
                $columns = array_keys($this->originalAutoSendRow);
                $this->db->query('DELETE FROM er_cron_job_runs WHERE job_name = ?', [SendVerificationBatchJob::JOB_NAME]);
                $this->db->query(
                    'INSERT INTO er_cron_job_runs (' . implode(', ', $columns) . ') VALUES ('
                        . implode(', ', array_fill(0, count($columns), '?')) . ')',
                    array_values($this->originalAutoSendRow)
                );
            }
            foreach (array_chunk($this->shiftedLogIds, 200) as $ids) {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $this->db->query(
                    'UPDATE logs SET logdate = logdate + INTERVAL ' . self::SHIFT_DAYS . " DAY WHERE id IN ($marks)",
                    $ids
                );
            }
            $this->db->query('DELETE FROM logs WHERE user_id IN (?, ?)', [$this->adminId, $this->editorId]);
        }
        parent::tearDown();
    }

    private function seedNotHealthy(): void
    {
        $this->db->query(
            'UPDATE er_verification_settings SET enabled = 0, unmatched_recipient_count = 0 WHERE id = 1'
        );
        $this->assertFalse($this->db->error(), 'Test setup: switch off: ' . $this->db->errorString());
    }

    private function seedHealthy(): void
    {
        $this->saveBrevoTestKey();

        $this->db->query(
            'UPDATE er_verification_settings
                SET enabled = 1, unmatched_recipient_count = 0, last_cron_request_at = NOW()
              WHERE id = 1'
        );
        $this->assertFalse($this->db->error(), 'Test setup: healthy settings: ' . $this->db->errorString());

        // The failure count covers the last 7 days of the shared test schema.
        // Other tests leave rows there on purpose, so move the rows out of the window.
        $rows = $this->db->query(
            "SELECT id FROM logs WHERE logtype = 'CronJobFailure' AND logdate >= NOW() - INTERVAL 8 DAY"
        )->results();
        foreach ($rows as $row) {
            $this->shiftedLogIds[] = (int) $row->id;
        }
        foreach (array_chunk($this->shiftedLogIds, 200) as $ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $this->db->query(
                'UPDATE logs SET logdate = logdate - INTERVAL ' . self::SHIFT_DAYS . " DAY WHERE id IN ($marks)",
                $ids
            );
            $this->assertFalse($this->db->error(), 'Test setup: move failure rows: ' . $this->db->errorString());
        }
    }

    /**
     * GET the real Verification tab as the test admin and parse the page.
     *
     * @param string|null $status Raw value of `?status=`, or null for none
     * @param int|null $userId User who makes the request, or null for the test admin
     */
    private function getVerificationTab(?string $status, ?int $userId = null): DOMXPath
    {
        $projectRoot = dirname(__DIR__, 2);
        $adminDir = getenv('VERIFICATION_TAB_RENDER_ADMIN_DIR') ?: $projectRoot . '/app/admin';
        $page = $adminDir . '/index.php';
        $this->assertFileExists($page);

        foreach (self::DB_ENV_VARS as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if ($value !== false && $value !== '') {
                putenv("$var=$value");
            }
        }

        $query = ['tab' => 'verification'];
        if ($status !== null) {
            $query['status'] = $status;
        }

        $script = sprintf(
            'require %s; ' .
            '\Dotenv\Dotenv::createMutable(%s, ".env.test.local")->load(); ' .
            '$_SERVER["DOCUMENT_ROOT"] = %s; ' .
            '$_SERVER["PHP_SELF"] = "/app/admin/index.php"; ' .
            '$_SERVER["REQUEST_METHOD"] = "GET"; ' .
            'chdir(%s); ' .
            'require_once %s; ' .
            '$loggedInUser = new User(); $loggedInUser->find(%d); ' .
            '$reflection = new ReflectionClass($loggedInUser); ' .
            '$prop = $reflection->getProperty("_isLoggedIn"); $prop->setValue($loggedInUser, true); ' .
            'global $user; $user = $loggedInUser; $GLOBALS["user"] = $loggedInUser; ' .
            '$_GET = %s; $_REQUEST = $_GET; ' .
            'require %s;',
            var_export($projectRoot . '/vendor/autoload.php', true),
            var_export($projectRoot, true),
            var_export($projectRoot, true),
            var_export($adminDir, true),
            var_export($projectRoot . '/users/init.php', true),
            $userId ?? $this->adminId,
            var_export($query, true),
            var_export($page, true)
        );

        $raw = (string) shell_exec('php -r ' . escapeshellarg($script) . ' 2>&1; echo "___EXIT:$?"');
        [$output, $exitMarker] = array_pad(explode('___EXIT:', $raw), 2, '0');

        $this->assertSame(0, (int) trim($exitMarker), 'The page must run to the end: ' . substr($output, 0, 500));
        // The page JavaScript holds words such as "TypeError", so look for the PHP error line shape.
        $this->assertDoesNotMatchRegularExpression(
            '/(Fatal error|Parse error|Warning|Notice|Deprecated): .+ on line \d+/',
            $output,
            'The page must print no PHP error'
        );

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $output);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->assertTrue($loaded, 'The page must parse as HTML');

        $xpath = new DOMXPath($dom);
        $this->assertSame(
            1,
            $xpath->query('//*[@id="verificationQueue"]')->length,
            'The Verification tab must be on the page: ' . substr(strip_tags($output), 0, 300)
        );

        return $xpath;
    }

    private function onePanel(DOMXPath $xpath): DOMElement
    {
        $panels = $xpath->query('//*[@id="verificationStatusPanel"]');
        $this->assertSame(1, $panels->length, 'The tab renders the status panel exactly once');
        $panel = $panels->item(0);
        $this->assertInstanceOf(DOMElement::class, $panel);

        return $panel;
    }

    private function assertPanelDirectlyBeforeAutomaticSending(DOMXPath $xpath): void
    {
        $panel = $this->onePanel($xpath);

        $cards = $xpath->query(
            '//div[contains(concat(" ", normalize-space(@class), " "), " registry-card ")]'
            . '[./div[contains(@class, "card-header")]/h5[contains(normalize-space(.), "Automatic Sending")]]'
        );
        $this->assertSame(1, $cards->length, 'The tab renders the Automatic Sending card exactly once');

        // following-sibling::*[1] is the next ELEMENT. Comments and text do not count,
        // so no other section can sit between the two.
        $next = $xpath->query('following-sibling::*[1]', $panel)->item(0);
        $this->assertNotNull($next, 'The panel has a next element');
        $this->assertTrue(
            $next->isSameNode($cards->item(0)),
            'The element after the status panel must be the Automatic Sending card, not <' . $next->nodeName . '>'
        );
    }

    /** The data-status of the one active filter pill. */
    private function activePillStatus(DOMXPath $xpath): string
    {
        $active = $xpath->query(
            '//a[contains(concat(" ", normalize-space(@class), " "), " filter-pill ")]'
            . '[contains(concat(" ", normalize-space(@class), " "), " active ")]'
        );
        $this->assertSame(1, $active->length, 'Exactly one pill is active');
        $pill = $active->item(0);
        $this->assertInstanceOf(DOMElement::class, $pill);

        return $pill->getAttribute('data-status');
    }

    private function badgeText(DOMXPath $xpath): string
    {
        $badges = $xpath->query('//*[@id="verificationStatusPanel"]/summary/span[contains(@class, "badge")]');
        $this->assertSame(1, $badges->length, 'The panel header has one health badge');

        return trim((string) preg_replace('/\s+/', ' ', $badges->item(0)->textContent));
    }

    public function testNotHealthyPanelIsDirectlyBeforeAutomaticSending(): void
    {
        $this->seedNotHealthy();

        $xpath = $this->getVerificationTab(null);

        $this->assertSame('Needs attention', $this->badgeText($xpath), 'The switch is off, so the tab is not healthy');
        $this->assertTrue($this->onePanel($xpath)->hasAttribute('open'), 'The panel is open when the tab is not healthy');
        $this->assertPanelDirectlyBeforeAutomaticSending($xpath);
    }

    public function testHealthyPanelIsDirectlyBeforeAutomaticSending(): void
    {
        $this->seedHealthy();

        $xpath = $this->getVerificationTab(null);

        $rowBadges = [];
        foreach (['brevo', 'cron', 'unmatched', 'cron_failures'] as $key) {
            $node = $xpath->query('//*[@data-status-row="' . $key . '"]')->item(0);
            $rowBadges[$key] = $node === null ? '(missing)' : trim((string) preg_replace('/\s+/', ' ', $node->textContent));
        }
        $this->assertSame(
            'All healthy',
            $this->badgeText($xpath),
            'The seeded state must be healthy. Rows: ' . json_encode($rowBadges)
        );
        $this->assertFalse($this->onePanel($xpath)->hasAttribute('open'), 'The panel is closed when the tab is healthy');
        $this->assertPanelDirectlyBeforeAutomaticSending($xpath);
    }

    /** Save the auto-send job row once, so tearDown can put it back. */
    private function rememberAutoSendRow(): void
    {
        if ($this->originalAutoSendRow !== null) {
            return;
        }
        $row = $this->db->query('SELECT * FROM er_cron_job_runs WHERE job_name = ?', [SendVerificationBatchJob::JOB_NAME])->first();
        $this->assertIsObject($row, 'er_cron_job_runs must hold the send_verification_batch row');
        $this->originalAutoSendRow = (array) $row;
    }

    /**
     * Put the auto-send job in an enabled state. The last run is the earlier
     * of two moments; the counts (3 sent, 2 skipped, 1 failed) belong to it.
     *
     * @param bool $lastRunFailed True when the newest claimed run threw
     */
    private function seedAutoSendRun(bool $lastRunFailed): void
    {
        $this->rememberAutoSendRow();
        $this->db->query(
            'UPDATE er_cron_job_runs
                SET enabled = 1,
                    last_run_at = NOW() - INTERVAL 1 HOUR,
                    last_failure_at = ' . ($lastRunFailed ? 'NOW() - INTERVAL 1 HOUR' : 'NULL') . ',
                    last_sent_count = 3, last_skipped_count = 2, last_failed_count = 1
              WHERE job_name = ?',
            [SendVerificationBatchJob::JOB_NAME]
        );
        $this->assertFalse($this->db->error(), 'Test setup: auto-send run: ' . $this->db->errorString());
    }

    /** The "Last run results" cell of the Automatic Sending card, as one line of text. */
    private function lastRunResultsText(DOMXPath $xpath): string
    {
        $cells = $xpath->query(
            '//div[contains(concat(" ", normalize-space(@class), " "), " registry-card ")]'
            . '[./div[contains(@class, "card-header")]/h5[contains(normalize-space(.), "Automatic Sending")]]'
            . '//dt[normalize-space(.) = "Last run results"]/following-sibling::dd[1]'
        );
        $this->assertSame(1, $cells->length, 'The Automatic Sending card has one "Last run results" cell');

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $cells->item(0)->textContent)));
    }

    public function testLastRunFailedAfterEarlierSuccessShowsCountsWithEarlierRunNote(): void
    {
        $this->seedAutoSendRun(true);

        $text = $this->lastRunResultsText($this->getVerificationTab(null));

        $this->assertStringContainsString('3 sent, 2 skipped, 1 failed', $text);
        $this->assertStringContainsString('From an earlier successful run', $text);
        $this->assertStringNotContainsString('Counts unavailable', $text);
    }

    public function testLastRunSucceededShowsCountsWithoutEarlierRunNote(): void
    {
        $this->seedAutoSendRun(false);

        $text = $this->lastRunResultsText($this->getVerificationTab(null));

        $this->assertStringContainsString('3 sent, 2 skipped, 1 failed', $text);
        $this->assertStringNotContainsString('From an earlier successful run', $text);
    }

    public function testMissingAutoSendRowShowsCountsUnavailableInsteadOfCounts(): void
    {
        // The count columns are INT, so a missing row is the one stored state
        // that makes lastOutcomeCounts() report unreadable.
        $this->seedAutoSendRun(false);
        $this->db->query('DELETE FROM er_cron_job_runs WHERE job_name = ?', [SendVerificationBatchJob::JOB_NAME]);
        $this->assertFalse($this->db->error(), 'Test setup: delete row: ' . $this->db->errorString());

        $text = $this->lastRunResultsText($this->getVerificationTab(null));

        $this->assertStringContainsString('Counts unavailable', $text);
        $this->assertStringNotContainsString('sent,', $text);
        $this->assertStringNotContainsString('No automatic run yet', $text);
    }

    public function testEditorSeesTheTabWithAdminControlsRemoved(): void
    {
        $this->seedNotHealthy();

        $xpath = $this->getVerificationTab(null, $this->editorId);

        // The page must render in full for an editor.
        $this->assertSame(7, $xpath->query('//*[@data-summary]')->length, 'Seven summary cards');
        $this->assertSame(
            8,
            $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " filter-pill ")]')->length,
            'Eight filter pills'
        );
        $this->assertPanelDirectlyBeforeAutomaticSending($xpath);
        $this->assertSame('Needs attention', $this->badgeText($xpath));

        // The code shows the switch to an editor, but disabled.
        $switch = $xpath->query('//*[@id="verificationEnabledSwitch"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $switch, 'The editor sees the feature switch');
        $this->assertTrue($switch->hasAttribute('disabled'), 'The feature switch is disabled for an editor');

        // The code leaves these admin-only controls out of the page.
        $this->assertSame(0, $xpath->query('//*[@id="verificationBatchSize"]')->length, 'No batch size form');
        $this->assertSame(
            0,
            $xpath->query('//input[@name="command"][@value="verification_set_batch_size" or @value="verification_toggle_cron"]')->length,
            'No batch size or pause form'
        );
        $this->assertSame(
            0,
            $xpath->query('//a[contains(normalize-space(.), "Send Batch Now")]')->length,
            'No Send Batch Now link'
        );
        $this->assertStringContainsString(
            'Administrator access is required to pause or resume automatic sending.',
            $xpath->query('//body')->item(0)->textContent,
            'The editor sees why the pause control is missing'
        );
    }

    /** @return array<string, array{0: string}> */
    public static function filterPillProvider(): array
    {
        return [
            'blocked'   => ['blocked'],
            'opted_out' => ['opted_out'],
        ];
    }

    #[DataProvider('filterPillProvider')]
    public function testKnownStatusMakesItsPillTheActiveOne(string $status): void
    {
        $this->seedNotHealthy();

        $xpath = $this->getVerificationTab($status);

        $this->assertSame($status, $this->activePillStatus($xpath));

        $hidden = $xpath->query('//form[@id="verificationQueueControls"]/input[@name="status"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $hidden);
        $this->assertSame($status, $hidden->getAttribute('value'), 'The queue controls keep the chosen status');
    }

    public function testUnknownStatusShowsAllAndIsNeverPrinted(): void
    {
        $this->seedNotHealthy();
        $payload = '<script>alert(1)</script>';

        $xpath = $this->getVerificationTab($payload);

        $this->assertSame('all', $this->activePillStatus($xpath), 'An unknown status shows all rows');

        // The payload would show as a script element, as an escaped text node or as an attribute value.
        $this->assertSame(
            0,
            $xpath->query('//script[contains(., "alert(1)")]')->length,
            'The payload must not run as a script'
        );
        $this->assertSame(
            0,
            $xpath->query('//text()[contains(., "alert(1)")] | //@*[contains(., "alert(1)")] | //comment()[contains(., "alert(1)")]')->length,
            'The page must not print the unknown status in any form'
        );
    }
}
