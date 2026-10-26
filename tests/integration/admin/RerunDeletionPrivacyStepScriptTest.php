<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\SendinblueMessagesTable;

/**
 * #2419: 30-Rerun-Deletion-Privacy-Step.php, the manual rerun in the runbook.
 *
 * invokeEndpoint() runs the real page in its own `php -r` subprocess, with a
 * real session user, real CSRF token and the real database. So these tests
 * run the page, not only its source.
 *
 * The plugin table is made if missing and dropped at the end only if this
 * class made it (see SendinblueMessagesTable).
 */
#[Group('integration')]
#[Group('admin')]
final class RerunDeletionPrivacyStepScriptTest extends IntegrationTestCase
{
    private const SCRIPT = 'app/admin/scripts/maintenance/30-Rerun-Deletion-Privacy-Step.php';

    private const PERMISSION_ADMIN = 2;

    private const PERMISSION_EDITOR = 3;

    private static bool $madePluginTable = false;

    /** @var list<string> */
    private array $pluginMessageIds = [];

    /** @var list<int> */
    private array $loggedUserIds = [];

    private int $adminId = 0;

    private int $fixScriptRunsMaxId = 0;

    public static function tearDownAfterClass(): void
    {
        if (self::$madePluginTable) {
            DB::getInstance()->query('DROP TABLE IF EXISTS plg_sendinblue_messages');
            self::$madePluginTable = false;
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->pluginMessageIds = [];
        $this->loggedUserIds = [];
        if (SendinblueMessagesTable::createIfMissing($this->db)) {
            self::$madePluginTable = true;
        }
        $this->fixScriptRunsMaxId = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) AS m FROM fix_script_runs')->first()->m;
        $this->adminId = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($this->adminId, self::PERMISSION_ADMIN);
        $this->loggedUserIds[] = $this->adminId;
    }

    protected function tearDown(): void
    {
        // DB::query() can throw from prepare(). The finally keeps parent::tearDown(), which deletes the test users, reachable.
        try {
            if ($this->databaseConnected) {
                foreach ($this->pluginMessageIds as $messageId) {
                    $this->db->query('DELETE FROM plg_sendinblue_messages WHERE message_id = ?', [$messageId]);
                }
                foreach ($this->loggedUserIds as $userId) {
                    $this->db->query('DELETE FROM logs WHERE user_id = ?', [$userId]);
                }
                // A committed rerun records itself in fix_script_runs.
                $this->db->query(
                    'DELETE FROM fix_script_runs WHERE id > ? AND script_name = ?',
                    [$this->fixScriptRunsMaxId, basename(self::SCRIPT)]
                );
            }
        } finally {
            parent::tearDown();
        }
    }

    private function uniqueAddress(string $prefix): string
    {
        return $prefix . '-' . uniqid() . '@example.test';
    }

    private function insertPluginRow(string $messageId, string $recipient): void
    {
        $this->db->query(
            "INSERT INTO plg_sendinblue_messages (message_id, recipient_email, sender_email, kind, status, accepted_at)
             VALUES (?, ?, 'registry@example.test', 'transactional', 'delivered', '2026-05-01 10:00:00')",
            [$messageId, $recipient]
        );
        $this->assertFalse($this->db->error(), 'Test setup: plugin row: ' . $this->db->errorString());
        $this->pluginMessageIds[] = $messageId;
    }

    private function pluginRowExists(string $messageId): bool
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS cnt FROM plg_sendinblue_messages WHERE message_id = ?',
            [$messageId]
        )->first()->cnt === 1;
    }

    private function profileCount(int $userId): int
    {
        return (int) $this->db->query('SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?', [$userId])->first()->cnt;
    }

    private function holdingRowCount(int $userId): int
    {
        return (int) $this->db->query('SELECT COUNT(*) AS cnt FROM er_deleted_user_addresses WHERE user_id = ?', [$userId])->first()->cnt;
    }

    /**
     * An owner with a pending address, and a plugin row for each address.
     *
     * @return array{id: int, rows: list<string>}
     */
    private function createOwnerWithPluginRows(): array
    {
        $current = $this->uniqueAddress('current');
        $pending = $this->uniqueAddress('pending');
        $userId = $this->createTestUser(['email' => $current, 'email_new' => $pending], true);
        $this->loggedUserIds[] = $userId;
        $currentRow = 'rerun-script-current-' . uniqid();
        $pendingRow = 'rerun-script-pending-' . uniqid();
        $this->insertPluginRow($currentRow, $current);
        $this->insertPluginRow($pendingRow, $pending);

        return ['id' => $userId, 'rows' => [$currentRow, $pendingRow]];
    }

    /**
     * fix_script_runs rows that this test's runs of the script recorded.
     */
    private function recordedRunCount(): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS cnt FROM fix_script_runs WHERE id > ? AND script_name = ?',
            [$this->fixScriptRunsMaxId, basename(self::SCRIPT)]
        )->first()->cnt;
    }

    private function deleteUsersRowOnly(int $userId): void
    {
        $this->db->delete('users', ['id', '=', $userId]);
        $this->assertFalse($this->db->error(), 'Test setup: users delete: ' . $this->db->errorString());
    }

    /**
     * A deleted owner whose privacy step did not run: the holding row, the
     * profile and the plugin rows all remain.
     *
     * @return array{id: int, rows: list<string>}
     */
    private function createPendingDeletedOwner(): array
    {
        $owner = $this->createOwnerWithPluginRows();
        $this->deleteUsersRowOnly($owner['id']);
        $this->assertSame(1, $this->holdingRowCount($owner['id']), 'Test setup: the trigger must write the holding row');
        $this->assertSame(1, $this->profileCount($owner['id']), 'Test setup: the profile remains');

        return $owner;
    }

    public function testAdminRerunForADeletedUserDeletesRowsAndClearsTheHoldingRow(): void
    {
        $owner = $this->createPendingDeletedOwner();

        $result = $this->invokeEndpoint(self::SCRIPT, ['user_id' => (string) $owner['id']], $this->adminId);

        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 800));
        // The full page has inline JS that names TypeError, so assertNoPhpErrorText() would fire.
        $this->assertDoesNotMatchRegularExpression('/(Fatal error|Parse error|Warning|Notice|Deprecated): .* in \/.*\.php on line \d+/', $result['raw']);
        $this->assertStringContainsString('Committed. Rows deleted', $result['raw']);
        $this->assertStringContainsString('The daily retention purge deletes those rows once they are older than 90 days.', $result['raw']);
        $this->assertSame(0, $this->holdingRowCount($owner['id']));
        $this->assertSame(0, $this->profileCount($owner['id']));
        foreach ($owner['rows'] as $messageId) {
            $this->assertFalse($this->pluginRowExists($messageId), 'The rerun reads both addresses from the holding row');
        }
        $this->assertSame(1, $this->recordedRunCount(), 'A committed rerun records itself in fix_script_runs');
        $this->assertStringNotContainsString('No holding row was found', $result['raw']);
    }

    public function testARerunForAnIdWithNoHoldingRowWarnsTheAdmin(): void
    {
        $owner = $this->createPendingDeletedOwner();
        // A second run, or a mistyped ID of a deleted user, has no holding row.
        $this->db->query('DELETE FROM er_deleted_user_addresses WHERE user_id = ?', [$owner['id']]);
        $this->assertFalse($this->db->error(), 'Test setup: holding-row delete: ' . $this->db->errorString());

        $result = $this->invokeEndpoint(self::SCRIPT, ['user_id' => (string) $owner['id']], $this->adminId);

        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 800));
        $this->assertStringContainsString('Committed. Rows deleted', $result['raw']);
        $this->assertStringContainsString("No holding row was found for user {$owner['id']}.", $result['raw']);
    }

    public function testTheRerunPageAndLogShowNoAddress(): void
    {
        $owner = $this->createPendingDeletedOwner();
        $addresses = array_map(
            fn (string $messageId): string => (string) $this->db->query(
                'SELECT recipient_email FROM plg_sendinblue_messages WHERE message_id = ?',
                [$messageId]
            )->first()->recipient_email,
            $owner['rows']
        );

        $result = $this->invokeEndpoint(self::SCRIPT, ['user_id' => (string) $owner['id']], $this->adminId);

        $this->assertStringContainsString('Committed', $result['raw']);
        $logs = $this->db->query('SELECT lognote FROM logs WHERE user_id IN (?, ?)', [$this->adminId, $owner['id']])->results();
        $this->assertNotSame([], $logs, 'Test control: the run wrote log lines');
        foreach ($addresses as $address) {
            $this->assertStringNotContainsString($address, $result['raw']);
            foreach ($logs as $log) {
                $this->assertStringNotContainsString($address, (string) $log->lognote);
            }
        }
    }

    public function testALiveUserIdIsRefusedAndNothingIsDeleted(): void
    {
        $owner = $this->createOwnerWithPluginRows();

        $result = $this->invokeEndpoint(self::SCRIPT, ['user_id' => (string) $owner['id']], $this->adminId);

        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 800));
        $this->assertStringContainsString('Refused', $result['raw']);
        $this->assertStringNotContainsString('Committed', $result['raw']);
        $this->assertSame(1, $this->profileCount($owner['id']), 'A live owner keeps the profile');
        foreach ($owner['rows'] as $messageId) {
            $this->assertTrue($this->pluginRowExists($messageId), 'A live owner keeps the plugin rows');
        }
        $this->assertSame(0, $this->recordedRunCount(), 'A refused rerun must not show as completed');
    }

    public function testABadCsrfTokenRunsNothing(): void
    {
        $owner = $this->createPendingDeletedOwner();

        $result = $this->invokeEndpoint(self::SCRIPT, ['user_id' => (string) $owner['id']], $this->adminId, 'invalid');

        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 800));
        $this->assertStringContainsString('form token is not valid', $result['raw']);
        $this->assertStringNotContainsString('Committed', $result['raw']);
        $this->assertSame(1, $this->holdingRowCount($owner['id']));
        $this->assertSame(1, $this->profileCount($owner['id']));
        $this->assertTrue($this->pluginRowExists($owner['rows'][0]));
    }

    public function testAMissingCsrfTokenRunsNothing(): void
    {
        $owner = $this->createPendingDeletedOwner();

        $result = $this->invokeEndpoint(self::SCRIPT, ['user_id' => (string) $owner['id']], $this->adminId, 'omit');

        $this->assertStringContainsString('form token is not valid', $result['raw']);
        $this->assertSame(1, $this->holdingRowCount($owner['id']));
        $this->assertSame(1, $this->profileCount($owner['id']));
    }

    public function testAUserWhoIsNotAdminRunsNothing(): void
    {
        $owner = $this->createPendingDeletedOwner();
        $plainUser = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($plainUser, 1);
        $this->loggedUserIds[] = $plainUser;

        $result = $this->invokeEndpoint(self::SCRIPT, ['user_id' => (string) $owner['id']], $plainUser);

        // The positive marker: the page ran to the end and securePage() sent the redirect.
        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 500));
        $this->assertSame(302, $result['httpCode'], 'securePage() must redirect a plain user');
        $this->assertStringNotContainsString('Committed', $result['raw']);
        $this->assertStringNotContainsString('Rerun Deletion Privacy Step', $result['raw'], 'A non-admin must not see the page');
        $this->assertSame(1, $this->holdingRowCount($owner['id']));
        $this->assertSame(1, $this->profileCount($owner['id']));
        $this->assertTrue($this->pluginRowExists($owner['rows'][0]));
    }

    public function testAnEditorRunsNothing(): void
    {
        $owner = $this->createPendingDeletedOwner();
        $editor = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($editor, self::PERMISSION_EDITOR);
        $this->loggedUserIds[] = $editor;

        $result = $this->invokeEndpoint(self::SCRIPT, ['user_id' => (string) $owner['id']], $editor);

        // PageRegistrationSeed registers app/admin/scripts/ pages for admin only, so securePage() stops an editor.
        // The positive marker: the page ran to the end and securePage() sent the redirect.
        // Without it, a crash would also show no page.
        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 500));
        $this->assertSame(302, $result['httpCode'], 'securePage() must redirect an editor');
        $this->assertStringNotContainsString('Rerun Deletion Privacy Step', $result['raw'], 'An editor must not see the page');
        $this->assertStringNotContainsString('Committed', $result['raw']);
        $this->assertSame(1, $this->holdingRowCount($owner['id']));
        $this->assertSame(1, $this->profileCount($owner['id']));
        $this->assertTrue($this->pluginRowExists($owner['rows'][0]));
    }

    /**
     * isAdmin() is the second gate. A site can give an editor this page in
     * the page permissions, and securePage() then lets the editor in. The
     * page must still refuse the run.
     */
    public function testAnEditorWithPagePermissionIsStoppedByIsAdmin(): void
    {
        $owner = $this->createPendingDeletedOwner();
        $editor = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($editor, self::PERMISSION_EDITOR);
        $this->loggedUserIds[] = $editor;

        $page = $this->db->query('SELECT id FROM pages WHERE page = ?', [self::SCRIPT]);
        $this->assertFalse($page->error(), 'Test setup: pages lookup: ' . $page->errorString());
        $this->assertSame(1, $page->count(), 'Test setup: PageRegistrationSeed must register the page');
        $pageId = (int) $page->first()->id;

        $this->db->insert('permission_page_matches', ['permission_id' => self::PERMISSION_EDITOR, 'page_id' => $pageId]);
        $this->assertFalse($this->db->error(), 'Test setup: page permission: ' . $this->db->errorString());
        $matchId = (int) $this->db->lastId();
        $this->assertGreaterThan(0, $matchId, 'Test setup: page permission row ID');

        try {
            $result = $this->invokeEndpoint(self::SCRIPT, ['user_id' => (string) $owner['id']], $editor);
        } finally {
            // Other tests expect the seeded admin-only permission, so the row must go even when the call fails.
            $this->db->query('DELETE FROM permission_page_matches WHERE id = ?', [$matchId]);
            $this->assertFalse($this->db->error(), 'Test cleanup: page permission delete: ' . $this->db->errorString());
        }

        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 500));
        $this->assertStringContainsString('Administrator access required', $result['raw']);
        $this->assertStringNotContainsString('Committed', $result['raw']);
        $securityLogs = (int) $this->db->query(
            'SELECT COUNT(*) AS cnt FROM logs WHERE user_id = ? AND logtype = ?',
            [$editor, LogCategories::LOG_CATEGORY_SECURITY]
        )->first()->cnt;
        $this->assertSame(1, $securityLogs, 'isAdmin() refusal must write a Security log row');
        $this->assertSame(1, $this->holdingRowCount($owner['id']));
        $this->assertSame(1, $this->profileCount($owner['id']));
        foreach ($owner['rows'] as $messageId) {
            $this->assertTrue($this->pluginRowExists($messageId));
        }
    }

    public function testAFailedRerunShowsTheStageAndLogsNoAddress(): void
    {
        $owner = $this->createPendingDeletedOwner();
        $addresses = array_map(
            fn (string $messageId): string => (string) $this->db->query(
                'SELECT recipient_email FROM plg_sendinblue_messages WHERE message_id = ?',
                [$messageId]
            )->first()->recipient_email,
            $owner['rows']
        );

        // The plugin delete reads er_email_events.brevo_message_id. Without the column the step fails at that stage.
        $this->db->query('ALTER TABLE er_email_events RENAME COLUMN brevo_message_id TO brevo_message_id_renamed_for_test');
        $this->assertFalse($this->db->error(), 'Test setup: failed to force a DB error: ' . $this->db->errorString());
        try {
            $result = $this->invokeEndpoint(self::SCRIPT, ['user_id' => (string) $owner['id']], $this->adminId);
        } finally {
            $this->db->query('ALTER TABLE er_email_events RENAME COLUMN brevo_message_id_renamed_for_test TO brevo_message_id');
            $this->assertFalse($this->db->error(), 'Test cleanup: failed to restore the column: ' . $this->db->errorString());
        }

        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 800));
        $this->assertStringContainsString('Failed at stage <strong>plugin delete</strong>', $result['raw']);
        $this->assertStringNotContainsString('Committed', $result['raw']);
        $this->assertSame(1, $this->holdingRowCount($owner['id']), 'The holding row stays for the next rerun');
        $this->assertSame(1, $this->profileCount($owner['id']));
        $this->assertSame(0, $this->recordedRunCount(), 'A rolled-back rerun must not show as completed');
        $notes = array_map(
            static fn (object $row): string => (string) $row->lognote,
            $this->db->query('SELECT lognote FROM logs WHERE user_id = ? AND logtype = ?', [$this->adminId, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR])->results()
        );
        $this->assertContains("Deletion privacy step rerun rolled back for user {$owner['id']} at stage plugin delete", $notes);
        foreach ($addresses as $address) {
            $this->assertStringNotContainsString($address, $result['raw']);
            foreach ($notes as $note) {
                $this->assertStringNotContainsString($address, $note);
            }
        }
    }

    public function testALoggedOutVisitorRunsNothing(): void
    {
        $owner = $this->createPendingDeletedOwner();

        $result = $this->invokeEndpoint(self::SCRIPT, ['user_id' => (string) $owner['id']], null);

        // The positive marker: the page ran to the end and securePage() sent the redirect to the login page.
        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 500));
        $this->assertSame(302, $result['httpCode'], 'securePage() must redirect a logged-out visitor');
        $this->assertStringNotContainsString('Committed', $result['raw']);
        $this->assertSame(1, $this->holdingRowCount($owner['id']));
        $this->assertSame(1, $this->profileCount($owner['id']));
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function badUserIdProvider(): array
    {
        return [
            'missing'      => [[]],
            'empty'        => [['user_id' => '']],
            'non-numeric'  => [['user_id' => 'abc']],
            'zero'         => [['user_id' => '0']],
            'negative'     => [['user_id' => '-5']],
            'decimal'      => [['user_id' => '12.5']],
            'trailing text' => [['user_id' => '12abc']],
        ];
    }

    /**
     * @param array<string, string> $post
     */
    #[DataProvider('badUserIdProvider')]
    public function testAMissingOrBadUserIdIsRejectedAndNothingRuns(array $post): void
    {
        $owner = $this->createPendingDeletedOwner();

        $result = $this->invokeEndpoint(self::SCRIPT, $post, $this->adminId);

        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 800));
        $this->assertStringContainsString('whole number of 1 or more', $result['raw']);
        $this->assertStringNotContainsString('Committed', $result['raw']);
        $this->assertSame(1, $this->holdingRowCount($owner['id']), 'Another deleted user is not touched');
        $this->assertSame(1, $this->profileCount($owner['id']));
    }

    public function testSecurePageComesBeforeIsAdminAndBeforeTheRun(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../' . self::SCRIPT);

        $core = strpos($source, 'fix-script-core.php');
        $securePage = strpos($source, 'securePage($php_self)');
        $isAdmin = strpos($source, 'isAdmin()');
        $run = strpos($source, '->run(');

        $this->assertNotFalse($core, 'The script must load fix-script-core.php');
        $this->assertNotFalse($securePage, 'The script must call securePage($php_self)');
        $this->assertNotFalse($isAdmin, 'The script must call isAdmin()');
        $this->assertNotFalse($run, 'The script must call the privacy step');
        $this->assertLessThan($securePage, $core);
        $this->assertLessThan($isAdmin, $securePage, 'securePage($php_self) must come before isAdmin()');
        $this->assertLessThan($run, $isAdmin, 'isAdmin() must come before the run');
        $this->assertStringContainsString('admin_script_exec_requested()', $source, 'The run needs a POST with a valid token');
    }
}
