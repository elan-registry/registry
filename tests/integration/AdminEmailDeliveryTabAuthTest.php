<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\SendinblueMessagesTable;

/**
 * #2102: the Email Delivery tab of app/admin/index.php is for admins only.
 *
 * Each request runs the real page in its own `php -r` subprocess, as
 * AdminDeliverabilityActionsAuthTest does. The page is registered for admins
 * and editors in the test schema, so an editor passes securePage(). The tab
 * check is then the only guard under test. The admin request is the control:
 * the same request shows the mail when the user is an admin.
 *
 * The test seeds one mail in plg_sendinblue_messages (and makes the table when
 * the schema has none, then drops it) and asks for that recipient, so an
 * editor who saw the tab would see the address.
 */
#[Group('integration')]
#[Group('admin')]
final class AdminEmailDeliveryTabAuthTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    private const PERMISSION_ADMIN = 2;
    private const PERMISSION_EDITOR = 3;

    /** @var list<int> */
    private array $permissionMatchIds = [];

    private int $adminId = 0;
    private int $editorId = 0;
    private bool $madeTable = false;
    private string $recipient = '';
    private string $messageId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->permissionMatchIds = [];

        $this->adminId = $this->createTestUser(['permissions' => 1]);
        $this->editorId = $this->createTestUser(['permissions' => 1]);
        $this->grantPermission($this->adminId, self::PERMISSION_ADMIN);
        $this->grantPermission($this->editorId, self::PERMISSION_EDITOR);

        $token = bin2hex(random_bytes(6));
        $this->recipient = "edtab-{$token}@example.com";
        $this->messageId = "<edtab-{$token}@brevo>";
        $this->madeTable = SendinblueMessagesTable::createIfMissing($this->db);
        $this->db->query(
            "INSERT INTO plg_sendinblue_messages
                (message_id, recipient_email, sender_email, kind, tags, status, accepted_at)
             VALUES (?, ?, 'registry@example.test', 'transactional', 'verification', 'accepted', ?)",
            [$this->messageId, $this->recipient, date('Y-m-d H:i:s')]
        );
        $this->assertFalse($this->db->error(), 'Test setup: mail: ' . $this->db->errorString());
    }

    protected function tearDown(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            putenv($var);
        }
        if ($this->databaseConnected) {
            foreach ($this->permissionMatchIds as $id) {
                $this->db->query('DELETE FROM user_permission_matches WHERE id = ?', [$id]);
            }
            $this->db->query('DELETE FROM plg_sendinblue_messages WHERE message_id = ?', [$this->messageId]);
            if ($this->madeTable) {
                $this->db->query('DROP TABLE IF EXISTS plg_sendinblue_messages');
            }
            foreach ([$this->adminId, $this->editorId] as $userId) {
                $this->db->query('DELETE FROM logs WHERE user_id = ?', [$userId]);
            }
        }
        parent::tearDown();
    }

    private function grantPermission(int $userId, int $permissionId): void
    {
        $this->db->insert('user_permission_matches', ['user_id' => $userId, 'permission_id' => $permissionId]);
        $this->assertFalse($this->db->error(), 'Test setup: grant permission: ' . $this->db->errorString());
        $this->permissionMatchIds[] = (int) $this->db->lastId();
    }

    /**
     * GET app/admin/index.php?tab=email-delivery&email=<recipient> as a user.
     *
     * @return array{output: string, exitCode: int}
     */
    private function requestTab(int $loggedInUserId): array
    {
        $projectRoot = dirname(__DIR__, 2);
        $page = $projectRoot . '/app/admin/index.php';
        $this->assertFileExists($page);

        foreach (self::DB_ENV_VARS as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if ($value !== false && $value !== '') {
                putenv("$var=$value");
            }
        }

        $fields = sprintf(
            '$fields = ["tab" => "email-delivery", "email" => %s]; $_GET = $fields; $_REQUEST = $fields;',
            var_export($this->recipient, true)
        );

        $script = sprintf(
            'require %s; ' .
            '\Dotenv\Dotenv::createMutable(%s, ".env.test.local")->load(); ' .
            '$_SERVER["DOCUMENT_ROOT"] = %s; ' .
            '$_SERVER["SCRIPT_FILENAME"] = %s; ' .
            '$_SERVER["REQUEST_METHOD"] = "GET"; ' .
            'chdir(%s); ' .
            'require_once %s; ' .
            '$loggedInUser = new User(); $loggedInUser->find(%d); ' .
            '$reflection = new ReflectionClass($loggedInUser); ' .
            '$prop = $reflection->getProperty("_isLoggedIn"); $prop->setValue($loggedInUser, true); ' .
            'global $user; $user = $loggedInUser; $GLOBALS["user"] = $loggedInUser; ' .
            '%s ' .
            'require %s;',
            var_export($projectRoot . '/vendor/autoload.php', true),
            var_export($projectRoot, true),
            var_export($projectRoot, true),
            var_export($page, true),
            var_export(dirname($page), true),
            var_export($projectRoot . '/users/init.php', true),
            $loggedInUserId,
            $fields,
            var_export($page, true)
        );

        $raw = (string) shell_exec(
            'env US_ENVIRONMENT=development BREVO_API_HOST=' . escapeshellarg('http://mock-brevo:8080/v3')
            . ' php -r ' . escapeshellarg($script) . ' 2>&1; echo "___EXIT:$?"'
        );
        [$output, $exitMarker] = array_pad(explode('___EXIT:', $raw), 2, '0');

        return ['output' => $output, 'exitCode' => (int) trim($exitMarker)];
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }

    private function nodeCount(DOMXPath $xp, string $query): int
    {
        $nodes = $xp->query($query);
        $this->assertNotFalse($nodes);

        return $nodes->length;
    }

    public function testEditorGetsNoMailDataNoNavLinkAndTheDefaultTab(): void
    {
        $result = $this->requestTab($this->editorId);
        $html = $result['output'];

        $this->assertSame(0, $result['exitCode'], 'The page must run to the end: ' . substr($html, 0, 500));
        $this->assertStringContainsString('Registry Management', $html, 'Control: the editor reached the admin page');
        $xp = $this->xpath($html);

        foreach (['table', 'empty', 'counts', 'filters', 'plugin-link', 'error', 'plugin-missing', 'pager'] as $suffix) {
            $this->assertSame(0, $this->nodeCount($xp, '//*[@data-testid="email-delivery-' . $suffix . '"]'), "No email-delivery-{$suffix} for an editor");
        }
        $this->assertStringNotContainsString('tab=email-delivery', $html, 'No nav link and no tab URL for an editor');
        $this->assertStringNotContainsString($this->recipient, $html, 'The recipient address is not in the page');
        $this->assertStringNotContainsString($this->messageId, $html);

        $active = $xp->query('//a[contains(concat(" ", normalize-space(@class), " "), " nav-link ") and contains(concat(" ", normalize-space(@class), " "), " active ")]');
        $this->assertNotFalse($active);
        $this->assertSame(1, $active->length, 'One tab is active');
        $this->assertStringContainsString('tab=car-mgmt', (string) $xp->query('//a[contains(concat(" ", normalize-space(@class), " "), " nav-link ") and contains(concat(" ", normalize-space(@class), " "), " active ")]/@href')?->item(0)?->nodeValue);
    }

    public function testAdminGetsTheNavLinkAndTheMailTable(): void
    {
        $result = $this->requestTab($this->adminId);
        $html = $result['output'];

        $this->assertSame(0, $result['exitCode'], 'The page must run to the end: ' . substr($html, 0, 500));
        $xp = $this->xpath($html);

        $this->assertSame(1, $this->nodeCount($xp, '//a[@href="?tab=email-delivery"]'), 'The nav link is there');
        $this->assertSame(1, $this->nodeCount($xp, '//a[@href="?tab=email-delivery"][contains(concat(" ", normalize-space(@class), " "), " active ")]'), 'The Email Delivery tab is active');
        $this->assertSame(1, $this->nodeCount($xp, '//*[@data-testid="email-delivery-plugin-link"]'));
        $this->assertSame(1, $this->nodeCount($xp, '//*[@data-testid="email-delivery-table"]'), 'The seeded mail shows in the table');
        $this->assertSame(0, $this->nodeCount($xp, '//*[@data-testid="email-delivery-error"]'));
        $this->assertSame(0, $this->nodeCount($xp, '//*[@data-testid="email-delivery-plugin-missing"]'));
        $this->assertStringContainsString($this->recipient, $html);
        $this->assertStringContainsString(htmlspecialchars($this->messageId, ENT_QUOTES, 'UTF-8'), $html);
    }
}
