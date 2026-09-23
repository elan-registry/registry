<?php
declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Test behavior of error/500.php — the canonical handler for all 4xx/5xx
 * error codes (400/401/403/404/405/408/500/502/504) since issue #1830
 * consolidated the former dedicated 403.php/404.php pages into it.
 *
 * Runs the real page in a subprocess to verify its icon rendering and that
 * the logging guard actually resolves and fires (issue #1830: the
 * pre-consolidation 403/404 handlers guarded
 * logger() with class_exists('LogCategories') — a bare, unqualified class
 * name that silently never resolved under the Composer PSR-4 autoloader,
 * so logging silently no-op'd for 47 days undetected because no test ever
 * executed the guarded code path).
 *
 * The page's own anti-clickjacking header() calls (X-Frame-Options and CSP
 * frame-ancestors, sent before init.php loads) are pinned at source level in
 * tests/unit/security/SecurityHeadersTest.php. An HTTP-level check such as
 * tests/playwright/security/clickjacking.spec.js cannot detect their removal
 * from error/500.php, because .htaccess:13 sets X-Frame-Options globally.
 */
#[Group('integration')]
#[Group('security')]
#[Group('error-pages')]
class ErrorPageHeadersTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    /** @var string[] LIKE patterns for logs rows this test wrote, cleaned up in tearDown() */
    private array $logCleanupPatterns = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->logCleanupPatterns = [];
    }

    /**
     * The behavioral tests below write real rows to the `logs` table via a
     * subprocess (error/500.php calling logger()) — outside
     * IntegrationTestCase's own car/user tracking, so they need their own
     * cleanup here to avoid polluting the table on every test run.
     */
    protected function tearDown(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            putenv($var);
        }

        if ($this->databaseConnected) {
            foreach ($this->logCleanupPatterns as $pattern) {
                $this->db->query('DELETE FROM logs WHERE lognote LIKE ?', [$pattern]);
            }
        }

        parent::tearDown();
    }

    /**
     * Propagate this test run's DB credentials (loaded from .env.test.local
     * by tests/bootstrap-integration.php) into the process environment so a
     * subprocess spawned via shell_exec() connects to the same dedicated
     * test schema instead of falling back to the project's real .env.
     *
     * Unlike LogDeploymentScriptTest's subprocess (which only requires
     * vendor/autoload.php and connects to the DB directly), error/500.php
     * requires the full users/init.php bootstrap — which hydrates $settings
     * from a DB-backed row, not just raw DB_* credentials. putenv() alone
     * left that hydration broken in testing (settings loaded as an array
     * instead of the expected object), so this subprocess also explicitly
     * re-loads .env.test.local via Dotenv — init.php's own
     * Dotenv::createImmutable() call reads but does not overwrite
     * already-set $_ENV values, so this doesn't conflict with it.
     */
    private function exposeTestDatabaseToSubprocess(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if ($value !== false && $value !== '') {
                putenv("$var=$value");
            }
        }
    }

    /**
     * Compile-time safety net for the #1830 bug class: assert the fully
     * qualified class actually resolves under the Composer autoloader. If a
     * future change breaks the autoload mapping, this fails loudly instead
     * of the guarded logger() call silently no-op'ing like the original bug.
     */
    public function testLogCategoriesClassResolves(): void
    {
        $this->assertTrue(
            class_exists(LogCategories::class),
            'ElanRegistry\LogCategories must resolve via the Composer PSR-4 autoloader'
        );
    }

    /**
     * Invoke error/500.php in a subprocess with the given REDIRECT_STATUS,
     * simulating an Apache ErrorDocument dispatch, and return its captured
     * stdout. A subprocess is used (rather than an in-process require)
     * because the file unconditionally calls header()/http_response_code(),
     * which would emit PHP warnings and pollute global state if required
     * directly inside the PHPUnit process.
     */
    private function renderErrorPage(int $statusCode, string $requestUri = '/nonexistent-page'): string
    {
        $errorPageFile = dirname(__DIR__, 2) . '/error/500.php';
        $projectRoot = dirname(__DIR__, 2);

        $this->exposeTestDatabaseToSubprocess();

        $script = sprintf(
            'require %s; \Dotenv\Dotenv::createMutable(%s, ".env.test.local")->load(); ' .
            '$_SERVER["REDIRECT_STATUS"] = %d; $_SERVER["REQUEST_URI"] = %s; ' .
            '$_SERVER["DOCUMENT_ROOT"] = %s; chdir(%s); require %s;',
            var_export($projectRoot . '/vendor/autoload.php', true),
            var_export($projectRoot, true),
            $statusCode,
            var_export($requestUri, true),
            var_export($projectRoot, true),
            var_export(dirname($errorPageFile), true),
            var_export($errorPageFile, true)
        );

        $output = shell_exec('php -r ' . escapeshellarg($script) . ' 2>&1');

        return $output !== null ? $output : '';
    }

    #[DataProvider('nonStaticNotFoundPathProvider')]
    public function test404NonStaticPathWritesOnePageNotFoundRow(string $requestUri): void
    {
        $this->logCleanupPatterns[] = "%{$requestUri}%";
        $before = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_PAGE_NOT_FOUND, "%{$requestUri}%");

        $this->renderErrorPage(404, $requestUri);

        $after = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_PAGE_NOT_FOUND, "%{$requestUri}%");

        $this->assertSame(
            $before + 1,
            $after,
            "404 on non-static path {$requestUri} should write exactly one PageNotFound log row"
        );
    }

    /**
     * Icon rendering is new output-mapping logic introduced by #1830's
     * consolidation ($iconSvgMap/match on icon_type) — a future edit to the
     * match arms could silently render the wrong icon for a status code
     * without any other test catching it, so this asserts the distinctive
     * markup for each icon shape appears for the codes that should use it.
     */
    #[DataProvider('iconRenderingProvider')]
    public function testRendersExpectedIconForStatusCode(int $statusCode, string $distinctiveMarkup): void
    {
        $requestUri = '/icon-test-' . uniqid();
        $this->logCleanupPatterns[] = "%{$requestUri}%";

        $output = $this->renderErrorPage($statusCode, $requestUri);

        $this->assertStringContainsString(
            $distinctiveMarkup,
            $output,
            "Status {$statusCode} should render the expected icon markup"
        );
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function iconRenderingProvider(): array
    {
        return [
            // 403/404 use dedicated icons sourced from the former 403.php/404.php.
            '403 renders lock icon' => [403, '<rect x="5" y="11" width="14" height="10" rx="2" fill="#d9230f"/>'],
            '404 renders search icon' => [404, '<circle cx="11" cy="11" r="7" stroke="#d9230f" stroke-width="2" fill="none"/>'],
            // Every other code falls through match()'s default arm to the shared generic icon.
            '400 renders generic icon' => [400, '<line x1="12" y1="8" x2="12" y2="12" stroke="#d9230f" stroke-width="2" stroke-linecap="round"/>'],
            '500 renders generic icon' => [500, '<line x1="12" y1="8" x2="12" y2="12" stroke="#d9230f" stroke-width="2" stroke-linecap="round"/>'],
        ];
    }

    /**
     * @return array<string, array<string>>
     */
    public static function nonStaticNotFoundPathProvider(): array
    {
        $uniqueSuffix = uniqid();

        return [
            'non-static path' => ["/nonexistent-page-{$uniqueSuffix}"],
        ];
    }

    #[DataProvider('staticAssetPathProvider')]
    public function test404StaticAssetPathWritesZeroRows(string $requestUri): void
    {
        $before = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_PAGE_NOT_FOUND, "%{$requestUri}%");

        $output = $this->renderErrorPage(404, $requestUri);

        // "Zero rows written" is indistinguishable from "the subprocess crashed
        // before reaching logger()" unless we also confirm it actually ran to
        // completion — this positively confirms the page rendered rather than
        // vacuously passing on a silent subprocess failure.
        $this->assertStringContainsString(
            '</html>',
            $output,
            "renderErrorPage() for {$requestUri} should have completed and rendered the page, not crashed"
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'Fatal error',
            $output,
            "renderErrorPage() for {$requestUri} should not emit a fatal error"
        );

        $after = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_PAGE_NOT_FOUND, "%{$requestUri}%");

        $this->assertSame(
            $before,
            $after,
            "404 on static-asset path {$requestUri} should write zero log rows"
        );
    }

    /**
     * @return array<string, array<string>>
     */
    public static function staticAssetPathProvider(): array
    {
        $uniqueSuffix = uniqid();

        return [
            'jpg' => ["/missing-{$uniqueSuffix}.jpg"],
            'css' => ["/missing-{$uniqueSuffix}.css"],
            'js' => ["/missing-{$uniqueSuffix}.js"],
        ];
    }

    public function test403WritesOneAccessDeniedRow(): void
    {
        $requestUri = '/.git/config-' . uniqid();
        $this->logCleanupPatterns[] = "%{$requestUri}%";

        $before = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, "%{$requestUri}%");

        $this->renderErrorPage(403, $requestUri);

        $after = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, "%{$requestUri}%");

        $this->assertSame(
            $before + 1,
            $after,
            "403 on {$requestUri} should write exactly one AccessDenied log row"
        );
    }
}
