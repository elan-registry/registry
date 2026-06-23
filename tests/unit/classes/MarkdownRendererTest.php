<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ElanRegistry\Documentation\MarkdownRenderer;

use PHPUnit\Framework\Attributes\Group;

/**
 * MarkdownRenderer class tests
 *
 * Integration tests for the league/commonmark-based Markdown renderer.
 * Covers GFM features, heading permalinks, external-link safety attributes,
 * URL resolution, img-fluid injection, and XSS prevention.
 *
 * @see Issue #815 — Replace MarkdownParser with league/commonmark
 */
#[Group('fast')]
final class MarkdownRendererTest extends TestCase
{
    // ============================================================
    // EXTERNAL LINK TESTS
    // ============================================================

    public function testExternalLinkGetsTargetAndRel(): void
    {
        $html = MarkdownRenderer::convert('[link](https://example.com)');

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function testAnchorLinkDoesNotGetTarget(): void
    {
        $html = MarkdownRenderer::convert('[link](#section)');

        $this->assertStringNotContainsString('target=', $html);
    }

    public function testRootRelativeLinkDoesNotGetTarget(): void
    {
        $html = MarkdownRenderer::convert('[link](/about)');

        $this->assertStringNotContainsString('target=', $html);
    }

    // ============================================================
    // HEADING PERMALINK TESTS
    // ============================================================

    public function testH1GetsId(): void
    {
        $html = MarkdownRenderer::convert('# My Heading');

        $this->assertStringContainsString('id="my-heading"', $html);
    }

    public function testH2GetsId(): void
    {
        $html = MarkdownRenderer::convert('## Sub Section');

        $this->assertStringContainsString('id="sub-section"', $html);
    }

    // ============================================================
    // GFM TABLE TESTS
    // ============================================================

    public function testGfmTableRendersCorrectly(): void
    {
        $markdown = "| Col A | Col B |\n| ----- | ----- |\n| val 1 | val 2 |";

        $html = MarkdownRenderer::convert($markdown);

        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringContainsString('<tbody>', $html);
        $this->assertStringContainsString('<th>', $html);
        $this->assertStringContainsString('<td>', $html);
    }

    // ============================================================
    // XSS PREVENTION TESTS
    // ============================================================

    public function testJavascriptUrlInLinkIsBlocked(): void
    {
        $html = MarkdownRenderer::convert('[click](javascript:alert(1))');

        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function testDataUrlInImageIsBlocked(): void
    {
        $html = MarkdownRenderer::convert('![img](data:image/png;base64,abc)');

        $this->assertStringNotContainsString('data:', $html);
    }

    public function testMixedCaseDataUrlInImageIsBlocked(): void
    {
        $html = MarkdownRenderer::convert('![img](dAtA:image/png;base64,abc)');

        $this->assertStringNotContainsString('data:', strtolower($html));
    }

    public function testVbscriptUrlInLinkIsBlocked(): void
    {
        $html = MarkdownRenderer::convert('[click](vbscript:msgbox(1))');

        $this->assertStringNotContainsString('vbscript:', $html);
    }

    public function testDataUrlInLinkHrefIsBlocked(): void
    {
        $html = MarkdownRenderer::convert('[click](data:text/html,<h1>XSS</h1>)');

        $this->assertStringNotContainsString('data:', $html);
    }

    public function testRawHtmlScriptTagIsStripped(): void
    {
        $html = MarkdownRenderer::convert('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
    }

    // ============================================================
    // URL RESOLUTION TESTS
    // ============================================================

    public function testRelativeImageUrlResolvedWithBaseUrl(): void
    {
        $html = MarkdownRenderer::convert('![img](guides/img.png)', 'https://example.com/');

        $this->assertStringContainsString('src="https://example.com/docs/guides/img.png"', $html);
    }

    public function testRootRelativeImageUrlResolvedWithBaseUrl(): void
    {
        $html = MarkdownRenderer::convert('![img](/foo/img.png)', 'https://example.com/');

        $this->assertStringContainsString('src="https://example.com/foo/img.png"', $html);
    }

    public function testExternalImageUrlUnchangedWithBaseUrl(): void
    {
        $html = MarkdownRenderer::convert('![img](https://cdn.example.com/img.png)', 'https://example.com/');

        $this->assertStringContainsString('src="https://cdn.example.com/img.png"', $html);
    }

    // ============================================================
    // IMG-FLUID CLASS TESTS
    // ============================================================

    public function testImageGetsImgFluidClass(): void
    {
        $html = MarkdownRenderer::convert('![alt](https://example.com/img.png)');

        $this->assertStringContainsString('class="img-fluid"', $html);
    }

    public function testMultipleImagesAllGetImgFluidClass(): void
    {
        $html = MarkdownRenderer::convert("![one](https://example.com/1.png)\n\n![two](https://example.com/2.png)");

        $this->assertSame(2, substr_count($html, 'class="img-fluid"'));
    }

    // ============================================================
    // BASIC MARKDOWN CONVERSION TESTS
    // ============================================================

    public function testBoldRenders(): void
    {
        $html = MarkdownRenderer::convert('**bold**');

        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function testItalicRenders(): void
    {
        $html = MarkdownRenderer::convert('*italic*');

        $this->assertStringContainsString('<em>italic</em>', $html);
    }

    public function testInlineCodeRenders(): void
    {
        $html = MarkdownRenderer::convert('`code`');

        $this->assertStringContainsString('<code>code</code>', $html);
    }

    public function testBulletListRenders(): void
    {
        $html = MarkdownRenderer::convert('- item');

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>item</li>', $html);
    }

    public function testFencedCodeBlockRenders(): void
    {
        $html = MarkdownRenderer::convert("```\nsome code\n```");

        $this->assertStringContainsString('<pre>', $html);
        $this->assertStringContainsString('<code>', $html);
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        $html = MarkdownRenderer::convert('');

        $this->assertSame('', trim($html));
    }

    // ============================================================
    // PRODUCTION-FORMAT BASEURL TESTS (root-relative, as used in production)
    // ============================================================

    public function testRelativeImageResolvedWithRootRelativeBaseUrl(): void
    {
        $html = MarkdownRenderer::convert('![img](guides/img.png)', '/elan-registry/');

        $this->assertStringContainsString('src="/elan-registry/docs/guides/img.png"', $html);
    }

    public function testInternalDocLinkWithRootRelativeBaseUrl(): void
    {
        // .md files use server-root-relative links like /docs/guide-viewer.php?doc=...
        // The resolver prepends $baseUrl so the link works from a subdirectory install.
        $html = MarkdownRenderer::convert('[Guide](/docs/guide-viewer.php?doc=ADD_CAR_GUIDE.md)', '/elan-registry/');

        $this->assertStringContainsString('href="/elan-registry/docs/guide-viewer.php?doc=ADD_CAR_GUIDE.md"', $html);
    }

    public function testRelativeLinkHrefResolvedWithBaseUrl(): void
    {
        $html = MarkdownRenderer::convert('[Guide](guides/ADD_CAR_GUIDE.md)', '/elan-registry/');

        $this->assertStringContainsString('href="/elan-registry/docs/guides/ADD_CAR_GUIDE.md"', $html);
    }

    public function testHeadingWithSpecialCharsGetsSlugifiedId(): void
    {
        $html = MarkdownRenderer::convert('# Hello, World! (2024)');

        $this->assertStringContainsString('id="hello-world-2024"', $html);
    }
}
