<?php
declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * error/500.php, the handler for all 4xx/5xx codes since #1830, run in a
 * subprocess. #1830: the old logging guard used an unqualified class name that
 * never resolved, so logging silently did nothing for 47 days.
 *
 * The anti-clickjacking headers are pinned in
 * tests/unit/security/SecurityHeadersTest.php: an HTTP check cannot see their
 * removal, because .htaccess sets X-Frame-Options globally.
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

    /** The subprocess writes `logs` rows outside IntegrationTestCase's tracking. */
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
     * Pass the test DB credentials to the subprocess so it uses the test
     * schema, not the real .env. error/500.php loads users/init.php, which
     * hydrates $settings from the DB; putenv() alone left it broken, so the
     * subprocess also reloads .env.test.local via Dotenv.
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

    /** #1830: the fully qualified class must resolve under the Composer autoloader. */
    public function testLogCategoriesClassResolves(): void
    {
        $this->assertTrue(
            class_exists(LogCategories::class),
            'ElanRegistry\LogCategories must resolve via the Composer PSR-4 autoloader'
        );
    }

    /**
     * Run error/500.php in a subprocess with the given REDIRECT_STATUS and
     * return stdout. Not required in-process: its header() calls would warn
     * and pollute global state.
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

    /** #1830 icon mapping: a wrong match arm would otherwise go unnoticed. */
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

        // Proves the page ran to completion, so "zero rows" is not a silent crash.
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
