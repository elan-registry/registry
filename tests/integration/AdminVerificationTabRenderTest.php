<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Cron\SendVerificationBatchJob;
use ElanRegistry\LogCategories;
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

    /** Stored batch_size, as found; null until setUp reads it. */
    private ?int $originalBatchSize = null;

    /** @var array<string, mixed>|null The whole id = 1 row, saved by a test that deletes it */
    private ?array $deletedSettingsRow = null;

    /** @var array<string, mixed>|null The send_verification_batch row of er_cron_job_runs, as found */
    private ?array $originalAutoSendRow = null;

    /** @var list<int> logs ids whose logdate this test moved back */
    private array $shiftedLogIds = [];

    /** @var list<int> Cars that createFirstInLineEligibleCar() made in this test */
    private array $firstInLineCarIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->shiftedLogIds = [];
        $this->firstInLineCarIds = [];
        $this->originalSettings = null;
        $this->originalAutoSendRow = null;
        $this->deletedSettingsRow = null;

        // securePage() treats `users.permissions = 0` as a banned account.
        $this->adminId = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($this->adminId, self::PERMISSION_ADMIN);

        $this->editorId = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($this->editorId, self::PERMISSION_EDITOR);

        $this->originalBatchSize = null;
        $row = $this->db->query(
            'SELECT enabled, unmatched_recipient_count, last_cron_request_at, batch_size FROM er_verification_settings WHERE id = 1'
        )->first();
        $this->assertIsObject($row, 'er_verification_settings must have its id = 1 row');
        $this->originalBatchSize = (int) $row->batch_size;
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
            // First, so the UPDATE blocks below find the row again.
            if ($this->deletedSettingsRow !== null) {
                $columns = array_keys($this->deletedSettingsRow);
                $this->db->query(
                    'INSERT INTO er_verification_settings (' . implode(', ', $columns) . ') VALUES ('
                        . implode(', ', array_fill(0, count($columns), '?')) . ')',
                    array_values($this->deletedSettingsRow)
                );
                if ($this->db->error()) {
                    // An assert here would hide the real test failure.
                    fwrite(STDERR, 'tearDown: settings row restore failed: ' . $this->db->errorString() . "\n");
                }
                $this->db->query(
                    "DELETE FROM logs WHERE user_id = 0 AND logtype = 'VerificationConfigWarning'"
                    . " AND lognote LIKE 'er_verification_settings%'"
                    . " AND logdate >= NOW() - INTERVAL 10 MINUTE"
                );
            }
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
            if ($this->originalBatchSize !== null) {
                $this->db->query(
                    'UPDATE er_verification_settings SET batch_size = ? WHERE id = 1',
                    [$this->originalBatchSize]
                );
                if ($this->db->error()) {
                    // An assert here would hide the real test failure.
                    fwrite(STDERR, 'tearDown: batch_size restore failed: ' . $this->db->errorString() . "\n");
                }
            }
            // The 255 render logs a user_id 0 warning that the user_id delete below misses.
            $this->db->query(
                "DELETE FROM logs WHERE user_id = 0 AND logtype = 'VerificationConfigWarning' AND lognote LIKE 'er_verification_settings.batch_size holds 255,%'"
            );
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
     * @param array<string, string> $extraQuery More query values, such as `show`
     * @param list<array<string, mixed>>|null $stubQueueRows When set, the request includes the tab file
     *        directly with a repository whose findVerificationQueue() returns these rows as objects
     */
    private function getVerificationTab(
        ?string $status,
        ?int $userId = null,
        array $extraQuery = [],
        ?array $stubQueueRows = null
    ): DOMXPath {
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
        $query += $extraQuery;

        if ($stubQueueRows === null) {
            $tail = 'require ' . var_export($page, true) . ';';
        } else {
            // app/admin/index.php builds its own repository, so a stub needs the tab file itself.
            $tail = 'class StubQueueRepository extends \\ElanRegistry\\Car\\CarRepository { '
                . 'public function findVerificationQueue(string $status, ?int $windowDays, int $limit): array { '
                . 'return array_map(static fn (array $r): object => (object) $r, ' . var_export($stubQueueRows, true) . '); } } '
                . '$verificationRepo = new StubQueueRepository(dbi()); $queueStatus = "pending"; '
                . 'require ' . var_export($adminDir . '/includes/tab-verification.php', true) . ';';
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
            '%s',
            var_export($projectRoot . '/vendor/autoload.php', true),
            var_export($projectRoot, true),
            var_export($projectRoot, true),
            var_export($adminDir, true),
            var_export($projectRoot . '/users/init.php', true),
            $userId ?? $this->adminId,
            var_export($query, true),
            $tail
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

    /**
     * #2363: a batch_size written straight to the database, past the
     * setBatchSize() clamp, must show as 25 in the admin field.
     */
    public function testBatchSizeFieldShowsTheClampedValueWhenTheStoredValueIsTooLarge(): void
    {
        $this->db->query('UPDATE er_verification_settings SET batch_size = 255 WHERE id = 1');
        $this->assertFalse($this->db->error(), 'Test setup: batch_size 255: ' . $this->db->errorString());

        $xpath = $this->getVerificationTab(null);

        $input = $xpath->query('//input[@id="verificationBatchSize"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $input, 'The admin sees the batch size field');
        $this->assertSame('25', $input->getAttribute('value'));

        // Uses the tearDown DELETE's filter, so a reworded message fails here
        // and does not leak rows without notice.
        $this->assertSame(
            1,
            $this->countMatchingLogs(
                LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
                'er_verification_settings.batch_size holds 255,%',
                0
            ),
            'One render must log exactly one batch_size warning'
        );
    }

    /**
     * #2572: with no id = 1 row, the tab must not present the code default as
     * the admin's setting. A Save form would let the admin store a number
     * that was never read, and "no cron request logged" would blame the cron.
     */
    public function testMissingSettingsRowShowsBatchSizeUnavailableAndNoSaveForm(): void
    {
        $row = $this->db->query('SELECT * FROM er_verification_settings WHERE id = 1')->first();
        $this->assertIsObject($row, 'Test setup: the id = 1 row exists before the delete');
        $this->deletedSettingsRow = (array) $row;

        $this->db->query('DELETE FROM er_verification_settings WHERE id = 1');
        $this->assertFalse($this->db->error(), 'Test setup: delete row: ' . $this->db->errorString());
        $gone = $this->db->query('SELECT id FROM er_verification_settings WHERE id = 1');
        $this->assertSame(0, $gone->count(), 'Test setup: the row is gone');

        $xpath = $this->getVerificationTab(null);

        $this->assertSame(0, $xpath->query('//*[@id="verificationBatchSize"]')->length, 'No batch size input');
        $batchSizeField = $xpath->query('//dt[normalize-space()="Batch size"]/following-sibling::dd[1]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $batchSizeField, 'The Batch size row renders');
        $this->assertSame(
            'Unavailable The batch size could not be read. Check the system log for VerificationConfigWarning entries.',
            trim((string) preg_replace('/\s+/', ' ', $batchSizeField->textContent)),
            'The Batch size field shows Unavailable and names the log to check'
        );
        $this->assertSame(
            0,
            $xpath->query('//input[@name="command"][@value="verification_set_batch_size"]')->length,
            'No batch size Save form'
        );
        $this->assertSame(
            1,
            $xpath->query('//*[@id="verificationSwitchUnavailable"]')->length,
            'The switch shows as unavailable'
        );
        $this->assertStringNotContainsString(
            'no cron request has ever been logged',
            strtolower((string) $xpath->query('//body')->item(0)->textContent),
            'A missing row is not the same as a cron that never ran'
        );
    }

    public function testEditorSeesTheTabWithAdminControlsRemoved(): void
    {
        $this->seedNotHealthy();

        $xpath = $this->getVerificationTab(null, $this->editorId);

        // The page must render in full for an editor.
        $this->assertSame(7, $xpath->query('//*[@data-summary]')->length, 'Seven summary cards');
        $this->assertSame(
            9,
            $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " filter-pill ")]')->length,
            'Nine filter pills'
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

    /** @return array<string, array{0: bool}> */
    public static function queueViewerProvider(): array
    {
        return [
            'admin'  => [false],
            'editor' => [true],
        ];
    }

    /**
     * #2498: each queue row shows the owner's user ID, linked to the user's
     * admin page. Two cars of one owner show the same ID.
     */
    #[DataProvider('queueViewerProvider')]
    public function testQueueShowsTheOwnerUserIdWithALinkToTheUserPage(bool $asEditor): void
    {
        $this->seedNotHealthy();

        $ownerId = $this->createTestUser([], true);
        // Pending lists the longest-waiting car first, so a send time near the
        // end of the link lifetime puts these two cars in the first rows.
        $sentAt = date('Y-m-d H:i:s', time() - 59 * 86400);
        $carIds = [];
        for ($i = 0; $i < 2; $i++) {
            $carIds[] = $this->createTestCar($ownerId, [
                'vericode'         => bin2hex(random_bytes(16)),
                'vericode_sent_at' => $sentAt,
            ]);
        }
        // cars.user_id is nullable with no foreign key, so a row can have no owner.
        $noOwnerCarId = $this->createTestCar($ownerId, [
            'vericode'         => bin2hex(random_bytes(16)),
            'vericode_sent_at' => $sentAt,
        ]);
        $this->db->query('UPDATE cars SET user_id = NULL WHERE id = ?', [$noOwnerCarId]);
        $this->assertFalse($this->db->error(), 'Test setup: clear user_id: ' . $this->db->errorString());

        $xpath = $this->getVerificationTab('pending', $asEditor ? $this->editorId : null);

        $headers = [];
        foreach ($xpath->query('//*[@id="verificationQueue"]//table/thead/tr/th') as $th) {
            $headers[] = trim($th->textContent);
        }
        $this->assertSame(
            ['Car', 'Chassis', 'Owner', 'User ID', 'Sent', 'Days', 'Status', 'Actions'],
            $headers,
            'User ID is the column directly after Owner'
        );

        foreach ($carIds as $carId) {
            $rows = $xpath->query(sprintf(
                '//*[@id="verificationQueue"]//table/tbody/tr[normalize-space(td[1]) = "%d"]',
                $carId
            ));
            $this->assertSame(1, $rows->length, "Car {$carId} has one queue row");

            $cell = $xpath->query('td[4]', $rows->item(0))->item(0);
            $this->assertInstanceOf(DOMElement::class, $cell);
            $links = $xpath->query('a', $cell);
            $this->assertSame(1, $links->length, "The User ID cell of car {$carId} holds one link");

            $link = $links->item(0);
            $this->assertInstanceOf(DOMElement::class, $link);
            $this->assertSame((string) $ownerId, trim($link->textContent), "Car {$carId} shows its owner's user ID");
            $this->assertSame('/users/admin.php?view=user&id=' . $ownerId, $link->getAttribute('href'));
            $this->assertSame('_blank', $link->getAttribute('target'));
            $this->assertSame('noopener', $link->getAttribute('rel'));
        }

        $noOwnerCell = $xpath->query(sprintf(
            '//*[@id="verificationQueue"]//table/tbody/tr[normalize-space(td[1]) = "%d"]/td[4]',
            $noOwnerCarId
        ))->item(0);
        $this->assertInstanceOf(DOMElement::class, $noOwnerCell, "Car {$noOwnerCarId} with no owner has a queue row");
        $this->assertSame(0, $xpath->query('a', $noOwnerCell)->length, 'A row with no owner has no user link');
        $this->assertSame("\u{2014}", trim($noOwnerCell->textContent), 'A row with no owner shows a dash');
    }

    /** @return array<string, array{0: string}> */
    public static function filterPillProvider(): array
    {
        return [
            'blocked'       => ['blocked'],
            'opted_out'     => ['opted_out'],
            'next_queue'    => ['next_queue'],
            'invalid_email' => ['invalid_email'],
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

    public function testUnknownStatusShowsEligibleAndIsNeverPrinted(): void
    {
        $this->seedNotHealthy();
        $payload = '<script>alert(1)</script>';

        $xpath = $this->getVerificationTab($payload);

        $this->assertSame('eligible', $this->activePillStatus($xpath), 'An unknown status shows Eligible');

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

    // --- #2599: pills, Next Queue, links -----------------------------------------------

    /** @return list<string> The data-status of each filter pill, in page order. */
    private function pillStatuses(DOMXPath $xpath): array
    {
        $statuses = [];
        foreach ($xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " filter-pill ")]') as $pill) {
            $this->assertInstanceOf(DOMElement::class, $pill);
            $statuses[] = $pill->getAttribute('data-status');
        }

        return $statuses;
    }

    /** The "(n)" count of a pill as an int, or null when the pill shows no count. */
    private function pillCount(DOMXPath $xpath, string $status): ?int
    {
        $pills = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " filter-pill ")][@data-status="' . $status . '"]');
        $this->assertSame(1, $pills->length, "One {$status} pill");
        $spans = $xpath->query('span', $pills->item(0));
        if ($spans->length === 0) {
            return null;
        }
        $this->assertSame(1, preg_match('/^\((\d[\d,]*)\)$/', trim($spans->item(0)->textContent), $m), 'Pill count text');

        return (int) str_replace(',', '', $m[1]);
    }

    private function sendBatchFormCount(DOMXPath $xpath): int
    {
        return $xpath->query('//form[.//input[@name="command"][@value="verification_send_batch"]]')->length;
    }

    /**
     * Create an eligible car with a fixed last_verified (default 1988-01-01)
     *
     * The send order puts NULL first, so the date alone does not put the car
     * first. A setup assertion fails the test when an eligible car that this
     * helper did not create has last_verified NULL or on or before the date of
     * this call. Thus the cars from this helper sort ahead of all other
     * eligible cars. Among themselves they sort by date, then by car id. The
     * setup check of the call with the latest date covers all helper cars, in
     * any call order.
     */
    private function createFirstInLineEligibleCar(
        int $ownerId,
        string $label,
        string $lastVerified = '1988-01-01 00:00:00'
    ): int {
        $stale = date('Y-m-d H:i:s', strtotime('-3 years'));

        $carId = $this->createTestCar($ownerId, [
            'email'              => $label . '-' . uniqid() . '@example.com',
            'last_verified'      => $lastVerified,
            'owner_last_updated' => $stale,
            'mtime'              => $stale,
            'solddate'           => null,
            'vericode'           => null,
            'vericode_sent_at'   => null,
        ]);
        $this->firstInLineCarIds[] = $carId;

        $ahead = array_filter(
            (new CarRepository($this->db))->findVerificationEligible(1000, 0),
            fn ($row) => !in_array((int) $row->id, $this->firstInLineCarIds, true)
                && ($row->last_verified === null || $row->last_verified <= $lastVerified)
        );
        $this->assertSame([], array_values($ahead), 'Setup: no other eligible car sorts ahead of the first-in-line cars');

        return $carId;
    }

    public function testPillsAreTheNineQueueViewsInOrderWithNoAllPill(): void
    {
        $this->seedNotHealthy();

        $statuses = $this->pillStatuses($this->getVerificationTab(null));

        $this->assertSame(
            ['eligible', 'next_queue', 'pending', 'bounced', 'blocked', 'opted_out', 'verified', 'sold', 'invalid_email'],
            $statuses
        );
    }

    public function testDefaultAndRemovedAllStatusShowEligibleWithNoSendForm(): void
    {
        $this->seedNotHealthy();
        $ownerId = $this->createTestUser([], true);
        $this->createFirstInLineEligibleCar($ownerId, 'default-view');

        foreach ([null, 'all'] as $status) {
            $xpath = $this->getVerificationTab($status);

            $label = $status ?? 'no status';
            $this->assertSame('eligible', $this->activePillStatus($xpath), "{$label}: Eligible is active");
            $this->assertSame(0, $this->sendBatchFormCount($xpath), "{$label}: no send form on Eligible");
            $this->assertSame(0, $xpath->query('//input[@name="car_ids[]"]')->length, "{$label}: no car_ids to send");
            $this->assertSame(0, $xpath->query('//*[contains(@class, "alert-danger")][contains(., "queue could not be loaded")]')->length);
        }
    }

    public function testSendBatchNowLinkOpensTheNextQueueView(): void
    {
        $this->seedNotHealthy();

        $xpath = $this->getVerificationTab(null);
        $links = $xpath->query('//a[contains(normalize-space(.), "Send Batch Now")]');

        $this->assertSame(1, $links->length, 'The admin sees one Send Batch Now link');
        $link = $links->item(0);
        $this->assertInstanceOf(\DOMElement::class, $link);
        $href = $link->getAttribute('href');
        // Only Next Queue has the send form, so the link must open that view.
        $this->assertStringContainsString('status=next_queue', $href);
        $this->assertStringEndsWith('#verificationQueue', $href);
    }

    public function testNextQueueRendersThePreviewAndASendFormForNextQueue(): void
    {
        $this->seedNotHealthy();
        $ownerId = $this->createTestUser([], true);
        $carId = $this->createFirstInLineEligibleCar($ownerId, 'next-queue');

        $xpath = $this->getVerificationTab('next_queue');

        $this->assertSame('next_queue', $this->activePillStatus($xpath));
        $forms = $xpath->query('//form[.//input[@name="command"][@value="verification_send_batch"]]');
        $this->assertSame(1, $forms->length, 'One send form');
        $form = $forms->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);
        $this->assertSame('POST', strtoupper($form->getAttribute('method')));
        $this->assertStringContainsString('status=next_queue', $form->getAttribute('action'));
        $sent = [];
        foreach ($xpath->query('.//input[@name="car_ids[]"]', $form) as $input) {
            $this->assertInstanceOf(DOMElement::class, $input);
            $sent[] = (int) $input->getAttribute('value');
        }
        $this->assertContains($carId, $sent, 'The oldest-verified car is in the batch');
        $this->assertSame($carId, $sent[0], 'The car with the oldest last_verified is first');
    }

    /**
     * The dates run in reverse id order: the highest id has the oldest date.
     * With ORDER BY id alone, Eligible would list [c1, c2, c3], so the test
     * fails when the query drops last_verified from the order. Expected send
     * order: [c3, c2, c1], and Next Queue (batch size 2) is [c3, c2].
     */
    public function testNextQueueRowsAreTheFirstRowsOfEligible(): void
    {
        $this->seedNotHealthy();
        $ownerId = $this->createTestUser([], true);
        // The setup check of the call with the latest date covers all three cars, in any call order.
        $c1 = $this->createFirstInLineEligibleCar($ownerId, 'same-order-1', '1988-03-01 00:00:00');
        $c2 = $this->createFirstInLineEligibleCar($ownerId, 'same-order-2', '1988-02-01 00:00:00');
        $c3 = $this->createFirstInLineEligibleCar($ownerId, 'same-order-3', '1988-01-01 00:00:00');
        // Batch size 2, below the 3 cars: Next Queue is a strict prefix of Eligible.
        $this->db->query('UPDATE er_verification_settings SET batch_size = 2 WHERE id = 1');
        $this->assertFalse($this->db->error(), 'Test setup: batch_size 2: ' . $this->db->errorString());
        $ids = static function (DOMXPath $xpath): array {
            $out = [];
            foreach ($xpath->query('//*[@id="verificationQueue"]//table/tbody/tr/td[1]/a') as $a) {
                $out[] = (int) trim($a->textContent);
            }

            return $out;
        };

        $eligible = $ids($this->getVerificationTab('eligible'));
        $nextQueueTab = $this->getVerificationTab('next_queue');
        $nextQueue = $ids($nextQueueTab);
        $sent = [];
        foreach ($nextQueueTab->query('//form[.//input[@name="command"][@value="verification_send_batch"]]//input[@name="car_ids[]"]') as $input) {
            $this->assertInstanceOf(DOMElement::class, $input);
            $sent[] = (int) $input->getAttribute('value');
        }

        $this->assertSame([$c3, $c2, $c1], array_slice($eligible, 0, 3), 'Eligible lists the oldest last_verified first');
        $this->assertSame([$c3, $c2], $nextQueue, 'Next Queue shows the first batch of 2 rows of Eligible');
        $this->assertSame([$c3, $c2], $sent, 'The Next Queue send form posts the same ids, in the same order');
    }

    /** @return array<string, array{0: string}> The column that the queue row lacks */
    public static function queueRowMissingColumnProvider(): array
    {
        return [
            'no email_address_malformed' => ['email_address_malformed'],
            'no has_profile'             => ['has_profile'],
        ];
    }

    /**
     * A queue row that lacks a column is a fault of the queue, not an empty
     * queue and not a page error: the tab shows the alert, renders no rows,
     * and writes one log line.
     */
    #[DataProvider('queueRowMissingColumnProvider')]
    public function testQueueRowWithAMissingColumnShowsTheAlertRendersNoRowsAndLogsOnce(string $missingColumn): void
    {
        $this->seedNotHealthy();
        $row = [
            'id'                      => 987654,
            'has_profile'             => 1,
            'email_opted_out'         => 0,
            'email_blocked_active'    => 0,
            'email_blocked_cause'     => null,
            'email_bounced_active'    => 0,
            'email_address_malformed' => 0,
        ];
        unset($row[$missingColumn]);
        $countSql = 'SELECT COUNT(*) AS n FROM logs WHERE user_id = ? AND logtype = ? AND lognote LIKE ?';
        $countParams = [$this->adminId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, '%has no email flag columns%'];
        $this->assertSame(0, (int) $this->db->query($countSql, $countParams)->first()->n, 'Test setup: no such log row yet');

        $xpath = $this->getVerificationTab('pending', null, [], [$row]);

        $this->assertSame(
            1,
            $xpath->query('//*[contains(@class, "alert-danger")][contains(normalize-space(.), "The verification queue could not be loaded. Check the system log for details.")]')->length,
            'The tab shows the queue fault alert'
        );
        $this->assertSame(0, $xpath->query('//*[@id="verificationQueue"]//table/tbody/tr')->length, 'No queue row renders');
        $this->assertSame(1, (int) $this->db->query($countSql, $countParams)->first()->n, 'One log line for the fault');
    }

    public function testNextQueuePillCountIsTheSmallerOfEligibleAndTheBatchSize(): void
    {
        $this->seedNotHealthy();
        $ownerId = $this->createTestUser([], true);
        for ($i = 0; $i < 3; $i++) {
            $this->createFirstInLineEligibleCar($ownerId, "count-{$i}");
        }

        // Batch size 1, eligible 3 or more: the batch size is the smaller.
        $this->db->query('UPDATE er_verification_settings SET batch_size = 1 WHERE id = 1');
        $this->assertFalse($this->db->error(), 'Test setup: batch_size 1: ' . $this->db->errorString());
        $xpath = $this->getVerificationTab('eligible');
        $eligibleCount = $this->pillCount($xpath, 'eligible');
        $this->assertGreaterThanOrEqual(3, $eligibleCount);
        $this->assertSame(1, $this->pillCount($xpath, 'next_queue'), 'The batch size is below the eligible count');

        // Batch size 25: the smaller is the eligible count (below the batch size), or 25 when more are eligible.
        $this->db->query('UPDATE er_verification_settings SET batch_size = 25 WHERE id = 1');
        $xpath = $this->getVerificationTab('eligible');
        $eligibleCount = $this->pillCount($xpath, 'eligible');
        $this->assertNotNull($eligibleCount);
        $this->assertSame(min($eligibleCount, 25), $this->pillCount($xpath, 'next_queue'));
    }

    public function testNextQueuePillShowsNoCountWhenTheBatchSizeCannotBeRead(): void
    {
        $row = $this->db->query('SELECT * FROM er_verification_settings WHERE id = 1')->first();
        $this->assertIsObject($row, 'Test setup: the id = 1 row exists before the delete');
        $this->deletedSettingsRow = (array) $row;
        $this->db->query('DELETE FROM er_verification_settings WHERE id = 1');
        $this->assertFalse($this->db->error(), 'Test setup: delete row: ' . $this->db->errorString());

        $xpath = $this->getVerificationTab('eligible');

        $this->assertNull($this->pillCount($xpath, 'next_queue'), 'No batch size, no Next Queue count');
        $this->assertNotNull($this->pillCount($xpath, 'eligible'), 'Control: the Eligible pill keeps its count');
    }

    /** @return array<string, array{0: string}> */
    public static function linkedViewProvider(): array
    {
        return [
            'eligible'      => ['eligible'],
            'next_queue'    => ['next_queue'],
            'invalid_email' => ['invalid_email'],
        ];
    }

    #[DataProvider('linkedViewProvider')]
    public function testQueueRowLinksTheCarAndTheOwnerInANewTab(string $view): void
    {
        $this->seedNotHealthy();
        $ownerId = $view === 'invalid_email'
            ? $this->createTestUser(['email' => 'None'], true)
            : $this->createTestUser([], true);
        $carId = $this->createFirstInLineEligibleCar($ownerId, 'links-' . $view);
        // Invalid email lists by id ASC, so show the most rows.
        $extra = $view === 'eligible' ? [] : ['show' => '100'];

        $xpath = $this->getVerificationTab($view, null, $extra);

        $rows = $xpath->query(sprintf(
            '//*[@id="verificationQueue"]//table/tbody/tr[normalize-space(td[1]) = "%d"]',
            $carId
        ));
        $this->assertSame(1, $rows->length, "Car {$carId} has one row in {$view}");

        $carLinks = $xpath->query('td[1]/a', $rows->item(0));
        $this->assertSame(1, $carLinks->length, 'One car link');
        $this->assertNewTabLink($carLinks->item(0), '/app/owner/cars/details.php?car_id=' . $carId);

        $ownerLinks = $xpath->query('td[3]/a', $rows->item(0));
        $this->assertSame(1, $ownerLinks->length, 'One owner link');
        $this->assertNewTabLink($ownerLinks->item(0), '/app/admin/index.php?tab=owner-mgmt&owner_id=' . $ownerId);

        $this->assertNewTabLink(
            $xpath->query('td[4]/a', $rows->item(0))->item(0),
            '/users/admin.php?view=user&id=' . $ownerId
        );
    }

    public function testOwnerNameIsEscapedInTheOwnerLink(): void
    {
        $this->seedNotHealthy();
        $payload = '<b>x</b>"&';
        $ownerId = $this->createTestUser(['fname' => $payload, 'lname' => 'Q'], true);
        $carId = $this->createFirstInLineEligibleCar($ownerId, 'escape');

        $xpath = $this->getVerificationTab('eligible');

        $link = $xpath->query(sprintf(
            '//*[@id="verificationQueue"]//table/tbody/tr[normalize-space(td[1]) = "%d"]/td[3]/a',
            $carId
        ))->item(0);
        $this->assertInstanceOf(DOMElement::class, $link);
        $this->assertSame(0, $xpath->query('a/b', $link)->length, 'The name is text, not markup');
        $this->assertStringContainsString($payload, $link->textContent, 'The raw characters show as text');
    }

    // --- Recent Activity table (#2496) ----------------------------------------

    /** Smallest `activity_window` choice, in days. Seeded rows are stamped inside it. */
    private const ACTIVITY_WINDOW = '7';

    /**
     * Insert one VERIFIED or VERIFIED SOLD cars_hist row for a test car.
     *
     * The car's cars_hist rows are deleted with the car in tearDown.
     *
     * @param int|null $historyUserId user_id of the history row, which can differ from the car's owner
     */
    private function insertActivityRow(
        int $carId,
        string $operation,
        string $timestamp,
        ?int $historyUserId,
        string $fname = '',
        string $lname = ''
    ): void {
        $inserted = (new CarRepository($this->db))->insertHistory([
            'operation' => $operation,
            'car_id'    => $carId,
            'model'     => 'Elan S4',
            'series'    => 'S4',
            'variant'   => 'SE',
            'type'      => 'FHC',
            'chassis'   => 'ACT' . substr(uniqid(), -8),
            'user_id'   => $historyUserId,
            'fname'     => $fname,
            'lname'     => $lname,
            'timestamp' => $timestamp,
        ]);
        $this->assertTrue($inserted, 'Setup: insert cars_hist row: ' . $this->db->errorString());
    }

    private function activityRow(DOMXPath $xpath, int $carId): DOMElement
    {
        $rows = $xpath->query(sprintf(
            '//*[@id="verificationActivityTable"]/tbody/tr[normalize-space(td[3]) = "%d"]',
            $carId
        ));
        $this->assertSame(1, $rows->length, "Car {$carId} has one Recent Activity row");
        $row = $rows->item(0);
        $this->assertInstanceOf(DOMElement::class, $row);

        return $row;
    }

    private function assertNewTabLink(?DOMNode $link, string $expectedHref): void
    {
        $this->assertInstanceOf(DOMElement::class, $link);
        $this->assertSame($expectedHref, $link->getAttribute('href'));
        $this->assertSame('_blank', $link->getAttribute('target'));
        $this->assertSame('noopener', $link->getAttribute('rel'));
    }

    private function datatablesScriptCount(DOMXPath $xpath): int
    {
        return $xpath->query('//script[contains(@src, "usersc/js/datatables.min.js")]')->length;
    }

    public function testRecentActivityRendersATableWithTheFiveColumns(): void
    {
        $this->seedNotHealthy();
        $ownerId = $this->createTestUser(['fname' => 'Cols', 'lname' => 'Owner'], true);
        $carId = $this->createTestCar($ownerId);
        $this->insertActivityRow($carId, 'VERIFIED SOLD', $this->mysqlDatetime('NOW() - INTERVAL 1 HOUR'), $ownerId, 'Cols', 'Owner');

        $xpath = $this->getVerificationTab('eligible', null, ['activity_window' => self::ACTIVITY_WINDOW]);

        $tables = $xpath->query('//*[@id="verificationRecentActivity"]//div[contains(concat(" ", normalize-space(@class), " "), " table-responsive ")]/table[@id="verificationActivityTable"]');
        $this->assertSame(1, $tables->length, 'One table, inside .table-responsive, inside the Recent Activity card');
        $table = $tables->item(0);
        $this->assertInstanceOf(DOMElement::class, $table);
        $this->assertStringContainsString('table-sm', $table->getAttribute('class'));

        $headers = [];
        foreach ($xpath->query('thead/tr/th', $table) as $th) {
            $headers[] = trim($th->textContent);
        }
        $this->assertSame(['Time', 'Operation', 'Car', 'Owner', 'User ID'], $headers);

        $row = $this->activityRow($xpath, $carId);
        $this->assertSame(5, $xpath->query('td', $row)->length, 'Five cells in a row');
        $this->assertSame('VERIFIED SOLD', trim($xpath->query('td[2]', $row)->item(0)->textContent));
        $this->assertSame('Cols Owner', trim($xpath->query('td[4]', $row)->item(0)->textContent));
        $this->assertSame((string) $ownerId, trim($xpath->query('td[5]', $row)->item(0)->textContent));

        $this->assertGreaterThan(0, $xpath->query('//link[contains(@href, "usersc/css/datatables.min.css")]')->length, 'DataTables CSS loads with the table');
        $this->assertSame(1, $this->datatablesScriptCount($xpath), 'DataTables JS loads with the table');
    }

    public function testRecentActivityTimeCellHasTheSortableTimestamp(): void
    {
        $this->seedNotHealthy();
        $ownerId = $this->createTestUser([], true);
        $newerCarId = $this->createTestCar($ownerId);
        $olderCarId = $this->createTestCar($ownerId);
        $newer = $this->mysqlDatetime('NOW() - INTERVAL 1 HOUR');
        $older = $this->mysqlDatetime('NOW() - INTERVAL 2 HOUR');
        // The older row goes in last, so it has the higher id: id order alone would list it first.
        $this->insertActivityRow($newerCarId, 'VERIFIED', $newer, $ownerId);
        $this->insertActivityRow($olderCarId, 'VERIFIED', $older, $ownerId);

        $xpath = $this->getVerificationTab('eligible', null, ['activity_window' => self::ACTIVITY_WINDOW]);

        $newerCell = $this->activityRow($xpath, $newerCarId)->getElementsByTagName('td')->item(0);
        $olderCell = $this->activityRow($xpath, $olderCarId)->getElementsByTagName('td')->item(0);
        $this->assertInstanceOf(DOMElement::class, $newerCell);
        $this->assertInstanceOf(DOMElement::class, $olderCell);
        $this->assertSame($newer, $newerCell->getAttribute('data-order'), 'data-order holds the raw cars_hist timestamp');
        $this->assertSame($older, $olderCell->getAttribute('data-order'));
        $this->assertNotSame($newer, trim($newerCell->textContent), 'The shown text is the display format, not the raw value');

        $order = [];
        foreach ($xpath->query('//*[@id="verificationActivityTable"]/tbody/tr/td[3]') as $cell) {
            $order[] = (int) trim($cell->textContent);
        }
        $newerPosition = array_search($newerCarId, $order, true);
        $olderPosition = array_search($olderCarId, $order, true);
        $this->assertIsInt($newerPosition, 'The newer row is listed');
        $this->assertIsInt($olderPosition, 'The older row is listed');
        $this->assertLessThan($olderPosition, $newerPosition, 'Newest row comes first in the HTML');
    }

    public function testRecentActivityRowLinksTheCarTheOwnerAndTheUserInANewTab(): void
    {
        $this->seedNotHealthy();
        $ownerId = $this->createTestUser([], true);
        $carId = $this->createTestCar($ownerId);
        $this->insertActivityRow($carId, 'VERIFIED', $this->mysqlDatetime('NOW() - INTERVAL 1 HOUR'), $ownerId, 'Link', 'Owner');

        $xpath = $this->getVerificationTab('eligible', null, ['activity_window' => self::ACTIVITY_WINDOW]);
        $row = $this->activityRow($xpath, $carId);

        $this->assertNewTabLink($xpath->query('td[3]/a', $row)->item(0), '/app/owner/cars/details.php?car_id=' . $carId);
        $this->assertNewTabLink($xpath->query('td[4]/a', $row)->item(0), '/app/admin/index.php?tab=owner-mgmt&owner_id=' . $ownerId);
        $this->assertNewTabLink($xpath->query('td[5]/a', $row)->item(0), '/users/admin.php?view=user&id=' . $ownerId);
    }

    public function testRecentActivityUsesTheHistoricalOwner(): void
    {
        $this->seedNotHealthy();
        $currentOwnerId = $this->createTestUser(['fname' => 'Current', 'lname' => 'Keeper'], true);
        $formerOwnerId = $this->createTestUser(['fname' => 'Former', 'lname' => 'Keeper'], true);
        $carId = $this->createTestCar($currentOwnerId);
        $this->insertActivityRow($carId, 'VERIFIED', $this->mysqlDatetime('NOW() - INTERVAL 1 HOUR'), $formerOwnerId, 'Former', 'Keeper');

        $xpath = $this->getVerificationTab('eligible', null, ['activity_window' => self::ACTIVITY_WINDOW]);
        $row = $this->activityRow($xpath, $carId);

        $this->assertNewTabLink($xpath->query('td[4]/a', $row)->item(0), '/app/admin/index.php?tab=owner-mgmt&owner_id=' . $formerOwnerId);
        $this->assertSame('Former Keeper', trim($xpath->query('td[4]', $row)->item(0)->textContent));
        $this->assertNewTabLink($xpath->query('td[5]/a', $row)->item(0), '/users/admin.php?view=user&id=' . $formerOwnerId);
        $this->assertSame(
            0,
            $xpath->query(sprintf('.//a[contains(@href, "id=%d")]', $currentOwnerId), $row)->length,
            "The car's current owner is not linked"
        );
    }

    /** @return array<string, array{0: int|null, 1: string, 2: string, 3: string}> */
    public static function noOwnerProvider(): array
    {
        return [
            'zero, no name'   => [0, '', '', "\u{2014}"],
            'null, no name'   => [null, '', '', "\u{2014}"],
            'zero, with name' => [0, 'Plain', 'Text', 'Plain Text'],
        ];
    }

    #[DataProvider('noOwnerProvider')]
    public function testRecentActivityRowWithNoOwnerShowsNoOwnerLink(?int $historyUserId, string $fname, string $lname, string $expectedOwner): void
    {
        $this->seedNotHealthy();
        $ownerId = $this->createTestUser([], true);
        $carId = $this->createTestCar($ownerId);
        $this->insertActivityRow($carId, 'VERIFIED', $this->mysqlDatetime('NOW() - INTERVAL 1 HOUR'), $historyUserId, $fname, $lname);

        $xpath = $this->getVerificationTab('eligible', null, ['activity_window' => self::ACTIVITY_WINDOW]);
        $row = $this->activityRow($xpath, $carId);

        $this->assertSame($expectedOwner, trim($xpath->query('td[4]', $row)->item(0)->textContent));
        $this->assertSame(0, $xpath->query('td[4]//a', $row)->length, 'No owner link');
        $this->assertSame("\u{2014}", trim($xpath->query('td[5]', $row)->item(0)->textContent));
        $this->assertSame(0, $xpath->query('td[5]//a', $row)->length, 'No user link');
        $this->assertSame(1, $xpath->query('td[3]/a', $row)->length, 'Control: the car link stays');
    }

    public function testRecentActivityOwnerWithNoNameShowsTheOwnerNumberAsTheLink(): void
    {
        $this->seedNotHealthy();
        $ownerId = $this->createTestUser([], true);
        $carId = $this->createTestCar($ownerId);
        $this->insertActivityRow($carId, 'VERIFIED', $this->mysqlDatetime('NOW() - INTERVAL 1 HOUR'), $ownerId);

        $xpath = $this->getVerificationTab('eligible', null, ['activity_window' => self::ACTIVITY_WINDOW]);
        $link = $xpath->query('td[4]/a', $this->activityRow($xpath, $carId))->item(0);

        $this->assertNewTabLink($link, '/app/admin/index.php?tab=owner-mgmt&owner_id=' . $ownerId);
        $this->assertSame('owner #' . $ownerId, trim($link->textContent));
    }

    public function testRecentActivityOwnerNameIsEscaped(): void
    {
        $this->seedNotHealthy();
        $payload = '<b>x</b>"&';
        $ownerId = $this->createTestUser([], true);
        $carId = $this->createTestCar($ownerId);
        $this->insertActivityRow($carId, 'VERIFIED', $this->mysqlDatetime('NOW() - INTERVAL 1 HOUR'), $ownerId, $payload, 'Q');

        $xpath = $this->getVerificationTab('eligible', null, ['activity_window' => self::ACTIVITY_WINDOW]);

        $link = $xpath->query('td[4]/a', $this->activityRow($xpath, $carId))->item(0);
        $this->assertInstanceOf(DOMElement::class, $link);
        $this->assertSame(0, $xpath->query('b', $link)->length, 'The name is text, not markup');
        $this->assertStringContainsString($payload, $link->textContent, 'The raw characters show as text');
    }

    public function testRecentActivityEmptyWindowShowsTheMessageAndNoTable(): void
    {
        $this->seedNotHealthy();
        $inWindow = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist
              WHERE operation IN ('VERIFIED', 'VERIFIED SOLD') AND timestamp >= NOW() - INTERVAL 7 DAY"
        )->first();
        $this->assertIsObject($inWindow);
        $this->assertSame(0, (int) $inWindow->cnt, 'Setup: no VERIFIED row in the last 7 days of this database');

        $xpath = $this->getVerificationTab('eligible', null, ['activity_window' => self::ACTIVITY_WINDOW]);

        $this->assertSame(0, $xpath->query('//*[@id="verificationActivityTable"]')->length, 'No table');
        $card = $xpath->query('//*[@id="verificationRecentActivity"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $card);
        $this->assertStringContainsString('No verification activity in this window.', $card->textContent);
        $this->assertSame(0, $this->datatablesScriptCount($xpath), 'DataTables JS stays off when there is no table');
        $this->assertSame(0, $xpath->query('//link[contains(@href, "usersc/css/datatables.min.css")]')->length, 'DataTables CSS stays off');
    }
}
