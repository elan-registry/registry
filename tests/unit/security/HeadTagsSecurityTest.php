<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Test head_tags.php security migration
 *
 * Verifies that head_tags.php uses validated server globals
 * and properly handles invalid host scenarios.
 */
#[Group('security')]
#[Group('head-tags')]
#[Group('server-globals')]
class HeadTagsSecurityTest extends TestCase
{
    private string $headTagsFile;
    private string $fileContent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->headTagsFile = dirname(__DIR__, 3) . '/usersc/includes/head_tags.php';

        // Load file once with error handling
        if (!is_file($this->headTagsFile)) {
            $this->markTestSkipped("head_tags.php not found at {$this->headTagsFile}");
        }

        $content = file_get_contents($this->headTagsFile);
        if ($content === false) {
            $this->markTestSkipped("Unable to read head_tags.php");
        }

        $this->fileContent = (string) $content;
    }

    /**
     * Test that head_tags.php doesn't redefine $current_url
     */
    public function testDoesNotRedefineCurrentUrl(): void
    {
        // Should not have assignment to $current_url
        $this->assertStringNotContainsString(
            '$current_url =',
            $this->fileContent,
            'head_tags.php should not redefine $current_url (use server global instead)'
        );
    }

    /**
     * Test that head_tags.php doesn't access $_SERVER directly
     */
    public function testNoDirectServerAccess(): void
    {
        // Should not access $_SERVER
        $this->assertStringNotContainsString(
            '$_SERVER[',
            $this->fileContent,
            'head_tags.php should not access $_SERVER directly (use server globals instead)'
        );
    }

    /**
     * Test that canonical link has host validation
     */
    public function testCanonicalLinkHasHostValidation(): void
    {
        // Should check if host is not empty before canonical link
        $this->assertStringContainsString(
            'if (!empty($host))',
            $this->fileContent,
            'head_tags.php should validate $host before rendering canonical link'
        );

        // Should have canonical link
        $this->assertStringContainsString(
            '<link rel="canonical"',
            $this->fileContent,
            'head_tags.php should have canonical link'
        );
    }

    /**
     * Test that OG URL tags have host validation
     */
    public function testOgUrlHasHostValidation(): void
    {
        // Should have og:url meta tag
        $this->assertStringContainsString(
            'og:url',
            $this->fileContent,
            'head_tags.php should have og:url meta tag'
        );

        // Should be within host validation conditional
        // (verify canonical and og:url are in same conditional block)
        $canonicalPos = strpos($this->fileContent, 'rel="canonical"');
        $ogUrlPos = strpos($this->fileContent, 'property="og:url"');

        $this->assertNotFalse($canonicalPos, 'Canonical link should exist');
        $this->assertNotFalse($ogUrlPos, 'OG URL should exist');

        // Both should be after the same if (!empty($host)) check
        $hostCheckPos = strpos($this->fileContent, 'if (!empty($host))');
        $this->assertIsInt($hostCheckPos);
        $this->assertLessThan($canonicalPos, $hostCheckPos, 'Host check should be before canonical link');
        $this->assertLessThan($ogUrlPos, $hostCheckPos, 'Host check should be before og:url');
    }

    /**
     * #1960: $current_url carries the raw query string, so the canonical
     * must come from CanonicalUrl::build() or a page-set $pageCanonicalUrl.
     */
    public function testCanonicalUsesBuilderOrPageOverrideNotCurrentUrl(): void
    {
        $this->assertStringContainsString(
            'CanonicalUrl::build($current_origin, $php_self)',
            $this->fileContent,
            'head_tags.php must build the default canonical from $current_origin and $php_self (#1960)'
        );
        $this->assertStringContainsString(
            '!empty($pageCanonicalUrl)',
            $this->fileContent,
            'head_tags.php must let a page override the canonical with $pageCanonicalUrl (#1960)'
        );

        foreach (['rel="canonical" href', 'property="og:url" content', 'name="twitter:url" content'] as $tag) {
            $this->assertDoesNotMatchRegularExpression(
                '/' . preg_quote($tag, '/') . '="<\?=[^>]*\$current_url/',
                $this->fileContent,
                "$tag must not echo \$current_url, which includes the raw query string (#1960)"
            );
        }
    }

    /**
     * The builder must run inside the host check: with no valid host,
     * $current_origin has no host and no URL tag renders.
     */
    public function testCanonicalBuilderIsInsideHostCheck(): void
    {
        $hostCheckPos = strpos($this->fileContent, 'if (!empty($host))');
        $builderPos = strpos($this->fileContent, 'CanonicalUrl::build(');
        $endifPos = strpos($this->fileContent, '<?php endif; ?>');

        $this->assertIsInt($hostCheckPos);
        $this->assertIsInt($builderPos);
        $this->assertIsInt($endifPos);
        $this->assertLessThan($builderPos, $hostCheckPos, 'CanonicalUrl::build() must come after the host check');
        $this->assertLessThan($endifPos, $builderPos, 'CanonicalUrl::build() must come before the end of the host check');
    }

    /**
     * Test that the Twitter URL meta tag uses $canonical_url
     */
    public function testTwitterUrlUsesCanonicalUrl(): void
    {
        $this->assertStringContainsString(
            '<meta name="twitter:url" content="<?= htmlspecialchars($canonical_url, ENT_QUOTES, \'UTF-8\') ?>">',
            $this->fileContent,
            'head_tags.php twitter:url must echo the escaped $canonical_url (#1960)'
        );
    }

    /**
     * #1960: canonical, og:url, and twitter:url must give one URL for a
     * response, so all three echo one variable.
     */
    public function testCanonicalOgTwitterUseSameVariable(): void
    {
        $patterns = [
            'canonical'   => '/<link rel="canonical" href="<\?= htmlspecialchars\((\$\w+),/',
            'og:url'      => '/<meta property="og:url" content="<\?= htmlspecialchars\((\$\w+),/',
            'twitter:url' => '/<meta name="twitter:url" content="<\?= htmlspecialchars\((\$\w+),/',
        ];

        $variables = [];
        foreach ($patterns as $tag => $pattern) {
            $matches = [];
            $this->assertSame(1, preg_match($pattern, $this->fileContent, $matches), "$tag tag must exist");
            $variables[$tag] = $matches[1];
        }

        $this->assertSame(
            ['canonical' => '$canonical_url', 'og:url' => '$canonical_url', 'twitter:url' => '$canonical_url'],
            $variables,
            'canonical, og:url, and twitter:url must all echo $canonical_url (#1960)'
        );
    }

    /**
     * Test that $site_description falls back to $pageDescription when set,
     * and treats both unset and empty-string as "not set" (#1372).
     */
    public function testSiteDescriptionUsesPageDescriptionOverride(): void
    {
        $this->assertStringContainsString(
            '!empty($pageDescription)',
            $this->fileContent,
            'head_tags.php should use !empty() (not ??) so an empty-string $pageDescription '
                . 'also falls back to the generic default, not just unset/null'
        );

        $this->assertStringContainsString(
            '$site_description = !empty($pageDescription)',
            $this->fileContent,
            'head_tags.php should assign $site_description from the $pageDescription override check'
        );
    }
}
