<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2101: Mark Bounced, Clear Bounced and Clear Suppression in
 * app/admin/index.php stay POST-only, CSRF-checked and admin-only.
 *
 * Each request runs the real page in its own `php -r` subprocess, as
 * VerificationToggleEndpointBehaviorTest does for the toggle endpoint. The page
 * is registered for admins and editors in the test schema, so an editor passes
 * securePage() and reaches the `hasPerm([2])` check inside the handler. That is
 * the check under test. The admin request is the control: it shows that the
 * same harness changes the profile when the request is allowed.
 */
#[Group('integration')]
#[Group('admin')]
final class AdminDeliverabilityActionsAuthTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    private const PERMISSION_ADMIN = 2;
    private const PERMISSION_EDITOR = 3;

    /** @var list<int> user_permission_matches ids created by this test */
    private array $permissionMatchIds = [];

    /** @var list<int> users whose logs rows tearDown deletes */
    private array $loggedUserIds = [];

    private int $adminId = 0;
    private int $editorId = 0;
    private int $ownerId = 0;
    private int $carId = 0;
    private string $ownerEmail = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->permissionMatchIds = [];
        $this->loggedUserIds = [];

        // securePage() treats `users.permissions = 0` as a banned account.
        $this->adminId = $this->createTestUser(['permissions' => 1]);
        $this->editorId = $this->createTestUser(['permissions' => 1]);
        $this->grantPermission($this->adminId, self::PERMISSION_ADMIN);
        $this->grantPermission($this->editorId, self::PERMISSION_EDITOR);

        $this->ownerEmail = 'auth-owner-' . uniqid() . '@example.com';
        $this->ownerId = $this->createTestUser(['email' => $this->ownerEmail], true);
        $this->carId = $this->createTestCar($this->ownerId, ['email' => $this->ownerEmail]);
        $this->loggedUserIds = [$this->adminId, $this->editorId, $this->ownerId];
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
            foreach ($this->loggedUserIds as $userId) {
                $this->db->query('DELETE FROM logs WHERE user_id = ?', [$userId]);
                $this->db->query(
                    "DELETE FROM logs
                      WHERE logtype = ?
                        AND JSON_VALID(CONVERT(metadata USING utf8mb4))
                        AND JSON_EXTRACT(CONVERT(metadata USING utf8mb4), '$.owner_id') = CAST(? AS UNSIGNED)",
                    [LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, (string) $userId]
                );
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
     * Put the owner in a state in which the command has a change to make.
     */
    private function seedFlagsFor(string $command): void
    {
        if ($command === 'clear_bounced') {
            $this->setOwnerBounced($this->ownerId, $this->ownerEmail, '2026-01-02 03:04:05');
        } elseif ($command === 'clear_suppression') {
            $this->setOwnerBlocked($this->ownerId, $this->ownerEmail, '2026-01-02 03:04:05', 'spam');
            $this->setOwnerOptedOut($this->ownerId, true, '2026-01-02 03:04:06');
        }
    }

    /**
     * Run app/admin/index.php as $loggedInUserId and return the page output.
     *
     * @param string $method   'POST' puts the fields in $_POST. 'GET' puts them in $_GET.
     * @param bool   $validCsrf False sends a token that does not match the session
     * @return array{output: string, exitCode: int}
     */
    private function requestAdminPage(int $loggedInUserId, string $method, string $command, bool $validCsrf): array
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
            '$fields = ["command" => %s, "car_id" => %s, "csrf" => %s]; ',
            var_export($command, true),
            var_export((string) $this->carId, true),
            $validCsrf ? '\Token::generate()' : var_export(str_repeat('0', 64), true)
        );
        $assign = $method === 'POST' ? '$_POST = $fields; $_REQUEST = $fields;' : '$_GET = $fields; $_REQUEST = $fields;';

        $script = sprintf(
            'require %s; ' .
            '\Dotenv\Dotenv::createMutable(%s, ".env.test.local")->load(); ' .
            '$_SERVER["DOCUMENT_ROOT"] = %s; ' .
            '$_SERVER["PHP_SELF"] = "/app/admin/index.php"; ' .
            '$_SERVER["REQUEST_METHOD"] = %s; ' .
            'chdir(%s); ' .
            'require_once %s; ' .
            '$loggedInUser = new User(); $loggedInUser->find(%d); ' .
            '$reflection = new ReflectionClass($loggedInUser); ' .
            '$prop = $reflection->getProperty("_isLoggedIn"); $prop->setValue($loggedInUser, true); ' .
            'global $user; $user = $loggedInUser; $GLOBALS["user"] = $loggedInUser; ' .
            '%s %s ' .
            'require %s;',
            var_export($projectRoot . '/vendor/autoload.php', true),
            var_export($projectRoot, true),
            var_export($projectRoot, true),
            var_export($method, true),
            var_export(dirname($page), true),
            var_export($projectRoot . '/users/init.php', true),
            $loggedInUserId,
            $fields,
            $assign,
            var_export($page, true)
        );

        $raw = (string) shell_exec('php -r ' . escapeshellarg($script) . ' 2>&1; echo "___EXIT:$?"');
        [$output, $exitMarker] = array_pad(explode('___EXIT:', $raw), 2, '0');

        return ['output' => $output, 'exitCode' => (int) trim($exitMarker)];
    }

    private function refusalLogCount(int $userId, string $command): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS cnt FROM logs WHERE logtype = ? AND user_id = ? AND lognote = ?',
            [
                LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
                $userId,
                "Verification: non-admin attempted command '{$command}'",
            ]
        )->first()->cnt;
    }

    private function flagChangeLogCount(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM logs
              WHERE logtype = ?
                AND JSON_VALID(CONVERT(metadata USING utf8mb4))
                AND JSON_EXTRACT(CONVERT(metadata USING utf8mb4), '$.owner_id') = CAST(? AS UNSIGNED)",
            [LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, (string) $this->ownerId]
        )->first()->cnt;
    }

    /** @return array<string, array{0: string}> */
    public static function commandProvider(): array
    {
        return [
            'Mark Bounced'      => ['mark_bounced'],
            'Clear Bounced'     => ['clear_bounced'],
            'Clear Suppression' => ['clear_suppression'],
        ];
    }

    #[DataProvider('commandProvider')]
    public function testAdminPostWithAValidTokenChangesTheOwnerProfile(string $command): void
    {
        $this->seedFlagsFor($command);
        $before = $this->getOwnerEmailFlags($this->ownerId);

        $result = $this->requestAdminPage($this->adminId, 'POST', $command, true);

        $this->assertSame(0, $result['exitCode'], 'The page must run to the end: ' . substr($result['output'], 0, 500));
        $this->assertNotSame($before, $this->getOwnerEmailFlags($this->ownerId), 'Control: an admin POST must change the profile');
        $this->assertSame(1, $this->flagChangeLogCount() - ($command === 'clear_suppression' ? 1 : 0), 'The change writes a flag-change log row');
        $this->assertSame(0, $this->refusalLogCount($this->adminId, $command), 'An admin is not refused');
    }

    #[DataProvider('commandProvider')]
    public function testEditorPostIsRefusedLoggedAndChangesNothing(string $command): void
    {
        $this->seedFlagsFor($command);
        $before = $this->getOwnerEmailFlags($this->ownerId);
        $changesBefore = $this->flagChangeLogCount();
        $this->assertSame(0, $this->refusalLogCount($this->editorId, $command), 'Test setup');

        $result = $this->requestAdminPage($this->editorId, 'POST', $command, true);

        $this->assertSame(0, $result['exitCode'], 'The page must run to the end: ' . substr($result['output'], 0, 500));
        $this->assertSame($before, $this->getOwnerEmailFlags($this->ownerId), 'An editor must not change the profile');
        $this->assertSame($changesBefore, $this->flagChangeLogCount(), 'A refused action writes no flag-change row');
        $this->assertSame(1, $this->refusalLogCount($this->editorId, $command), 'The refusal is logged once, with the editor as the user');
    }

    #[DataProvider('commandProvider')]
    public function testGetRequestChangesNothing(string $command): void
    {
        $this->seedFlagsFor($command);
        $before = $this->getOwnerEmailFlags($this->ownerId);
        $changesBefore = $this->flagChangeLogCount();

        $result = $this->requestAdminPage($this->adminId, 'GET', $command, true);

        $this->assertSame(0, $result['exitCode'], 'The page must run to the end: ' . substr($result['output'], 0, 500));
        $this->assertSame($before, $this->getOwnerEmailFlags($this->ownerId), 'An admin GET with the command in the query string must do nothing');
        $this->assertSame($changesBefore, $this->flagChangeLogCount());
        $this->assertSame(0, $this->refusalLogCount($this->adminId, $command), 'The GET never reached the handler');
    }

    #[DataProvider('commandProvider')]
    public function testPostWithAnInvalidTokenChangesNothing(string $command): void
    {
        $this->seedFlagsFor($command);
        $before = $this->getOwnerEmailFlags($this->ownerId);
        $changesBefore = $this->flagChangeLogCount();

        $result = $this->requestAdminPage($this->adminId, 'POST', $command, false);

        $this->assertStringContainsString('There was an error with your form', $result['output'], 'The token error page is shown');
        $this->assertSame($before, $this->getOwnerEmailFlags($this->ownerId), 'An admin POST with a bad token must do nothing');
        $this->assertSame($changesBefore, $this->flagChangeLogCount());
    }
}
