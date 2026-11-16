<?php

declare(strict_types=1);

use ElanRegistry\EmailTemplate;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * EmailTemplate class tests
 *
 * Tests email template rendering with focus on XSS prevention
 * and proper HTML structure.
 */
#[Group('fast')]
final class EmailTemplateTest extends TestCase
{
    private const array MEMBER_TO_OWNER_VARS = [
        'to'      => 'Jane Smith',
        'from'    => 'John Doe',
        'message' => 'Hi there.',
    ];

    private const array FEEDBACK_VARS = [
        'name'      => 'Alice Tester',
        'email'     => 'alice@example.com',
        'accountId' => '42',
        'comments'  => 'Great registry!',
    ];

    private const array VERIFY_VARS = [
        'fname'                => 'Bob',
        'email'                => 'bob@example.com',
        'vericode'             => 'CODE',
        'user_id'              => 7,
        'join_vericode_expiry' => 48,
    ];

    private const array VERIFY_NEW_VARS = [
        'fname'                => 'Carol',
        'email'                => 'carol@example.com',
        'vericode'             => 'NEWCODE77',
        'user_id'              => 12,
        'join_vericode_expiry' => 24,
    ];

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
    // createDetailRow() — $highlighted FLAG
    // ============================================================

    public function testCreateDetailRowHighlightedTrueAppliesBackgroundAndBorder(): void
    {
        $html = $this->template->createDetailRow('Label', 'Value', true);

        $this->assertStringContainsString('#FFF9E0', $html);
        $this->assertStringContainsString('#B8860B', $html);
    }

    public function testCreateDetailRowHighlightedFalseOmitsHighlightStyling(): void
    {
        $htmlExplicitFalse = $this->template->createDetailRow('Label', 'Value', false);
        $htmlOmitted = $this->template->createDetailRow('Label', 'Value');

        $this->assertStringNotContainsString('#FFF9E0', $htmlExplicitFalse);
        $this->assertStringNotContainsString('#B8860B', $htmlExplicitFalse);
        $this->assertStringNotContainsString('#FFF9E0', $htmlOmitted);
        $this->assertStringNotContainsString('#B8860B', $htmlOmitted);
        $this->assertSame($htmlOmitted, $htmlExplicitFalse);
    }

    public function testCreateDetailRowHighlightedTrueStillEscapesValueXss(): void
    {
        $html = $this->template->createDetailRow('Label', '<script>alert(1)</script>', true);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testCreateDetailRowHighlightedTrueStillEscapesLabelXss(): void
    {
        $html = $this->template->createDetailRow('<script>alert(1)</script>', 'Value', true);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testCreateDetailRowHighlightedTrueEscapesQuotesInValue(): void
    {
        $html = $this->template->createDetailRow('Name', 'Test "value" with \'quotes\'', true);

        $this->assertStringContainsString('&quot;', $html);
    }

    public function testCreateDetailRowHighlightedUsesTableLayout(): void
    {
        $html = $this->template->createDetailRow('Label', 'Value', true);

        $this->assertStringContainsString('<table role="presentation"', $html);
    }

    public function testCreateDetailRowDefaultHighlightedIsFalseAndUnchanged(): void
    {
        $html = $this->template->createDetailRow('Owner Name', 'John Doe');

        $expected = "
        <table role=\"presentation\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"margin-bottom: 10px;\">
            <tr>
                <td style=\"font-weight: bold; width: 120px; color: #469408; vertical-align: top; padding-right: 10px;\">Owner Name:</td>
                <td style=\"vertical-align: top;\">John Doe</td>
            </tr>
        </table>";

        $this->assertSame($expected, $html);
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

    public function testRenderUsesGlobalSettingsSiteNameWhenSet(): void
    {
        global $settings;
        $previous = $settings ?? null;

        try {
            $settings = (object) ['site_name' => 'Custom Registry Name'];
            $html = $this->template->render('My Email Subject', 'Subtitle', 'Content');

            $this->assertStringContainsString('<title>Custom Registry Name - My Email Subject</title>', $html);
            $this->assertStringNotContainsString('Lotus Elan Registry', $html);
        } finally {
            $settings = $previous;
        }
    }

    public function testRenderEscapesGlobalSettingsSiteName(): void
    {
        global $settings;
        $previous = $settings ?? null;

        try {
            $settings = (object) ['site_name' => '<script>alert(1)</script>'];
            $html = $this->template->render('Subject', 'Subtitle', 'Content');

            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        } finally {
            $settings = $previous;
        }
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

    /**
     * @return array<string, array{string|null, list<string>}>
     */
    public static function messageBoxStyleProvider(): array
    {
        return [
            'default style' => [null, ['content-box', '<h3 style=', '>Title</h3>', 'Content']],
            'message style' => ['message', ['content-box-message']],
            'alert style' => ['alert', ['content-box-alert']],
            'success style' => ['success', ['content-box-success']],
        ];
    }

    /**
     * @param string|null  $style    Box style, or null for the default
     * @param list<string> $contains Fragments the HTML must contain
     */
    #[DataProvider('messageBoxStyleProvider')]
    public function testCreateMessageBoxAppliesStyle(?string $style, array $contains): void
    {
        $html = $style === null
            ? $this->template->createMessageBox('Title', 'Content')
            : $this->template->createMessageBox('Title', 'Content', $style);

        $this->assertMarkup($html, $contains);
    }

    // ============================================================
    // BUTTON TESTS
    // ============================================================

    /**
     * @return array<string, array{string, string, string|null, list<string>}>
     */
    public static function buttonStyleProvider(): array
    {
        return [
            'primary style' => ['Click Me', 'https://example.com', null, ['btn btn-primary', 'href="https://example.com"', 'Click Me']],
            'secondary style' => ['Cancel', '/cancel', 'secondary', ['btn btn-secondary']],
            'success style' => ['Approve', '/approve', 'success', ['btn btn-success']],
            'danger style' => ['Delete', '/delete', 'danger', ['btn btn-danger']],
        ];
    }

    /**
     * @param string       $text     Button label
     * @param string       $url      Button link
     * @param string|null  $style    Button style, or null for the default
     * @param list<string> $contains Fragments the HTML must contain
     */
    #[DataProvider('buttonStyleProvider')]
    public function testCreateButtonAppliesStyle(string $text, string $url, ?string $style, array $contains): void
    {
        $html = $style === null
            ? $this->template->createButton($text, $url)
            : $this->template->createButton($text, $url, $style);

        $this->assertMarkup($html, $contains);
    }

    // ============================================================
    // createButtonRow() TESTS
    // ============================================================

    public function testCreateButtonRowRendersTwoButtons(): void
    {
        $html = $this->template->createButtonRow([
            ['label' => 'Approve', 'url' => 'https://example.com/approve'],
            ['label' => 'Deny', 'url' => 'https://example.com/deny'],
        ]);

        $this->assertStringContainsString('Approve', $html);
        $this->assertStringContainsString('href="https://example.com/approve"', $html);
        $this->assertStringContainsString('Deny', $html);
        $this->assertStringContainsString('href="https://example.com/deny"', $html);
    }

    public function testCreateButtonRowRendersThreeOrMoreButtons(): void
    {
        $html = $this->template->createButtonRow([
            ['label' => 'One', 'url' => 'https://example.com/one'],
            ['label' => 'Two', 'url' => 'https://example.com/two'],
            ['label' => 'Three', 'url' => 'https://example.com/three'],
        ]);

        $this->assertStringContainsString('One', $html);
        $this->assertStringContainsString('href="https://example.com/one"', $html);
        $this->assertStringContainsString('Two', $html);
        $this->assertStringContainsString('href="https://example.com/two"', $html);
        $this->assertStringContainsString('Three', $html);
        $this->assertStringContainsString('href="https://example.com/three"', $html);
    }

    public function testCreateButtonRowAppliesDefaultPrimaryStyleWhenStyleOmitted(): void
    {
        $html = $this->template->createButtonRow([
            ['label' => 'One', 'url' => 'https://example.com/one'],
            ['label' => 'Two', 'url' => 'https://example.com/two'],
        ]);

        $this->assertStringContainsString('btn btn-primary', $html);
    }

    public function testCreateButtonRowAppliesPerButtonStyle(): void
    {
        $styles = ['primary', 'secondary', 'success', 'danger'];
        $buttons = [];
        foreach ($styles as $style) {
            $buttons[] = ['label' => ucfirst($style), 'url' => "https://example.com/{$style}", 'style' => $style];
        }

        $html = $this->template->createButtonRow($buttons);

        foreach ($styles as $style) {
            // The color for a given style must match createButton()'s own output
            // for that style, rather than a redundantly hardcoded hex value.
            $soloButtonHtml = $this->template->createButton('X', 'https://example.com', $style);
            preg_match('/background-color:\s*(#[0-9A-Fa-f]{6})/', $soloButtonHtml, $matches);
            $this->assertNotEmpty($matches, "Could not extract color from createButton() for style {$style}");
            $expectedColor = $matches[1];

            $this->assertStringContainsString("btn btn-{$style}", $html);
            $this->assertStringContainsString("background-color: {$expectedColor}", $html);
        }
    }

    public function testCreateButtonRowUnknownStyleFallsBackToPrimary(): void
    {
        $html = $this->template->createButtonRow([
            ['label' => 'One', 'url' => 'https://example.com/one', 'style' => 'nonexistent'],
            ['label' => 'Two', 'url' => 'https://example.com/two', 'style' => 'danger'],
        ]);

        $this->assertSame(
            1,
            substr_count($html, 'background-color: #00563F'),
            'The unknown-style button must fall back to the primary color (#00563F), and only that button — '
            . 'the second button uses danger (#dc3545), so a fallback failure could not hide behind it'
        );
    }

    public function testCreateButtonRowEscapesHtmlInLabelAndUrl(): void
    {
        $html = $this->template->createButtonRow([
            ['label' => '<script>alert(1)</script>', 'url' => 'https://example.com/"><script>alert(2)</script>'],
            ['label' => 'Safe', 'url' => 'https://example.com/safe'],
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testCreateButtonRowEscapesStyleInClassAttribute(): void
    {
        $html = $this->template->createButtonRow([
            ['label' => 'One', 'url' => 'https://example.com/one', 'style' => 'x" onload="evil'],
            ['label' => 'Two', 'url' => 'https://example.com/two'],
        ]);

        $this->assertStringNotContainsString('onload="evil"', $html);
        $this->assertStringNotContainsString('class="btn btn-x" onload="evil"', $html);
    }

    public function testCreateButtonRowThrowsWhenButtonMissingLabel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->template->createButtonRow([
            ['url' => 'https://example.com/one'],
            ['label' => 'Two', 'url' => 'https://example.com/two'],
        ]);
    }

    public function testCreateButtonRowThrowsWhenButtonMissingUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->template->createButtonRow([
            ['label' => 'One'],
            ['label' => 'Two', 'url' => 'https://example.com/two'],
        ]);
    }

    public function testCreateButtonRowThrowsWhenLabelIsNotAString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->template->createButtonRow([
            ['label' => 123, 'url' => 'https://example.com/one'],
            ['label' => 'Two', 'url' => 'https://example.com/two'],
        ]);
    }

    public function testCreateButtonRowThrowsWhenUrlIsNotAString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->template->createButtonRow([
            ['label' => 'One', 'url' => 123],
            ['label' => 'Two', 'url' => 'https://example.com/two'],
        ]);
    }

    /**
     * Guards against createButtonRow() letting a non-string 'style' reach
     * getButtonInlineStyles()'s typed parameter as an uncaught \TypeError
     * instead of the method's own documented \InvalidArgumentException.
     */
    public function testCreateButtonRowThrowsWhenStyleIsNotAString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->template->createButtonRow([
            ['label' => 'One', 'url' => 'https://example.com/one', 'style' => 5],
            ['label' => 'Two', 'url' => 'https://example.com/two'],
        ]);
    }

    public function testCreateButtonRowIsCenteredTableBasedBlock(): void
    {
        $html = $this->template->createButtonRow([
            ['label' => 'One', 'url' => 'https://example.com/one'],
            ['label' => 'Two', 'url' => 'https://example.com/two'],
        ]);

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('text-align: center', $html);
    }

    public function testCreateButtonRowCellsUseTheClassStyledByTheHeadLevelMediaRule(): void
    {
        $html = $this->template->createButtonRow([
            ['label' => 'One', 'url' => 'https://example.com/one'],
            ['label' => 'Two', 'url' => 'https://example.com/two'],
        ]);

        // createButtonRow()'s own fragment intentionally does NOT embed an
        // @media block — the .btn-row-cell stacking rule lives in
        // getBaseTemplate()'s head-level <style> instead, since some mail
        // clients strip <style> tags found outside <head>. This test asserts
        // the fragment uses the class that rule targets; the rule's actual
        // presence and effect is verified via render() below and by the
        // Playwright spec in tests/playwright/local/.
        $this->assertStringContainsString('btn-row-cell', $html);
        $this->assertStringNotContainsString('@media', $html);
    }

    public function testRenderIncludesNarrowViewportStackingCssForButtonRow(): void
    {
        $buttonRow = $this->template->createButtonRow([
            ['label' => 'One', 'url' => 'https://example.com/one'],
            ['label' => 'Two', 'url' => 'https://example.com/two'],
        ]);
        $html = $this->template->render('Subject', 'Subtitle', $buttonRow);

        $this->assertStringContainsString('@media', $html);
        $this->assertStringContainsString('max-width: 600px', $html);
        $this->assertStringContainsString('.btn-row-cell', $html);
    }

    public function testCreateButtonRowThrowsWhenGivenEmptyArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'createButtonRow() requires at least 2 buttons; use createButton() for a single button.'
        );

        $this->template->createButtonRow([]);
    }

    public function testCreateButtonRowThrowsWhenGivenExactlyOneButton(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'createButtonRow() requires at least 2 buttons; use createButton() for a single button.'
        );

        $this->template->createButtonRow([
            ['label' => 'One', 'url' => 'https://example.com/one'],
        ]);
    }

    public function testCreateButtonRowDoesNotAlterCreateButtonOutput(): void
    {
        $before = $this->template->createButton('Click Me', 'https://example.com');

        $this->template->createButtonRow([
            ['label' => 'One', 'url' => 'https://example.com/one'],
            ['label' => 'Two', 'url' => 'https://example.com/two'],
        ]);

        $after = $this->template->createButton('Click Me', 'https://example.com');

        $this->assertSame($before, $after);
    }

    // ============================================================
    // createRawDetailRow() TESTS
    // ============================================================

    public function testCreateRawDetailRowEscapesLabel(): void
    {
        $html = $this->template->createRawDetailRow('<script>alert(1)</script>', '<strong>Value</strong>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testCreateRawDetailRowDoesNotEscapeTrustedHtml(): void
    {
        $html = $this->template->createRawDetailRow('Label', '<strong>Bold</strong>');

        $this->assertStringContainsString('<strong>Bold</strong>', $html);
    }

    public function testCreateRawDetailRowRendersScriptTagVerbatimWhenPassedAsTrustedHtml(): void
    {
        // INTENTIONAL, by contract: createRawDetailRow() treats $trustedHtml as
        // pre-composed, caller-trusted HTML and does NOT escape it (see the
        // escaping contract in the EmailTemplate class docblock). This is the
        // inverse of every other XSS test in this suite — it is not a missed
        // escaping bug. Callers are responsible for escaping any user-supplied
        // data before it reaches this method.
        $html = $this->template->createRawDetailRow('Label', '<script>alert(1)</script>');

        $this->assertStringContainsString('<script>alert(1)</script>', $html);
    }

    public function testCreateRawDetailRowUsesTableLayout(): void
    {
        $html = $this->template->createRawDetailRow('Label', '<strong>Value</strong>');

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<td', $html);
    }

    public function testCreateRawDetailRowPreservesLabelWhenNoSpecialChars(): void
    {
        $html = $this->template->createRawDetailRow('Owner Name', '<strong>John Doe</strong>');

        $this->assertStringContainsString('Owner Name:', $html);
        $this->assertStringContainsString('<strong>John Doe</strong>', $html);
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
            ['primary',   '#00563F'],
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
            ['default',  '#00563F'],
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
        $this->assertStringContainsString('background-color: #00563F', $html);
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
        $this->assertStringContainsString('background-color: #00563F', $html);
    }

    // ============================================================
    // MIGRATED TEMPLATE VIEW FILE TESTS
    //
    // These tests exercise the 5 email template view files migrated
    // in issue #324.  Each view file uses:
    //
    //   require_once $abs_us_root . $us_url_root . 'usersc/classes/EmailTemplate.php';
    //
    // Because the unit-test bootstrap loads EmailTemplate via the
    // custom autoloader before these tests run, the require_once
    // inside each view is a no-op (PHP's require_once skips files
    // that are already included).  We set $abs_us_root and
    // $us_url_root to point at the real project root so the path
    // resolves correctly even as a no-op.
    //
    // getBaseUrl() is already mocked in bootstrap-unit.php and
    // returns 'https://test.elanregistry.org'.
    // ============================================================

    /**
     * Resolve the absolute filesystem path to a view file and return
     * the two path-segment variables the templates expect.
     *
     * $abs_us_root . $us_url_root . 'usersc/classes/EmailTemplate.php'
     * must resolve to the real file so require_once doesn't fatal even
     * if the class isn't yet loaded.  With $abs_us_root set to the
     * project root (trailing slash) and $us_url_root = '' the
     * concatenation produces '<root>/usersc/classes/EmailTemplate.php'.
     *
     * @return array{abs_us_root: string, us_url_root: string, view_dir: string, usersc_view_dir: string}
     */
    private function getTemplatePathVars(): array
    {
        $projectRoot = dirname(__DIR__, 3); // tests/unit/classes → project root
        return [
            'abs_us_root'     => $projectRoot . '/',
            'us_url_root'     => '',
            'view_dir'        => $projectRoot . '/app/views/email/',
            'usersc_view_dir' => $projectRoot . '/usersc/views/',
        ];
    }

    /**
     * Absolute path to the UserSpice views directory, for upstream verify
     * templates that were not moved to app/views/email/
     * (`_email_template_verify.php` and `_email_template_verify_new.php`).
     */
    private function userscViewDir(): string
    {
        return $this->getTemplatePathVars()['usersc_view_dir'];
    }

    /**
     * Capture the output of a view file after setting the provided
     * variables in the local scope.  Returns the rendered HTML string.
     *
     * @param string               $filename  View filename (e.g. '_member_to_owner.php')
     * @param array<string, mixed> $vars      Variables to expose inside the view
     * @param string|null          $viewDir   Override the default app/views/email/ directory.
     *                                        Pass $this->userscViewDir() for UserSpice-owned templates.
     * @return string Rendered HTML output of the view file.
     */
    private function renderView(string $filename, array $vars, ?string $viewDir = null): string
    {
        $paths = $this->getTemplatePathVars();
        // These two must be set so the require_once inside the view resolves.
        $abs_us_root = $paths['abs_us_root']; // phpcs:ignore
        $us_url_root = $paths['us_url_root']; // phpcs:ignore

        // Expand caller-supplied variables into the local scope.
        extract($vars, EXTR_SKIP);

        ob_start();
        require ($viewDir ?? $paths['view_dir']) . $filename;
        return (string) ob_get_clean();
    }

    /**
     * Each row renders one view with its variables and lists the fragments
     * the output must and must not contain.
     *
     * @return array<string, array{string, array<string, mixed>, list<string>, list<string>}>
     */
    public static function viewMarkupProvider(): array
    {
        $xss = '<script>alert(\'xss\')</script>';
        $escaped = ['&lt;script&gt;'];
        $noScript = ['<script>'];
        $document = ['<!DOCTYPE html>', '</html>'];
        $car99S4 = ['id' => '99', 'year' => '1971', 'series' => 'S4', 'variant' => '', 'type' => '', 'chassis' => 'ELAN/6/1234'];
        $verifyNewUrl = 'users/verify_new.php?vericode=NEWCODE77';

        return [
            // _member_to_owner.php
            'member_to_owner renders without error' => [
                '_member_to_owner.php',
                [...self::MEMBER_TO_OWNER_VARS, 'message' => 'Hi, interested in your Elan.'],
                $document, [],
            ],
            'member_to_owner contains recipient name' => [
                '_member_to_owner.php', self::MEMBER_TO_OWNER_VARS, ['Jane Smith'], [],
            ],
            'member_to_owner contains reply instruction' => [
                '_member_to_owner.php', self::MEMBER_TO_OWNER_VARS, ['simply reply to this email'], [],
            ],
            'member_to_owner contains sender name' => [
                '_member_to_owner.php', self::MEMBER_TO_OWNER_VARS, ['John Doe'], [],
            ],
            'member_to_owner escapes xss in recipient name' => [
                '_member_to_owner.php',
                [...self::MEMBER_TO_OWNER_VARS, 'to' => $xss, 'message' => 'Hi.'],
                $escaped, $noScript,
            ],
            'member_to_owner escapes xss in sender name' => [
                '_member_to_owner.php',
                [...self::MEMBER_TO_OWNER_VARS, 'from' => $xss, 'message' => 'Hi.'],
                $escaped, $noScript,
            ],
            'member_to_owner escapes xss in message' => [
                '_member_to_owner.php',
                [...self::MEMBER_TO_OWNER_VARS, 'message' => $xss],
                $escaped, $noScript,
            ],

            // _feedback.php
            'feedback renders without error' => [
                '_feedback.php', self::FEEDBACK_VARS, $document, [],
            ],
            'feedback contains owner details' => [
                '_feedback.php', self::FEEDBACK_VARS, ['Alice Tester', 'alice@example.com', '42'], [],
            ],
            'feedback contains comments' => [
                '_feedback.php', self::FEEDBACK_VARS, ['Great registry!'], [],
            ],
            'feedback contains user feedback subtitle' => [
                '_feedback.php',
                [...self::FEEDBACK_VARS, 'accountId' => '7', 'comments' => 'Some comment.'],
                ['Feedback from Alice Tester'], [],
            ],
            'feedback escapes xss in comments' => [
                '_feedback.php',
                [...self::FEEDBACK_VARS, 'comments' => $xss],
                $escaped, $noScript,
            ],
            'feedback escapes xss in name' => [
                '_feedback.php',
                [...self::FEEDBACK_VARS, 'name' => $xss, 'comments' => 'Nice.'],
                $escaped, $noScript,
            ],

            // _admin_to_owner.php
            'admin_to_owner renders without error' => [
                '_admin_to_owner.php',
                [
                    'to' => 'Jane Smith', 'from' => 'Admin User', 'message' => 'Please update your car details.',
                    'carContext' => ['id' => '123', 'year' => '1972', 'series' => 'S4', 'variant' => '', 'type' => '', 'chassis' => 'CH123456'],
                    'qualityIssue' => 'Missing chassis number',
                ],
                $document, [],
            ],
            'admin_to_owner contains recipient and sender' => [
                '_admin_to_owner.php',
                ['to' => 'Jane Smith', 'from' => 'Admin User', 'message' => 'Please update your car details.', 'carContext' => [], 'qualityIssue' => null],
                ['Jane Smith', 'Admin User'], [],
            ],
            'admin_to_owner contains registry administrator label' => [
                '_admin_to_owner.php',
                ['to' => 'Jane Smith', 'from' => 'Admin User', 'message' => 'Update required.', 'carContext' => [], 'qualityIssue' => null],
                ['Registry Administrator'], [],
            ],
            'admin_to_owner renders car context when provided' => [
                '_admin_to_owner.php',
                [
                    'to' => 'Jane Smith', 'from' => 'Admin User', 'message' => 'Update required.',
                    'carContext' => ['id' => '99', 'year' => '1971', 'series' => 'Sprint', 'variant' => '', 'type' => '', 'chassis' => 'ABCDEF'],
                    'qualityIssue' => 'Missing engine number',
                ],
                ['99', '1971', 'Elan Sprint', 'ABCDEF', 'Missing engine number'], [],
            ],
            'admin_to_owner renders full vehicle label with variant and type' => [
                '_admin_to_owner.php',
                [
                    'to' => 'Jane Smith', 'from' => 'Admin User', 'message' => 'Update required.',
                    'carContext' => ['id' => '77', 'year' => '1968', 'series' => '+2', 'variant' => 'FHC', 'type' => '50', 'chassis' => 'GHIJKL'],
                    'qualityIssue' => 'Verify engine specs',
                ],
                ['77', '1968', 'Elan +2 FHC (Type 50)', 'GHIJKL', 'Verify engine specs'], [],
            ],
            'admin_to_owner omits car box when car context empty' => [
                '_admin_to_owner.php',
                ['to' => 'Jane Smith', 'from' => 'Admin User', 'message' => 'General message.', 'carContext' => [], 'qualityIssue' => null],
                [], ['Related to Your Car'],
            ],
            'admin_to_owner escapes xss in message' => [
                '_admin_to_owner.php',
                ['to' => 'Jane Smith', 'from' => 'Admin User', 'message' => $xss, 'carContext' => [], 'qualityIssue' => null],
                $escaped, $noScript,
            ],
            'admin_to_owner escapes xss in recipient name' => [
                '_admin_to_owner.php',
                ['to' => $xss, 'from' => 'Admin User', 'message' => 'Hello.', 'carContext' => [], 'qualityIssue' => null],
                $escaped, $noScript,
            ],
            'admin_to_owner shows update button when quality issue and car present' => [
                '_admin_to_owner.php',
                ['to' => 'Alice', 'from' => 'Admin Name', 'message' => 'Please correct the data.', 'carContext' => $car99S4, 'qualityIssue' => 'Year is incorrect'],
                ['Update Your Car Record', 'Elan S4'], [],
            ],
            'admin_to_owner shows registry link when no quality issue' => [
                '_admin_to_owner.php',
                ['to' => 'Alice', 'from' => 'Admin Name', 'message' => 'General message.', 'carContext' => [], 'qualityIssue' => ''],
                ['elanregistry.org'], ['Update Your Car Record'],
            ],
            'admin_to_owner escapes xss in quality issue' => [
                '_admin_to_owner.php',
                ['to' => 'Alice', 'from' => 'Admin Name', 'message' => 'Please fix the data.', 'carContext' => $car99S4, 'qualityIssue' => '<script>alert("xss")</script>'],
                $escaped, $noScript,
            ],

            // _email_template_verify.php
            'verify renders without error' => [
                '_email_template_verify.php',
                [...self::VERIFY_VARS, 'vericode' => 'ABC123DEFG'],
                $document, [],
            ],
            'verify contains first name' => [
                '_email_template_verify.php',
                [...self::VERIFY_VARS, 'vericode' => 'ABC123DEFG'],
                ['Bob'], [],
            ],
            // The verification URL must embed the vericode and user_id.
            'verify contains verification link' => [
                '_email_template_verify.php',
                [...self::VERIFY_VARS, 'vericode' => 'MYCODE99'],
                ['verify.php', 'MYCODE99', 'user_id=7'], [],
            ],
            'verify contains expiry hours' => [
                '_email_template_verify.php', self::VERIFY_VARS, ['48'], [],
            ],
            'verify contains welcome content' => [
                '_email_template_verify.php', self::VERIFY_VARS, ['Welcome to the Registry'], [],
            ],
            'verify escapes xss in first name' => [
                '_email_template_verify.php',
                [...self::VERIFY_VARS, 'fname' => $xss],
                $escaped, $noScript,
            ],
            // rawurlencode encodes '+' as '%2B', so the raw '+' must not appear in the URL.
            'verify url-encodes plus in email' => [
                '_email_template_verify.php',
                [...self::VERIFY_VARS, 'email' => 'bob+tag@example.com'],
                ['bob%2Btag%40example.com'], [],
            ],

            // _email_template_verify_new.php
            'verify_new renders without error' => [
                '_email_template_verify_new.php',
                [...self::VERIFY_NEW_VARS, 'url' => 'users/verify_new.php?email=carol%40example.com&vericode=NEWCODE77'],
                $document, [],
            ],
            'verify_new contains first name' => [
                '_email_template_verify_new.php',
                [...self::VERIFY_NEW_VARS, 'url' => $verifyNewUrl],
                ['Carol'], [],
            ],
            // The verification button and security warning must be present.
            'verify_new displays new email address' => [
                '_email_template_verify_new.php',
                [...self::VERIFY_NEW_VARS, 'url' => $verifyNewUrl],
                ['Verify New Email Address', 'request this change?'], [],
            ],
            'verify_new contains verification button' => [
                '_email_template_verify_new.php',
                [...self::VERIFY_NEW_VARS, 'url' => $verifyNewUrl],
                ['Verify New Email Address'], [],
            ],
            // URL is built from trusted components (same as UserSpice original):
            // users/verify.php?new=1&email=<email>&vericode=<code>&user_id=<id>
            'verify_new builds full url from base url' => [
                '_email_template_verify_new.php',
                self::VERIFY_NEW_VARS,
                ['https://test.elanregistry.org/users/verify.php?new=1&amp;email=carol@example.com&amp;vericode=NEWCODE77&amp;user_id=12'], [],
            ],
            'verify_new contains expiry hours' => [
                '_email_template_verify_new.php',
                [...self::VERIFY_NEW_VARS, 'url' => $verifyNewUrl],
                ['24'], [],
            ],
            'verify_new escapes xss in first name' => [
                '_email_template_verify_new.php',
                [...self::VERIFY_NEW_VARS, 'fname' => $xss, 'url' => $verifyNewUrl],
                $escaped, $noScript,
            ],
            // The $url option is not used — URL is always built from trusted components.
            // Passing a malicious $url has no effect on the output.
            'verify_new url ignores url parameter' => [
                '_email_template_verify_new.php',
                [...self::VERIFY_NEW_VARS, 'url' => 'javascript:alert(1)'],
                ['users/verify.php'], ['javascript:'],
            ],
            // getFeedbackEmail() mock returns 'registrar@elanregistry.org'. The
            // template must render that dynamic address into the mailto: href
            // and the visible link text, not a hardcoded value (#368).
            'verify_new contains admin contact email' => [
                '_email_template_verify_new.php',
                self::VERIFY_NEW_VARS,
                ['href="mailto:registrar@elanregistry.org"', '>registrar@elanregistry.org<'], [],
            ],
        ];
    }

    /**
     * The UserSpice-owned verify templates live in usersc/views/; the rest
     * live in app/views/email/.
     *
     * @param string               $filename    View filename
     * @param array<string, mixed> $vars        Variables to expose inside the view
     * @param list<string>         $contains    Fragments the HTML must contain
     * @param list<string>         $notContains Fragments the HTML must not contain
     */
    #[DataProvider('viewMarkupProvider')]
    public function testViewRendersExpectedMarkup(
        string $filename,
        array $vars,
        array $contains,
        array $notContains
    ): void {
        $viewDir = str_starts_with($filename, '_email_template_verify') ? $this->userscViewDir() : null;
        $html = $this->renderView($filename, $vars, $viewDir);

        $this->assertMarkup($html, $contains, $notContains);
    }

    // ============================================================
    // createMessageContent() DIRECT TESTS
    // ============================================================

    public function testCreateMessageContentEscapesHtml(): void
    {
        $html = $this->template->createMessageContent('<script>alert(1)</script>');

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testCreateMessageContentDefaultHasNoItalic(): void
    {
        $html = $this->template->createMessageContent('Some message text');

        $this->assertStringNotContainsString('font-style:italic', $html);
    }

    public function testCreateMessageContentItalicAppliesStyle(): void
    {
        $html = $this->template->createMessageContent('Some message text', true);

        $this->assertStringContainsString('font-style:italic', $html);
    }

    public function testCreateMessageContentHasPreWrapStyle(): void
    {
        $html = $this->template->createMessageContent('Some message text');

        $this->assertStringContainsString('white-space:pre-wrap', $html);
    }

    // ============================================================
    // OPEN-REDIRECT GUARD — protocol-relative URL
    // ============================================================

    public function testVerifyNewEmailViewOpenRedirectGuardRejectsProtocolRelativeUrl(): void
    {
        $html = $this->renderView('_email_template_verify_new.php', [
            'fname'               => 'Carol',
            'email'               => 'carol@example.com',
            'vericode'            => 'NEWCODE77',
            'user_id'             => 12,
            'join_vericode_expiry' => 24,
            'url'                 => '//evil.com/steal-cookies',
        ], $this->userscViewDir());

        // ltrim strips the leading '//' so 'evil.com/steal-cookies' is treated as a
        // relative path under the registry origin.  The output URL must be on the
        // registry domain — not an off-domain redirect to evil.com.
        $this->assertStringContainsString('https://test.elanregistry.org/', $html);
        $this->assertStringNotContainsString('href="//evil.com', $html);
        $this->assertStringNotContainsString('href="http://evil.com', $html);
        $this->assertStringNotContainsString('href="https://evil.com', $html);
    }

    // ============================================================
    // AUTH TEMPLATES — no visible fallback URL (#2147)
    // ============================================================

    /**
     * Brevo rewrites every http link through its tracking domain, so a
     * printed fallback URL shows the reader a third-party tracking URL.
     *
     * @return array<string, array{string, array<string, mixed>, string, string}>
     */
    public static function authTemplateProvider(): array
    {
        $base = 'https://test.elanregistry.org';
        $resetVars = [
            'fname'                 => 'Bob',
            'email'                 => rawurlencode('bob@example.com'),
            'vericode'              => 'ABC123DEFG',
            'user_id'               => 7,
            'reset_vericode_expiry' => 15,
        ];
        $resetUrl = $base . '/users/forgot_password_reset.php?email=bob%40example.com'
            . '&vericode=ABC123DEFG&user_id=7&reset=1';
        $joinVars = [
            'fname'                => 'Bob',
            'email'                => 'bob@example.com',
            'vericode'             => 'ABC123DEFG',
            'user_id'              => 7,
            'join_vericode_expiry' => 48,
        ];

        return [
            'forgot_password' => [
                '_email_template_forgot_password.php', $resetVars, $resetUrl, 'Reset My Password',
            ],
            'registration_attempt' => [
                '_email_template_registration_attempt.php', $resetVars, $resetUrl, 'Reset My Password',
            ],
            'verify' => [
                '_email_template_verify.php',
                $joinVars,
                $base . '/users/verify.php?email=bob%40example.com&vericode=ABC123DEFG&user_id=7',
                'Verify My Email Address',
            ],
            'verify_new' => [
                '_email_template_verify_new.php',
                ['email' => rawurlencode('bob@example.com')] + $joinVars,
                $base . '/users/verify.php?new=1&email=bob%40example.com&vericode=ABC123DEFG&user_id=7',
                'Verify New Email Address',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $vars
     */
    #[DataProvider('authTemplateProvider')]
    public function testAuthTemplateShowsActionUrlOnlyInButtonAndMailtoFallback(
        string $view,
        array $vars,
        string $actionUrl,
        string $buttonLabel
    ): void {
        $html = $this->renderView($view, $vars, $this->userscViewDir());
        $escapedUrl = htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8');

        $this->assertStringNotContainsString('copy and paste', $html);
        $this->assertSame(1, substr_count($html, $escapedUrl), 'Action URL must appear only once');
        $this->assertStringContainsString('href="' . $escapedUrl . '"', $html);
        $this->assertSame(1, substr_count($html, '>' . $buttonLabel . '</a>'), 'Expected exactly one button');
        $this->assertStringNotContainsString('word-break:break-all', $html);
        $this->assertDoesNotMatchRegularExpression('#<p[^>]*>\s*</p>#', $html);

        $contact = htmlspecialchars(getFeedbackEmail(), ENT_QUOTES, 'UTF-8');
        $this->assertStringContainsString(
            'If the button above does not work, contact <a href="mailto:' . $contact . '">' . $contact . '</a>.',
            $html
        );
    }

    /**
     * Assert that the HTML contains each fragment in $contains and none in $notContains.
     *
     * @param list<string> $contains    Fragments the HTML must contain
     * @param list<string> $notContains Fragments the HTML must not contain
     */
    private function assertMarkup(string $html, array $contains, array $notContains = []): void
    {
        foreach ($contains as $fragment) {
            $this->assertStringContainsString($fragment, $html);
        }
        foreach ($notContains as $fragment) {
            $this->assertStringNotContainsString($fragment, $html);
        }
    }
}
