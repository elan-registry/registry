<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ElanRegistry\Documentation\MarkdownParser;

/**
 * MarkdownParser class tests
 *
 * CRITICAL SECURITY TESTS for XSS prevention in markdown conversion.
 * Focus: URL validation, script injection prevention, safe output.
 *
 * @group fast
 * @group security
 */
final class MarkdownParserTest extends TestCase
{
    // ============================================================
    // XSS PREVENTION TESTS (CRITICAL)
    // ============================================================

    public function testBlocksJavascriptUrlInLinks(): void
    {
        $markdown = '[Click me](javascript:alert("XSS"))';

        $html = MarkdownParser::toHtml($markdown);

        // Should NOT contain the javascript: URL
        $this->assertStringNotContainsString('javascript:', $html);
        // Should still show the link text
        $this->assertStringContainsString('Click me', $html);
    }

    public function testBlocksJavascriptUrlInImages(): void
    {
        $markdown = '![alt](javascript:alert("XSS"))';

        $html = MarkdownParser::toHtml($markdown);

        $this->assertStringNotContainsString('javascript:', $html);
        // Alt text should be preserved
        $this->assertStringContainsString('alt', $html);
    }

    public function testBlocksDataUrlInLinks(): void
    {
        $markdown = '[Click](data:text/html,<script>alert("XSS")</script>)';

        $html = MarkdownParser::toHtml($markdown);

        // Data URLs should be blocked
        $this->assertStringNotContainsString('data:', $html);
    }

    public function testBlocksVbscriptUrl(): void
    {
        $markdown = '[Click](vbscript:msgbox("XSS"))';

        $html = MarkdownParser::toHtml($markdown);

        $this->assertStringNotContainsString('vbscript:', $html);
    }

    public function testAllowsHttpsUrls(): void
    {
        $markdown = '[Secure Link](https://example.com)';

        $html = MarkdownParser::toHtml($markdown);

        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('Secure Link', $html);
    }

    public function testAllowsHttpUrls(): void
    {
        $markdown = '[Link](http://example.com)';

        $html = MarkdownParser::toHtml($markdown);

        $this->assertStringContainsString('href="http://example.com"', $html);
    }

    public function testAllowsMailtoUrls(): void
    {
        $markdown = '[Email](mailto:test@example.com)';

        $html = MarkdownParser::toHtml($markdown);

        $this->assertStringContainsString('href="mailto:test@example.com"', $html);
    }

    public function testAllowsAnchorLinks(): void
    {
        $markdown = '[Jump to section](#my-section)';

        $html = MarkdownParser::toHtml($markdown);

        $this->assertStringContainsString('href="#my-section"', $html);
    }

    public function testAllowsRelativePaths(): void
    {
        $markdown = '[Docs](/docs/guide.pdf)';

        $html = MarkdownParser::toHtml($markdown);

        $this->assertStringContainsString('href="/docs/guide.pdf"', $html);
    }

    public function testExternalLinksOpenInNewTab(): void
    {
        $markdown = '[External](https://example.com)';

        $html = MarkdownParser::toHtml($markdown);

        $this->assertStringContainsString('target="_blank"', $html);
    }

    public function testAnchorLinksDoNotOpenInNewTab(): void
    {
        $markdown = '[Section](#section)';

        $html = MarkdownParser::toHtml($markdown);

        $this->assertStringNotContainsString('target="_blank"', $html);
    }

    // ============================================================
    // HTML SANITIZATION TESTS
    // ============================================================

    public function testSanitizeHtmlStripsScriptTags(): void
    {
        $html = '<p>Safe</p><script>alert("XSS")</script>';

        $sanitized = MarkdownParser::sanitizeHtml($html);

        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringContainsString('<p>Safe</p>', $sanitized);
    }

    public function testSanitizeHtmlAllowsBasicTags(): void
    {
        $html = '<h1>Title</h1><p>Text</p><strong>Bold</strong><em>Italic</em>';

        $sanitized = MarkdownParser::sanitizeHtml($html);

        $this->assertStringContainsString('<h1>', $sanitized);
        $this->assertStringContainsString('<p>', $sanitized);
        $this->assertStringContainsString('<strong>', $sanitized);
        $this->assertStringContainsString('<em>', $sanitized);
    }

    public function testSanitizeHtmlAllowsLists(): void
    {
        $html = '<ul><li>Item</li></ul><ol><li>Numbered</li></ol>';

        $sanitized = MarkdownParser::sanitizeHtml($html);

        $this->assertStringContainsString('<ul>', $sanitized);
        $this->assertStringContainsString('<ol>', $sanitized);
        $this->assertStringContainsString('<li>', $sanitized);
    }

    public function testSanitizeHtmlAllowsImages(): void
    {
        $html = '<img src="test.jpg" alt="Test">';

        $sanitized = MarkdownParser::sanitizeHtml($html);

        $this->assertStringContainsString('<img', $sanitized);
    }

    public function testSanitizeHtmlStripsEventHandlerAttributes(): void
    {
        $html = '<a href="https://example.com" onclick="alert(1)">link</a>'
              . '<img src="test.jpg" onerror="alert(1)">'
              . '<p onload="evil()">text</p>';

        $sanitized = MarkdownParser::sanitizeHtml($html);

        $this->assertStringNotContainsString('onclick', $sanitized);
        $this->assertStringNotContainsString('onerror', $sanitized);
        $this->assertStringNotContainsString('onload', $sanitized);
        $this->assertStringContainsString('href="https://example.com"', $sanitized);
    }

    public function testSanitizeHtmlBlocksJavascriptHref(): void
    {
        $html = '<a href="javascript:alert(1)">click</a>';

        $sanitized = MarkdownParser::sanitizeHtml($html);

        $this->assertStringNotContainsString('javascript:', $sanitized);
        $this->assertStringContainsString('<a', $sanitized);
    }

    public function testSanitizeHtmlBlocksDataSrc(): void
    {
        $html = '<img src="data:image/png;base64,abc123" alt="img">';

        $sanitized = MarkdownParser::sanitizeHtml($html);

        $this->assertStringNotContainsString('data:', $sanitized);
        $this->assertStringContainsString('alt="img"', $sanitized);
    }

    public function testSanitizeHtmlPreservesAllowedAttributes(): void
    {
        $html = '<h2 id="section-title">Title</h2>'
              . '<a href="https://example.com" target="_blank" rel="noopener noreferrer">link</a>'
              . '<img src="photo.jpg" alt="Photo">';

        $sanitized = MarkdownParser::sanitizeHtml($html);

        $this->assertStringContainsString('id="section-title"', $sanitized);
        $this->assertStringContainsString('href="https://example.com"', $sanitized);
        $this->assertStringContainsString('target="_blank"', $sanitized);
        $this->assertStringContainsString('src="photo.jpg"', $sanitized);
        $this->assertStringContainsString('alt="Photo"', $sanitized);
    }

    public function testSanitizeHtmlStripsStyleAttribute(): void
    {
        $html = '<p style="color:red;expression(alert(1))">text</p>';

        $sanitized = MarkdownParser::sanitizeHtml($html);

        $this->assertStringNotContainsString('style=', $sanitized);
        $this->assertStringContainsString('<p>', $sanitized);
    }

    public function testSanitizeHtmlBlocksControlCharacterUriBypass(): void
    {
        // Tab and null byte before "script" must not bypass javascript: detection
        $html = '<a href="java' . "\x09" . 'script:alert(1)">click</a>'
              . '<img src="da' . "\x00" . 'ta:image/png;base64,abc" alt="img">';

        $sanitized = MarkdownParser::sanitizeHtml($html);

        $this->assertStringNotContainsString('javascript:', $sanitized);
        $this->assertStringNotContainsString('data:', $sanitized);
    }

    public function testSanitizeHtmlBlocksUpperCaseSchemes(): void
    {
        $html = '<a href="JAVASCRIPT:alert(1)">click</a>'
              . '<img src="DATA:image/svg+xml,<svg/>" alt="img">';

        $sanitized = MarkdownParser::sanitizeHtml($html);

        $this->assertStringNotContainsString('JAVASCRIPT:', $sanitized);
        $this->assertStringNotContainsString('DATA:', $sanitized);
    }

    public function testSanitizeHtmlBlocksVbscriptHref(): void
    {
        $html = '<a href="vbscript:msgbox(1)">click</a>';

        $sanitized = MarkdownParser::sanitizeHtml($html);

        $this->assertStringNotContainsString('vbscript:', $sanitized);
        $this->assertStringContainsString('<a', $sanitized);
    }

    // ============================================================
    // MARKDOWN CONVERSION TESTS
    // ============================================================

    public function testConvertsHeadersWithIds(): void
    {
        $markdown = "# Main Title\n## Sub Title";

        $html = MarkdownParser::toHtml($markdown);

        $this->assertStringContainsString('<h1 id="main-title">Main Title</h1>', $html);
        $this->assertStringContainsString('<h2 id="sub-title">Sub Title</h2>', $html);
    }

    public function testConvertsBoldText(): void
    {
        $markdown = 'This is **bold** text';

        $html = MarkdownParser::toHtml($markdown);

        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function testConvertsItalicText(): void
    {
        $markdown = 'This is *italic* text';

        $html = MarkdownParser::toHtml($markdown);

        $this->assertStringContainsString('<em>italic</em>', $html);
    }

    public function testConvertsInlineCode(): void
    {
        $markdown = 'Use `code` here';

        $html = MarkdownParser::toHtml($markdown);

        $this->assertStringContainsString('<code>code</code>', $html);
    }

    public function testConvertsBulletLists(): void
    {
        $markdown = "- Item 1\n- Item 2";

        $html = MarkdownParser::toHtml($markdown);

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>Item 1</li>', $html);
        $this->assertStringContainsString('<li>Item 2</li>', $html);
    }

    public function testConvertsNumberedLists(): void
    {
        $markdown = "1. First\n2. Second";

        $html = MarkdownParser::toHtml($markdown);

        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('<li>First</li>', $html);
    }

    public function testHeaderIdGenerationRemovesSpecialCharacters(): void
    {
        $markdown = "# Hello, World! (2024)";

        $html = MarkdownParser::toHtml($markdown);

        // ID should be lowercase, no special chars, hyphens for spaces
        $this->assertStringContainsString('id="hello-world-2024"', $html);
    }

    public function testEscapesHtmlInLinkText(): void
    {
        $markdown = '[<script>XSS</script>](https://example.com)';

        $html = MarkdownParser::toHtml($markdown);

        // Script tag in link text should be escaped
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testEscapesHtmlInImageAlt(): void
    {
        $markdown = '![<script>XSS</script>](https://example.com/img.jpg)';

        $html = MarkdownParser::toHtml($markdown);

        $this->assertStringNotContainsString('<script>', $html);
    }
}
