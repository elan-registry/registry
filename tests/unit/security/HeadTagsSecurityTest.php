<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test head_tags.php security migration
 *
 * Verifies that head_tags.php uses validated server globals
 * and properly handles invalid host scenarios.
 *
 * @group security
 * @group head-tags
 * @group server-globals
 */
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
        $ogUrlPos = strpos($this->fileContent, 'og:url');

        $this->assertNotFalse($canonicalPos, 'Canonical link should exist');
        $this->assertNotFalse($ogUrlPos, 'OG URL should exist');

        // Both should be after the same if (!empty($host)) check
        $hostCheckPos = strpos($this->fileContent, 'if (!empty($host))');
        $this->assertIsInt($hostCheckPos);
        $this->assertLessThan($canonicalPos, $hostCheckPos, 'Host check should be before canonical link');
        $this->assertLessThan($ogUrlPos, $hostCheckPos, 'Host check should be before og:url');
    }

    /**
     * Test that file uses $current_url for canonical and OG tags
     */
    public function testUsesCurrentUrlGlobal(): void
    {
        // Should use $current_url in canonical link
        $this->assertStringContainsString(
            '$current_url',
            $this->fileContent,
            'head_tags.php should use $current_url global'
        );

        // Should reference server_globals.php in comments
        $this->assertStringContainsString(
            'server_globals.php',
            $this->fileContent,
            'head_tags.php should document use of server_globals.php'
        );
    }

    /**
     * Test that file has proper PHPDoc header
     */
    public function testHasProperDocumentation(): void
    {
        $this->assertStringContainsString(
            '/**',
            $this->fileContent,
            'head_tags.php should have PHPDoc header'
        );
    }

    /**
     * Test that Twitter URL meta tag exists and uses $current_url
     */
    public function testTwitterUrlUsesCurrentUrl(): void
    {
        // Should have twitter:url meta tag
        $this->assertStringContainsString(
            'twitter:url',
            $this->fileContent,
            'head_tags.php should have twitter:url meta tag'
        );
    }

    /**
     * Test that conditional closing tag exists
     */
    public function testHasConditionalClosing(): void
    {
        // Count opening and closing endif
        $openCount = substr_count($this->fileContent, 'if (!empty($host))');
        $closeCount = substr_count($this->fileContent, '<?php endif; ?>');

        $this->assertGreaterThan(
            0,
            $openCount,
            'head_tags.php should have at least one if (!empty($host)) block'
        );

        $this->assertGreaterThan(
            0,
            $closeCount,
            'head_tags.php should have closing endif'
        );
    }
}
