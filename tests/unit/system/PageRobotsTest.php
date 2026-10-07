<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1371: a page that does not set $pageRobots before init.php is silently
 * indexable, because head_tags.php falls back to 'index, follow'. The
 * convention is in elanregistry_overrides.md.php section 6.
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
        // Fail, not skip: a renamed page must turn this gate red.
        $this->assertFileExists($filePath, "$relativePath must exist (Issue #1371)");

        $content = (string)file_get_contents($filePath);

        $this->assertSame(
            1,
            preg_match('/\$pageRobots\s*=\s*[\'"]noindex,\s*follow[\'"]/', $content),
            "$relativePath must set \$pageRobots = 'noindex, follow' (Issue #1371)"
        );
    }

    /** Same before-init.php order as PageMetadataBeforeInitRule. */
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

        // Anchored to require_once: a comment that names init.php comes earlier.
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
