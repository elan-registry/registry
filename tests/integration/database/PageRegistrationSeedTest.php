<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * #1671: database/seeds/PageRegistrationSeed.php. A Phinx seed cannot be
 * instantiated from PHPUnit, so it runs as `vendor/bin/phinx seed:run`
 * against the test schema.
 *
 * `pages` and `permission_page_matches` are truncated in setUp() and
 * restored in tearDown(), so other tests keep the real page inventory.
 * Permission id=3 comes from the RegisterBaselinePermissions migration.
 */
#[Group('integration')]
#[Group('migration')]
final class PageRegistrationSeedTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    /** @var list<array<string, mixed>> Snapshot of `pages` rows before this suite truncates the table. */
    private array $pagesSnapshot = [];

    /** @var list<array<string, mixed>> Snapshot of `permission_page_matches` rows before truncation. */
    private array $permissionMatchesSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $editorPermission = $this->fetchAllRows('SELECT * FROM `permissions` WHERE id = 3');
        if ($editorPermission === []) {
            $this->markTestSkipped(
                'permissions id=3 (Editor) is missing. Run: composer migrate ' .
                '(RegisterBaselinePermissions) before running this suite.'
            );
        }

        $this->pagesSnapshot = $this->fetchAllRows('SELECT * FROM `pages`');
        $this->permissionMatchesSnapshot = $this->fetchAllRows('SELECT * FROM `permission_page_matches`');

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('TRUNCATE TABLE `permission_page_matches`');
        $this->db->query('TRUNCATE TABLE `pages`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
            $this->db->query('TRUNCATE TABLE `permission_page_matches`');
            $this->db->query('TRUNCATE TABLE `pages`');

            foreach ($this->pagesSnapshot as $row) {
                $this->db->insert('pages', $row);
            }
            foreach ($this->permissionMatchesSnapshot as $row) {
                $this->db->insert('permission_page_matches', $row);
            }
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        parent::tearDown();
    }

    public function testSeedRegistersKnownPagesAcrossAllBranchesAndIsIdempotent(): void
    {
        [$returnCode, $output] = $this->runSeed();
        $this->assertSame(0, $returnCode, 'Seeds must exit 0. Output: ' . implode("\n", $output));

        $editorPermission = $this->fetchAllRows('SELECT * FROM `permissions` WHERE id = 3');
        $this->assertCount(1, $editorPermission, 'permissions id=3 must exist');
        $this->assertSame('Editor', $editorPermission[0]['name'], 'permissions id=3 must be named Editor');

        // #1671: z_us_root.php must be core=1, as on dev and prod.
        $rootPage = $this->fetchAllRows("SELECT * FROM `pages` WHERE `page` = 'z_us_root.php'");
        $this->assertCount(1, $rootPage, 'z_us_root.php must be registered exactly once');
        $this->assertSame(1, (int) $rootPage[0]['core'], 'z_us_root.php must be registered with core=1');

        // login/join match no directory rule but must be public with no permissions.
        foreach (['usersc/login.php', 'usersc/join.php'] as $explicitPublicPage) {
            $page = $this->fetchAllRows('SELECT * FROM `pages` WHERE `page` = ?', [$explicitPublicPage]);
            $this->assertCount(1, $page, "{$explicitPublicPage} must be registered exactly once");
            $this->assertSame(0, (int) $page[0]['private'], "{$explicitPublicPage} must not be private");

            $pageId = (int) $page[0]['id'];
            $permissions = $this->fetchAllRows(
                'SELECT * FROM `permission_page_matches` WHERE `page_id` = ?',
                [$pageId]
            );
            $this->assertCount(0, $permissions, "{$explicitPublicPage} must have no permission rows");
        }

        $adminPage = $this->fetchAllRows(
            "SELECT * FROM `pages` WHERE `page` = 'app/admin/scripts/maintenance/21-Fix-Page-Permissions.php'"
        );
        $this->assertCount(1, $adminPage, 'Admin-only page must be registered exactly once');
        $this->assertSame(1, (int) $adminPage[0]['private'], 'Admin-only page must be private');

        $adminPageId = (int) $adminPage[0]['id'];
        $adminPermissions = $this->fetchAllRows(
            'SELECT * FROM `permission_page_matches` WHERE `page_id` = ?',
            [$adminPageId]
        );
        $this->assertCount(1, $adminPermissions, 'Admin-only page must have exactly one permission row');
        $this->assertSame(2, (int) $adminPermissions[0]['permission_id'], 'Admin-only page must grant Administrator (2)');

        // docs/pdf-viewer.php calls securePage() only to register, not to require login.
        $publicPage = $this->fetchAllRows("SELECT * FROM `pages` WHERE `page` = 'docs/pdf-viewer.php'");
        $this->assertCount(1, $publicPage, 'Public page must be registered exactly once');
        $this->assertSame(0, (int) $publicPage[0]['private'], 'Public page must not be private');

        $publicPageId = (int) $publicPage[0]['id'];
        $publicPermissions = $this->fetchAllRows(
            'SELECT * FROM `permission_page_matches` WHERE `page_id` = ?',
            [$publicPageId]
        );
        $this->assertCount(0, $publicPermissions, 'Public page must have no permission rows');

        // users/* takes a different code path (getUserSpiceInstallerSpec()).
        $usersPage = $this->fetchAllRows("SELECT * FROM `pages` WHERE `page` = 'users/account.php'");
        $this->assertCount(1, $usersPage, 'users/account.php must be registered exactly once');
        $this->assertSame(1, (int) $usersPage[0]['private'], 'users/account.php must be private');

        $usersPageId = (int) $usersPage[0]['id'];
        $usersPagePermissions = $this->fetchAllRows(
            'SELECT * FROM `permission_page_matches` WHERE `page_id` = ?',
            [$usersPageId]
        );
        $this->assertCount(1, $usersPagePermissions, 'users/account.php must have exactly one permission row');
        $this->assertSame(1, (int) $usersPagePermissions[0]['permission_id'], 'users/account.php must grant User (1)');

        $pagesCountAfterFirstRun = $this->countRows('pages');
        $matchesCountAfterFirstRun = $this->countRows('permission_page_matches');
        $this->assertGreaterThan(0, $pagesCountAfterFirstRun, 'Seed must have registered at least one page');

        [$secondReturnCode, $secondOutput] = $this->runSeed();
        $this->assertSame(
            0,
            $secondReturnCode,
            'Re-running the seed must exit 0. Output: ' . implode("\n", $secondOutput)
        );

        $this->assertSame(
            $pagesCountAfterFirstRun,
            $this->countRows('pages'),
            'Re-running the seed must not change the pages row count'
        );
        $this->assertSame(
            $matchesCountAfterFirstRun,
            $this->countRows('permission_page_matches'),
            'Re-running the seed must not change the permission_page_matches row count'
        );
    }

    /**
     * The healing path for isRegistrationComplete(): a `pages` row with an
     * incomplete permission set (left by UserSpice createPages() or an old seed).
     */
    public function testSeedHealsIncompletePermissionMatchesOnExistingPage(): void
    {
        $page = 'app/admin/scripts/maintenance/21-Fix-Page-Permissions.php';

        $this->db->insert('pages', [
            'page' => $page,
            'title' => null,
            'private' => 1,
            're_auth' => 0,
            'core' => 0,
            'lang_key' => null,
        ]);
        $preExistingPageId = (int) $this->db->lastId();

        $matchesBeforeSeed = $this->fetchAllRows(
            'SELECT * FROM `permission_page_matches` WHERE `page_id` = ?',
            [$preExistingPageId]
        );
        $this->assertCount(0, $matchesBeforeSeed, 'Test setup must start with zero matches for this page');

        [$returnCode, $output] = $this->runSeed();
        $this->assertSame(0, $returnCode, 'Seeds must exit 0. Output: ' . implode("\n", $output));

        $pageRows = $this->fetchAllRows('SELECT * FROM `pages` WHERE `page` = ?', [$page]);
        $this->assertCount(1, $pageRows, 'Healing must not insert a duplicate pages row');
        $this->assertSame(
            $preExistingPageId,
            (int) $pageRows[0]['id'],
            'Healing must reuse the existing pages.id, not insert a new row'
        );

        $matchesAfterSeed = $this->fetchAllRows(
            'SELECT * FROM `permission_page_matches` WHERE `page_id` = ?',
            [$preExistingPageId]
        );
        $this->assertCount(1, $matchesAfterSeed, 'Healing must add the missing permission_page_matches row');
        $this->assertSame(
            2,
            (int) $matchesAfterSeed[0]['permission_id'],
            'Healed match must grant Administrator (2), matching classification'
        );
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function runSeed(): array
    {
        $this->exposeTestDatabaseToSubprocess();

        $phinxBinary = __DIR__ . '/../../../vendor/bin/phinx';
        $phinxConfig = __DIR__ . '/../../../phinx.php';

        $command = 'php ' . escapeshellarg($phinxBinary)
            . ' seed:run'
            . ' -c ' . escapeshellarg($phinxConfig)
            . ' -s ' . escapeshellarg('PageRegistrationSeed')
            . ' 2>&1';

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        return [$returnCode, $output];
    }

    /** Without these the phinx subprocess falls back to the real .env. */
    private function exposeTestDatabaseToSubprocess(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if (is_string($value) && $value !== '') {
                putenv("$var=$value");
            }
        }
    }

    /**
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    private function fetchAllRows(string $sql, array $bindings = []): array
    {
        $rows = $this->db->query($sql, $bindings)->results();

        return array_map(static fn(object $row): array => (array) $row, $rows);
    }

    private function countRows(string $table): int
    {
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $row = $this->db->query("SELECT COUNT(*) AS cnt FROM {$quotedTable}")->first();

        return is_object($row) ? (int) $row->cnt : 0;
    }
}
