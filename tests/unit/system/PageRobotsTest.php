<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Noindex gate for Issue #1371.
 *
 * Per the ElanRegistry `$pageRobots` convention (introduced alongside the
 * `$pageTitle`/`$pageDescription` convention — see
 * usersc/plugins/ai_prompts/custom_prompts/elanregistry_overrides.md.php,
 * section 6), pages that should be excluded from search-engine indexing
 * must set `$pageRobots = 'noindex, follow';` BEFORE their
 * `require_once '.../init.php'` call, mirroring the timing requirement for
 * `$pageTitle`. usersc/includes/head_tags.php only falls back to the
 * site-wide default (`index, follow`) when `$pageRobots` is empty, so a
 * page that never sets it — or sets it too late — is silently indexable.
 *
 * This test is a pure static-text scan: it reads each file's raw source
 * via file_get_contents() and never requires/executes it, so it needs no
 * database and no bootstrapped UserSpice environment. It covers the 2
 * pages given noindex treatment under #1371 (the factory build records
 * list and the privacy policy), so a future regression on either file is
 * caught immediately.
 */
#[Group('system')]
#[Group('page-metadata')]
class PageRobotsTest extends TestCase
{
    private const PAGES_REQUIRING_NOINDEX = [
        'app/owner/cars/factory.php',
        'app/owner/privacy.php',
        'app/verify/verify_car.php',
    ];

    private const HEAD_TAGS_FILE = 'usersc/includes/head_tags.php';

    private string $rootDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootDir = dirname(__DIR__, 3);
    }

    #[DataProvider('pagesProvider')]
    public function testPageHasPageRobotsAssignment(string $relativePath): void
    {
        $filePath = $this->rootDir . '/' . $relativePath;
        // A hard failure here (not markTestSkipped) is deliberate: if one of these
        // pages is ever moved or renamed without updating this list, the
        // noindex gate must go red, not silently green.
        $this->assertFileExists($filePath, "$relativePath must exist (Issue #1371)");

        $content = (string)file_get_contents($filePath);

        $this->assertSame(
            1,
            preg_match('/\$pageRobots\s*=\s*[\'"]noindex,\s*follow[\'"]/', $content),
            "$relativePath must set \$pageRobots = 'noindex, follow' (Issue #1371)"
        );
    }

    /**
     * Critical timing check — see class docblock for the full rationale.
     * head_tags.php's fallback (`!empty($pageRobots) ? $pageRobots : 'index, follow'`)
     * reads whatever value is in scope when it renders, but the convention
     * requires $pageRobots to be assigned before init.php for consistency
     * with $pageTitle/$pageDescription (matching PageMetadataCompletenessTest),
     * so this test enforces the same ordering here.
     */
    #[DataProvider('pagesProvider')]
    public function testPageRobotsAssignmentPrecedesInitRequire(string $relativePath): void
    {
        $filePath = $this->rootDir . '/' . $relativePath;
        $this->assertFileExists($filePath, "$relativePath must exist (Issue #1371)");

        $content = (string)file_get_contents($filePath);

        $variableMatches = [];
        preg_match('/\$pageRobots\s*=/', $content, $variableMatches, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty(
            $variableMatches,
            "$relativePath must contain a \$pageRobots assignment to check its position (Issue #1371)"
        );

        // Anchor to the require_once statement itself, not a bare 'init.php' substring —
        // a docblock or comment mentioning "init.php" earlier in the file would otherwise
        // give a false-negative position and fail this test on correct code.
        $initMatches = [];
        preg_match('/require_once[^\n]*init\.php/', $content, $initMatches, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty(
            $initMatches,
            "$relativePath must require init.php so the \$pageRobots timing can be verified (Issue #1371)"
        );

        $this->assertLessThan(
            $initMatches[0][1],
            $variableMatches[0][1],
            "$relativePath must assign \$pageRobots BEFORE requiring init.php, per the convention in " .
            'elanregistry_overrides.md.php section 6 (Issue #1371)'
        );
    }

    public function testHeadTagsHasPageRobotsFallback(): void
    {
        $filePath = $this->rootDir . '/' . self::HEAD_TAGS_FILE;
        $this->assertFileExists($filePath, self::HEAD_TAGS_FILE . ' must exist (Issue #1371)');

        $content = (string)file_get_contents($filePath);

        $this->assertStringContainsString(
            "\$pageRobots = !empty(\$pageRobots) ? \$pageRobots : 'index, follow';",
            $content,
            self::HEAD_TAGS_FILE . ' must retain the $pageRobots fallback to the site-wide default (Issue #1371)'
        );
    }

    public function testHeadTagsRobotsMetaTagUsesPageRobotsVariable(): void
    {
        $filePath = $this->rootDir . '/' . self::HEAD_TAGS_FILE;
        $this->assertFileExists($filePath, self::HEAD_TAGS_FILE . ' must exist (Issue #1371)');

        $content = (string)file_get_contents($filePath);

        // Guards against a future edit reverting the meta tag to a hardcoded
        // 'index, follow' string, which would silently break noindex pages.
        $this->assertStringContainsString(
            '<meta name="robots" content="<?= htmlspecialchars($pageRobots,',
            $content,
            self::HEAD_TAGS_FILE . ' robots meta tag must render $pageRobots, not a hardcoded value (Issue #1371)'
        );
    }

    public static function pagesProvider(): array
    {
        $data = [];
        foreach (self::PAGES_REQUIRING_NOINDEX as $file) {
            $data[$file] = [$file];
        }
        return $data;
    }
}
