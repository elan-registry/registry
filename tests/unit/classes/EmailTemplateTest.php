<?php

declare(strict_types=1);

use ElanRegistry\EmailTemplate;
use PHPUnit\Framework\TestCase;

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

    // ------------------------------------------------------------------
    // _member_to_owner.php
    // ------------------------------------------------------------------

    public function testContactOwnerViewRendersWithoutError(): void
    {
        $html = $this->renderView('_member_to_owner.php', [
            'to'      => 'Jane Smith',
            'from'    => 'John Doe',
            'message' => 'Hi, interested in your Elan.',
        ]);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testContactOwnerViewContainsRecipientName(): void
    {
        $html = $this->renderView('_member_to_owner.php', [
            'to'      => 'Jane Smith',
            'from'    => 'John Doe',
            'message' => 'Hi there.',
        ]);

        $this->assertStringContainsString('Jane Smith', $html);
    }

    public function testContactOwnerViewContainsReplyInstruction(): void
    {
        $html = $this->renderView('_member_to_owner.php', [
            'to'      => 'Jane Smith',
            'from'    => 'John Doe',
            'message' => 'Hi there.',
        ]);

        $this->assertStringContainsString('simply reply to this email', $html);
    }

    public function testContactOwnerViewContainsSenderName(): void
    {
        $html = $this->renderView('_member_to_owner.php', [
            'to'      => 'Jane Smith',
            'from'    => 'John Doe',
            'message' => 'Hi there.',
        ]);

        $this->assertStringContainsString('John Doe', $html);
    }

    public function testContactOwnerViewEscapesXssInRecipientName(): void
    {
        $html = $this->renderView('_member_to_owner.php', [
            'to'      => '<script>alert(\'xss\')</script>',
            'from'    => 'John Doe',
            'message' => 'Hi.',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testContactOwnerViewEscapesXssInSenderName(): void
    {
        $html = $this->renderView('_member_to_owner.php', [
            'to'      => 'Jane Smith',
            'from'    => '<script>alert(\'xss\')</script>',
            'message' => 'Hi.',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testContactOwnerViewEscapesXssInMessage(): void
    {
        $html = $this->renderView('_member_to_owner.php', [
            'to'      => 'Jane Smith',
            'from'    => 'John Doe',
            'message' => '<script>alert(\'xss\')</script>',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ------------------------------------------------------------------
    // _feedback.php
    // ------------------------------------------------------------------

    public function testFeedbackViewRendersWithoutError(): void
    {
        $html = $this->renderView('_feedback.php', [
            'name'      =>'Alice Tester',
            'email'     => 'alice@example.com',
            'accountId' => '42',
            'comments'  => 'Great registry!',
        ]);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testFeedbackViewContainsOwnerDetails(): void
    {
        $html = $this->renderView('_feedback.php', [
            'name'      =>'Alice Tester',
            'email'     => 'alice@example.com',
            'accountId' => '42',
            'comments'  => 'Great registry!',
        ]);

        $this->assertStringContainsString('Alice Tester', $html);
        $this->assertStringContainsString('alice@example.com', $html);
        $this->assertStringContainsString('42', $html);
    }

    public function testFeedbackViewContainsComments(): void
    {
        $html = $this->renderView('_feedback.php', [
            'name'      =>'Alice Tester',
            'email'     => 'alice@example.com',
            'accountId' => '42',
            'comments'  => 'Great registry!',
        ]);

        $this->assertStringContainsString('Great registry!', $html);
    }

    public function testFeedbackViewContainsUserFeedbackSubtitle(): void
    {
        $html = $this->renderView('_feedback.php', [
            'name'      =>'Alice Tester',
            'email'     => 'alice@example.com',
            'accountId' => '7',
            'comments'  => 'Some comment.',
        ]);

        $this->assertStringContainsString('Feedback from Alice Tester', $html);
    }

    public function testFeedbackViewEscapesXssInComments(): void
    {
        $html = $this->renderView('_feedback.php', [
            'name'      =>'Alice Tester',
            'email'     => 'alice@example.com',
            'accountId' => '42',
            'comments'  => '<script>alert(\'xss\')</script>',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testFeedbackViewEscapesXssInName(): void
    {
        $html = $this->renderView('_feedback.php', [
            'name'      =>'<script>alert(\'xss\')</script>',
            'email'     => 'alice@example.com',
            'accountId' => '42',
            'comments'  => 'Nice.',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ------------------------------------------------------------------
    // _admin_to_owner.php
    // ------------------------------------------------------------------

    public function testAdminContactOwnerViewRendersWithoutError(): void
    {
        $html = $this->renderView('_admin_to_owner.php', [
            'to'           => 'Jane Smith',
            'from'         => 'Admin User',
            'message'      => 'Please update your car details.',
            'carContext'   => ['id' => '123', 'year' => '1972', 'series' => 'S4', 'variant' => '', 'type' => '', 'chassis' => 'CH123456'],
            'qualityIssue' => 'Missing chassis number',
        ]);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testAdminContactOwnerViewContainsRecipientAndSender(): void
    {
        $html = $this->renderView('_admin_to_owner.php', [
            'to'           => 'Jane Smith',
            'from'         => 'Admin User',
            'message'      => 'Please update your car details.',
            'carContext'   => [],
            'qualityIssue' => null,
        ]);

        $this->assertStringContainsString('Jane Smith', $html);
        $this->assertStringContainsString('Admin User', $html);
    }

    public function testAdminContactOwnerViewContainsRegistryAdministratorLabel(): void
    {
        $html = $this->renderView('_admin_to_owner.php', [
            'to'           => 'Jane Smith',
            'from'         => 'Admin User',
            'message'      => 'Update required.',
            'carContext'   => [],
            'qualityIssue' => null,
        ]);

        $this->assertStringContainsString('Registry Administrator', $html);
    }

    public function testAdminContactOwnerViewRendersCarContextWhenProvided(): void
    {
        $html = $this->renderView('_admin_to_owner.php', [
            'to'           => 'Jane Smith',
            'from'         => 'Admin User',
            'message'      => 'Update required.',
            'carContext'   => ['id' => '99', 'year' => '1971', 'series' => 'Sprint', 'variant' => '', 'type' => '', 'chassis' => 'ABCDEF'],
            'qualityIssue' => 'Missing engine number',
        ]);

        $this->assertStringContainsString('99', $html);
        $this->assertStringContainsString('1971', $html);
        $this->assertStringContainsString('Elan Sprint', $html);
        $this->assertStringContainsString('ABCDEF', $html);
        $this->assertStringContainsString('Missing engine number', $html);
    }

    public function testAdminContactOwnerViewRendersFullVehicleLabelWithVariantAndType(): void
    {
        $html = $this->renderView('_admin_to_owner.php', [
            'to'           => 'Jane Smith',
            'from'         => 'Admin User',
            'message'      => 'Update required.',
            'carContext'   => ['id' => '77', 'year' => '1968', 'series' => '+2', 'variant' => 'FHC', 'type' => '50', 'chassis' => 'GHIJKL'],
            'qualityIssue' => 'Verify engine specs',
        ]);

        $this->assertStringContainsString('77', $html);
        $this->assertStringContainsString('1968', $html);
        $this->assertStringContainsString('Elan +2 FHC (Type 50)', $html);
        $this->assertStringContainsString('GHIJKL', $html);
        $this->assertStringContainsString('Verify engine specs', $html);
    }

    public function testAdminContactOwnerViewOmitsCarBoxWhenCarContextEmpty(): void
    {
        $html = $this->renderView('_admin_to_owner.php', [
            'to'           => 'Jane Smith',
            'from'         => 'Admin User',
            'message'      => 'General message.',
            'carContext'   => [],
            'qualityIssue' => null,
        ]);

        // The "Related to Your Car" box must not appear when carContext is empty.
        $this->assertStringNotContainsString('Related to Your Car', $html);
    }

    public function testAdminContactOwnerViewEscapesXssInMessage(): void
    {
        $html = $this->renderView('_admin_to_owner.php', [
            'to'           => 'Jane Smith',
            'from'         => 'Admin User',
            'message'      => '<script>alert(\'xss\')</script>',
            'carContext'   => [],
            'qualityIssue' => null,
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAdminContactOwnerViewEscapesXssInRecipientName(): void
    {
        $html = $this->renderView('_admin_to_owner.php', [
            'to'           => '<script>alert(\'xss\')</script>',
            'from'         => 'Admin User',
            'message'      => 'Hello.',
            'carContext'   => [],
            'qualityIssue' => null,
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ------------------------------------------------------------------
    // _admin_to_owner.php — quality issue behaviour
    // ------------------------------------------------------------------

    public function testAdminContactOwnerViewShowsUpdateButtonWhenQualityIssueAndCarPresent(): void
    {
        $html = $this->renderView('_admin_to_owner.php', [
            'to'          => 'Alice',
            'from'        => 'Admin Name',
            'message'     => 'Please correct the data.',
            'carContext'  => ['id' => '99', 'year' => '1971', 'series' => 'S4', 'variant' => '', 'type' => '', 'chassis' => 'ELAN/6/1234'],
            'qualityIssue' => 'Year is incorrect',
        ]);

        $this->assertStringContainsString('Update Your Car Record', $html);
        $this->assertStringContainsString('Elan S4', $html);
    }

    public function testAdminContactOwnerViewShowsRegistryLinkWhenNoQualityIssue(): void
    {
        $html = $this->renderView('_admin_to_owner.php', [
            'to'          => 'Alice',
            'from'        => 'Admin Name',
            'message'     => 'General message.',
            'carContext'  => [],
            'qualityIssue' => '',
        ]);

        $this->assertStringNotContainsString('Update Your Car Record', $html);
        $this->assertStringContainsString('elanregistry.org', $html);
    }

    public function testAdminContactOwnerViewEscapesXssInQualityIssue(): void
    {
        $html = $this->renderView('_admin_to_owner.php', [
            'to'          => 'Alice',
            'from'        => 'Admin Name',
            'message'     => 'Please fix the data.',
            'carContext'  => ['id' => '99', 'year' => '1971', 'series' => 'S4', 'variant' => '', 'type' => '', 'chassis' => 'ELAN/6/1234'],
            'qualityIssue' => '<script>alert("xss")</script>',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ------------------------------------------------------------------
    // _email_template_verify.php
    // ------------------------------------------------------------------

    public function testVerifyEmailViewRendersWithoutError(): void
    {
        $html = $this->renderView('_email_template_verify.php', [
            'fname'               => 'Bob',
            'email'               => 'bob@example.com',
            'vericode'            => 'ABC123DEFG',
            'user_id'             => 7,
            'join_vericode_expiry' => 48,
        ], $this->userscViewDir());

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testVerifyEmailViewContainsFirstName(): void
    {
        $html = $this->renderView('_email_template_verify.php', [
            'fname'               => 'Bob',
            'email'               => 'bob@example.com',
            'vericode'            => 'ABC123DEFG',
            'user_id'             => 7,
            'join_vericode_expiry' => 48,
        ], $this->userscViewDir());

        $this->assertStringContainsString('Bob', $html);
    }

    public function testVerifyEmailViewContainsVerificationLink(): void
    {
        $html = $this->renderView('_email_template_verify.php', [
            'fname'               => 'Bob',
            'email'               => 'bob@example.com',
            'vericode'            => 'MYCODE99',
            'user_id'             => 7,
            'join_vericode_expiry' => 48,
        ], $this->userscViewDir());

        // The verification URL must embed the vericode and user_id.
        $this->assertStringContainsString('verify.php', $html);
        $this->assertStringContainsString('MYCODE99', $html);
        $this->assertStringContainsString('user_id=7', $html);
    }

    public function testVerifyEmailViewContainsExpiryHours(): void
    {
        $html = $this->renderView('_email_template_verify.php', [
            'fname'               => 'Bob',
            'email'               => 'bob@example.com',
            'vericode'            => 'CODE',
            'user_id'             => 7,
            'join_vericode_expiry' => 48,
        ], $this->userscViewDir());

        $this->assertStringContainsString('48', $html);
    }

    public function testVerifyEmailViewContainsWelcomeContent(): void
    {
        $html = $this->renderView('_email_template_verify.php', [
            'fname'               => 'Bob',
            'email'               => 'bob@example.com',
            'vericode'            => 'CODE',
            'user_id'             => 7,
            'join_vericode_expiry' => 48,
        ], $this->userscViewDir());

        $this->assertStringContainsString('Welcome to the Registry', $html);
    }

    public function testVerifyEmailViewEscapesXssInFirstName(): void
    {
        $html = $this->renderView('_email_template_verify.php', [
            'fname'               => '<script>alert(\'xss\')</script>',
            'email'               => 'bob@example.com',
            'vericode'            => 'CODE',
            'user_id'             => 7,
            'join_vericode_expiry' => 48,
        ], $this->userscViewDir());

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testVerifyEmailViewUrlEncodesPlusInEmail(): void
    {
        $html = $this->renderView('_email_template_verify.php', [
            'fname'               => 'Bob',
            'email'               => 'bob+tag@example.com',
            'vericode'            => 'CODE',
            'user_id'             => 7,
            'join_vericode_expiry' => 48,
        ], $this->userscViewDir());

        // rawurlencode encodes '+' as '%2B', so the raw '+' must not appear in the URL.
        $this->assertStringContainsString('bob%2Btag%40example.com', $html);
    }

    // ------------------------------------------------------------------
    // _email_template_verify_new.php
    // ------------------------------------------------------------------

    public function testVerifyNewEmailViewRendersWithoutError(): void
    {
        $html = $this->renderView('_email_template_verify_new.php', [
            'fname'               => 'Carol',
            'email'               => 'carol@example.com',
            'vericode'            => 'NEWCODE77',
            'user_id'             => 12,
            'join_vericode_expiry' => 24,
            'url'                 => 'users/verify_new.php?email=carol%40example.com&vericode=NEWCODE77',
        ], $this->userscViewDir());

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testVerifyNewEmailViewContainsFirstName(): void
    {
        $html = $this->renderView('_email_template_verify_new.php', [
            'fname'               => 'Carol',
            'email'               => 'carol@example.com',
            'vericode'            => 'NEWCODE77',
            'user_id'             => 12,
            'join_vericode_expiry' => 24,
            'url'                 => 'users/verify_new.php?vericode=NEWCODE77',
        ], $this->userscViewDir());

        $this->assertStringContainsString('Carol', $html);
    }

    public function testVerifyNewEmailViewDisplaysNewEmailAddress(): void
    {
        $html = $this->renderView('_email_template_verify_new.php', [
            'fname'               => 'Carol',
            'email'               => 'carol@example.com',
            'vericode'            => 'NEWCODE77',
            'user_id'             => 12,
            'join_vericode_expiry' => 24,
            'url'                 => 'users/verify_new.php?vericode=NEWCODE77',
        ], $this->userscViewDir());

        // The verification button and security warning must be present.
        $this->assertStringContainsString('Verify New Email Address', $html);
        $this->assertStringContainsString('request this change?', $html);
    }

    public function testVerifyNewEmailViewContainsVerificationButton(): void
    {
        $html = $this->renderView('_email_template_verify_new.php', [
            'fname'               => 'Carol',
            'email'               => 'carol@example.com',
            'vericode'            => 'NEWCODE77',
            'user_id'             => 12,
            'join_vericode_expiry' => 24,
            'url'                 => 'users/verify_new.php?vericode=NEWCODE77',
        ], $this->userscViewDir());

        $this->assertStringContainsString('Verify New Email Address', $html);
    }

    public function testVerifyNewEmailViewBuildsFullUrlFromBaseUrl(): void
    {
        $html = $this->renderView('_email_template_verify_new.php', [
            'fname'               => 'Carol',
            'email'               => 'carol@example.com',
            'vericode'            => 'NEWCODE77',
            'user_id'             => 12,
            'join_vericode_expiry' => 24,
        ], $this->userscViewDir());

        // URL is built from trusted components (same as UserSpice original):
        // users/verify.php?new=1&email=<email>&vericode=<code>&user_id=<id>
        $this->assertStringContainsString(
            'https://test.elanregistry.org/users/verify.php?new=1&amp;email=carol@example.com&amp;vericode=NEWCODE77&amp;user_id=12',
            $html
        );
    }

    public function testVerifyNewEmailViewContainsExpiryHours(): void
    {
        $html = $this->renderView('_email_template_verify_new.php', [
            'fname'               => 'Carol',
            'email'               => 'carol@example.com',
            'vericode'            => 'NEWCODE77',
            'user_id'             => 12,
            'join_vericode_expiry' => 24,
            'url'                 => 'users/verify_new.php?vericode=NEWCODE77',
        ], $this->userscViewDir());

        $this->assertStringContainsString('24', $html);
    }

    public function testVerifyNewEmailViewEscapesXssInFirstName(): void
    {
        $html = $this->renderView('_email_template_verify_new.php', [
            'fname'               => '<script>alert(\'xss\')</script>',
            'email'               => 'carol@example.com',
            'vericode'            => 'NEWCODE77',
            'user_id'             => 12,
            'join_vericode_expiry' => 24,
            'url'                 => 'users/verify_new.php?vericode=NEWCODE77',
        ], $this->userscViewDir());

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testVerifyNewEmailViewUrlIgnoresUrlParameter(): void
    {
        // The $url option is not used — URL is always built from trusted components.
        // Passing a malicious $url has no effect on the output.
        $html = $this->renderView('_email_template_verify_new.php', [
            'fname'               => 'Carol',
            'email'               => 'carol@example.com',
            'vericode'            => 'NEWCODE77',
            'user_id'             => 12,
            'join_vericode_expiry' => 24,
            'url'                 => 'javascript:alert(1)',
        ], $this->userscViewDir());

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('users/verify.php', $html);
    }

    public function testVerifyNewEmailViewContainsAdminContactEmail(): void
    {
        // getFeedbackEmail() mock returns 'registrar@elanregistry.org'.
        // Verify the template renders the dynamic address into the mailto: href
        // and visible link text — not any hardcoded value.
        $html = $this->renderView('_email_template_verify_new.php', [
            'fname'                => 'Carol',
            'email'                => 'carol@example.com',
            'vericode'             => 'NEWCODE77',
            'user_id'              => 12,
            'join_vericode_expiry' => 24,
        ], $this->userscViewDir());

        $this->assertStringContainsString(
            'href="mailto:registrar@elanregistry.org"',
            $html,
            '_email_template_verify_new.php must build mailto: href from getFeedbackEmail() (#368)'
        );
        $this->assertStringContainsString(
            '>registrar@elanregistry.org<',
            $html,
            '_email_template_verify_new.php must display the configured feedback address in link text (#368)'
        );
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
}
