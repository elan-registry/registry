<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for Issue #2247: the legacy `/stats.php`, `/stories` and
 * `/stories/` URLs returned 404. The #1369 rule `^stories/(.+)$` needs at
 * least one character after the slash, and no rule covered `/stats.php`.
 *
 * @issue 2247
 * @link https://github.com/elan-registry/registry/issues/2247
 * @category regression
 */
#[Group('regression')]
final class Issue2247LegacyRedirectsRegressionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const HTACCESS = self::ROOT . '/.htaccess';

    /**
     * Returns the trimmed directive lines, without blank and comment lines.
     *
     * @return list<string>
     */
    private function directiveLines(): array
    {
        $contents = file_get_contents(self::HTACCESS);
        $this->assertIsString($contents, '.htaccess must be readable');
        $lines = array_map('trim', preg_split('/\R/', $contents) ?: []);

        return array_values(preg_grep('/^[^#]/', $lines) ?: []);
    }

    public function testStatsPhpRedirectsToStatisticsPage(): void
    {
        $this->assertContains(
            'Redirect 301 /stats.php /app/owner/reports/statistics.php',
            $this->directiveLines(),
            'Legacy /stats.php must redirect to the statistics page (#2247).'
        );
    }

    public function testStoriesRootRedirectsToCarStoriesPage(): void
    {
        $this->assertContains(
            'RedirectMatch 301 ^/stories/?$ /docs/car-stories.php',
            $this->directiveLines(),
            '/stories and /stories/ must redirect to the car stories page (#2247).'
        );
    }

    public function testStorySlugRuleIsKept(): void
    {
        $this->assertContains(
            'RewriteRule ^stories/(.+)$ /docs/stories/$1 [R=301,L]',
            $this->directiveLines(),
            '/stories/<slug> must still redirect to /docs/stories/<slug> (#1369).'
        );
    }

    public function testRedirectTargetsExist(): void
    {
        foreach (['app/owner/reports/statistics.php', 'docs/car-stories.php'] as $target) {
            $this->assertFileExists(self::ROOT . '/' . $target, "Redirect target {$target} must exist (#2247).");
        }
    }
}
