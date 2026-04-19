<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * EmailTemplate class tests
 *
 * Tests email template rendering with focus on XSS prevention
 * and proper HTML structure.
 *
 * @group fast
 */
final class EmailTemplateTest extends TestCase
{
    private EmailTemplate $template;

    protected function setUp(): void
    {
        $this->template = new EmailTemplate();
    }

    // ============================================================
    // XSS PREVENTION TESTS (CRITICAL)
    // ============================================================

    public function testCreateDetailRowEscapesHtmlInValue(): void
    {
        $html = $this->template->createDetailRow('Label', '<script>alert("XSS")</script>');

        // Script tag should be escaped
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testCreateDetailRowEscapesQuotes(): void
    {
        $html = $this->template->createDetailRow('Name', 'Test "value" with \'quotes\'');

        // Quotes should be escaped
        $this->assertStringContainsString('&quot;', $html);
    }

    public function testCreateDetailRowPreservesLabel(): void
    {
        $html = $this->template->createDetailRow('Owner Name', 'John Doe');

        $this->assertStringContainsString('Owner Name:', $html);
        $this->assertStringContainsString('John Doe', $html);
    }

    // ============================================================
    // RENDER TESTS
    // ============================================================

    public function testRenderReturnsCompleteHtmlDocument(): void
    {
        $html = $this->template->render(
            'Test Subject',
            'Test Subtitle',
            '<p>Test content</p>'
        );

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testRenderIncludesSubjectInTitle(): void
    {
        $html = $this->template->render(
            'My Email Subject',
            'Subtitle',
            'Content'
        );

        $this->assertStringContainsString('<title>Lotus Elan Registry - My Email Subject</title>', $html);
    }

    public function testRenderIncludesSubtitleInHeader(): void
    {
        $html = $this->template->render(
            'Subject',
            'Owner to Owner Message',
            'Content'
        );

        $this->assertStringContainsString('Owner to Owner Message', $html);
    }

    public function testRenderIncludesContent(): void
    {
        $html = $this->template->render(
            'Subject',
            'Subtitle',
            '<p>This is the main content</p>'
        );

        $this->assertStringContainsString('<p>This is the main content</p>', $html);
    }

    public function testRenderIncludesDefaultFooterText(): void
    {
        $html = $this->template->render(
            'Subject',
            'Subtitle',
            'Content'
        );

        $this->assertStringContainsString('automated message from the registry', $html);
    }

    public function testRenderUsesCustomFooterText(): void
    {
        $html = $this->template->render(
            'Subject',
            'Subtitle',
            'Content',
            ['footer_text' => 'Custom footer message']
        );

        $this->assertStringContainsString('Custom footer message', $html);
    }

    public function testRenderIncludesRegistryBranding(): void
    {
        $html = $this->template->render('Subject', 'Subtitle', 'Content');

        $this->assertStringContainsString('Lotus Elan Registry', $html);
        $this->assertStringContainsString('Colin Chapman', $html);
    }

    // ============================================================
    // MESSAGE BOX TESTS
    // ============================================================

    public function testCreateMessageBoxDefaultStyle(): void
    {
        $html = $this->template->createMessageBox('Title', 'Content');

        $this->assertStringContainsString('content-box', $html);
        $this->assertStringContainsString('<h3 style=', $html);
        $this->assertStringContainsString('>Title</h3>', $html);
        $this->assertStringContainsString('Content', $html);
    }

    public function testCreateMessageBoxMessageStyle(): void
    {
        $html = $this->template->createMessageBox('Title', 'Content', 'message');

        $this->assertStringContainsString('content-box-message', $html);
    }

    public function testCreateMessageBoxAlertStyle(): void
    {
        $html = $this->template->createMessageBox('Title', 'Content', 'alert');

        $this->assertStringContainsString('content-box-alert', $html);
    }

    public function testCreateMessageBoxSuccessStyle(): void
    {
        $html = $this->template->createMessageBox('Title', 'Content', 'success');

        $this->assertStringContainsString('content-box-success', $html);
    }

    // ============================================================
    // BUTTON TESTS
    // ============================================================

    public function testCreateButtonPrimaryStyle(): void
    {
        $html = $this->template->createButton('Click Me', 'https://example.com');

        $this->assertStringContainsString('btn btn-primary', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('Click Me', $html);
    }

    public function testCreateButtonSecondaryStyle(): void
    {
        $html = $this->template->createButton('Cancel', '/cancel', 'secondary');

        $this->assertStringContainsString('btn btn-secondary', $html);
    }

    public function testCreateButtonSuccessStyle(): void
    {
        $html = $this->template->createButton('Approve', '/approve', 'success');

        $this->assertStringContainsString('btn btn-success', $html);
    }

    public function testCreateButtonDangerStyle(): void
    {
        $html = $this->template->createButton('Delete', '/delete', 'danger');

        $this->assertStringContainsString('btn btn-danger', $html);
    }

    // ============================================================
    // RESPONSIVE DESIGN TESTS
    // ============================================================

    public function testRenderIncludesViewportMeta(): void
    {
        $html = $this->template->render('Subject', 'Subtitle', 'Content');

        $this->assertStringContainsString('viewport', $html);
        $this->assertStringContainsString('width=device-width', $html);
    }

    public function testRenderIncludesResponsiveStyles(): void
    {
        $html = $this->template->render('Subject', 'Subtitle', 'Content');

        $this->assertStringContainsString('@media', $html);
        $this->assertStringContainsString('max-width: 600px', $html);
    }

    // ============================================================
    // EMAIL CLIENT COMPATIBILITY TESTS
    // ============================================================

    public function testRenderUsesTableBasedOuterWrapper(): void
    {
        $html = $this->template->render('Subject', 'Subtitle', 'Content');
        $this->assertStringContainsString('<table', $html);
        $this->assertStringNotContainsString('class="email-container"', $html);
    }

    public function testCreateDetailRowUsesTableLayout(): void
    {
        $html = $this->template->createDetailRow('Label', 'Value');
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<td', $html);
        $this->assertStringNotContainsString('display: flex', $html);
        $this->assertStringNotContainsString('display:flex', $html);
    }

    public function testCreateButtonHasInlineBackgroundColor(): void
    {
        $cases = [
            ['primary',   '#029acf'],
            ['secondary', '#6c757d'],
            ['success',   '#28a745'],
            ['danger',    '#dc3545'],
        ];
        foreach ($cases as [$style, $expectedColor]) {
            $html = $this->template->createButton('Click', 'https://example.com', $style);
            $this->assertStringContainsString("background-color: {$expectedColor}", $html);
        }
    }

    public function testCreateMessageBoxHasInlineStyles(): void
    {
        $cases = [
            ['default',  '#029acf'],
            ['alert',    '#dc3545'],
            ['message',  '#469408'],
            ['success',  '#28a745'],
        ];
        foreach ($cases as [$style, $expectedColor]) {
            $html = $this->template->createMessageBox('Title', 'Content', $style);
            $this->assertStringContainsString('background-color: #f8f9fa', $html);
            $this->assertStringContainsString("border: 2px solid {$expectedColor}", $html);
        }
    }

    public function testRenderHeaderHasInlineBackgroundColor(): void
    {
        $html = $this->template->render('Subject', 'Subtitle', 'Content');
        $this->assertStringContainsString('background-color: #029acf', $html);
    }

    public function testCreateButtonEscapesHtmlInText(): void
    {
        $html = $this->template->createButton('<script>alert("XSS")</script>', 'https://example.com');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testCreateMessageBoxEscapesHtmlInTitle(): void
    {
        $html = $this->template->createMessageBox('<b>Bold</b>', 'Content');
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
    }

    public function testCreateDetailRowEscapesHtmlInLabel(): void
    {
        $html = $this->template->createDetailRow('<b>Label</b>', 'Value');
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
    }

    public function testCreateButtonUnknownStyleFallsBackToPrimary(): void
    {
        $html = $this->template->createButton('Click', 'https://example.com', 'nonexistent');
        $this->assertStringContainsString('background-color: #029acf', $html);
    }
}
