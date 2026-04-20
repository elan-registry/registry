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
     * @return array{abs_us_root: string, us_url_root: string, view_dir: string}
     */
    private function getTemplatePathVars(): array
    {
        $projectRoot = dirname(__DIR__, 3); // tests/unit/classes → project root
        return [
            'abs_us_root' => $projectRoot . '/',
            'us_url_root' => '',
            'view_dir'    => $projectRoot . '/usersc/views/',
        ];
    }

    /**
     * Capture the output of a view file after setting the provided
     * variables in the local scope.  Returns the rendered HTML string.
     *
     * @param string               $filename  View filename (e.g. '_email_contact_owner.php')
     * @param array<string, mixed> $vars      Variables to expose inside the view
     */
    private function renderView(string $filename, array $vars): string
    {
        $paths = $this->getTemplatePathVars();
        // These two must be set so the require_once inside the view resolves.
        $abs_us_root = $paths['abs_us_root']; // phpcs:ignore
        $us_url_root = $paths['us_url_root']; // phpcs:ignore

        // Expand caller-supplied variables into the local scope.
        extract($vars, EXTR_SKIP);

        ob_start();
        require $paths['view_dir'] . $filename;
        return (string) ob_get_clean();
    }

    // ------------------------------------------------------------------
    // _email_contact_owner.php
    // ------------------------------------------------------------------

    public function testContactOwnerViewRendersWithoutError(): void
    {
        $html = $this->renderView('_email_contact_owner.php', [
            'to'      => 'Jane Smith',
            'from'    => 'John Doe',
            'message' => 'Hi, interested in your Elan.',
        ]);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testContactOwnerViewContainsRecipientName(): void
    {
        $html = $this->renderView('_email_contact_owner.php', [
            'to'      => 'Jane Smith',
            'from'    => 'John Doe',
            'message' => 'Hi there.',
        ]);

        $this->assertStringContainsString('Jane Smith', $html);
    }

    public function testContactOwnerViewContainsReplyInstruction(): void
    {
        $html = $this->renderView('_email_contact_owner.php', [
            'to'      => 'Jane Smith',
            'from'    => 'John Doe',
            'message' => 'Hi there.',
        ]);

        $this->assertStringContainsString('simply reply to this email', $html);
    }

    public function testContactOwnerViewContainsSenderName(): void
    {
        $html = $this->renderView('_email_contact_owner.php', [
            'to'      => 'Jane Smith',
            'from'    => 'John Doe',
            'message' => 'Hi there.',
        ]);

        $this->assertStringContainsString('John Doe', $html);
    }

    public function testContactOwnerViewEscapesXssInRecipientName(): void
    {
        $html = $this->renderView('_email_contact_owner.php', [
            'to'      => '<script>alert(\'xss\')</script>',
            'from'    => 'John Doe',
            'message' => 'Hi.',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testContactOwnerViewEscapesXssInSenderName(): void
    {
        $html = $this->renderView('_email_contact_owner.php', [
            'to'      => 'Jane Smith',
            'from'    => '<script>alert(\'xss\')</script>',
            'message' => 'Hi.',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testContactOwnerViewEscapesXssInMessage(): void
    {
        $html = $this->renderView('_email_contact_owner.php', [
            'to'      => 'Jane Smith',
            'from'    => 'John Doe',
            'message' => '<script>alert(\'xss\')</script>',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ------------------------------------------------------------------
    // _email_feedback.php
    // ------------------------------------------------------------------

    public function testFeedbackViewRendersWithoutError(): void
    {
        $html = $this->renderView('_email_feedback.php', [
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
        $html = $this->renderView('_email_feedback.php', [
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
        $html = $this->renderView('_email_feedback.php', [
            'name'      =>'Alice Tester',
            'email'     => 'alice@example.com',
            'accountId' => '42',
            'comments'  => 'Great registry!',
        ]);

        $this->assertStringContainsString('Great registry!', $html);
    }

    public function testFeedbackViewContainsUserFeedbackSubtitle(): void
    {
        $html = $this->renderView('_email_feedback.php', [
            'name'      =>'Alice Tester',
            'email'     => 'alice@example.com',
            'accountId' => '7',
            'comments'  => 'Some comment.',
        ]);

        $this->assertStringContainsString('Feedback from Alice Tester', $html);
    }

    public function testFeedbackViewEscapesXssInComments(): void
    {
        $html = $this->renderView('_email_feedback.php', [
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
        $html = $this->renderView('_email_feedback.php', [
            'name'      =>'<script>alert(\'xss\')</script>',
            'email'     => 'alice@example.com',
            'accountId' => '42',
            'comments'  => 'Nice.',
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ------------------------------------------------------------------
    // _email_admin_contact_owner.php
    // ------------------------------------------------------------------

    public function testAdminContactOwnerViewRendersWithoutError(): void
    {
        $html = $this->renderView('_email_admin_contact_owner.php', [
            'to'           => 'Jane Smith',
            'from'         => 'Admin User',
            'message'      => 'Please update your car details.',
            'carContext'   => ['id' => '123', 'year' => '1972', 'model' => 'Elan S4', 'chassis' => 'CH123456'],
            'qualityIssue' => 'Missing chassis number',
        ]);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testAdminContactOwnerViewContainsRecipientAndSender(): void
    {
        $html = $this->renderView('_email_admin_contact_owner.php', [
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
        $html = $this->renderView('_email_admin_contact_owner.php', [
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
        $html = $this->renderView('_email_admin_contact_owner.php', [
            'to'           => 'Jane Smith',
            'from'         => 'Admin User',
            'message'      => 'Update required.',
            'carContext'   => ['id' => '99', 'year' => '1971', 'model' => 'Elan Sprint', 'chassis' => 'ABCDEF'],
            'qualityIssue' => 'Missing engine number',
        ]);

        $this->assertStringContainsString('99', $html);
        $this->assertStringContainsString('1971', $html);
        $this->assertStringContainsString('Elan Sprint', $html);
        $this->assertStringContainsString('ABCDEF', $html);
        $this->assertStringContainsString('Missing engine number', $html);
    }

    public function testAdminContactOwnerViewOmitsCarBoxWhenCarContextEmpty(): void
    {
        $html = $this->renderView('_email_admin_contact_owner.php', [
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
        $html = $this->renderView('_email_admin_contact_owner.php', [
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
        $html = $this->renderView('_email_admin_contact_owner.php', [
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
        ]);

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
        ]);

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
        ]);

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
        ]);

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
        ]);

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
        ]);

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
        ]);

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
        ]);

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
        ]);

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
        ]);

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
        ]);

        $this->assertStringContainsString('Verify New Email Address', $html);
    }

    public function testVerifyNewEmailViewBuildsFullUrlFromBaseUrl(): void
    {
        $relativeUrl = 'users/verify_new.php?vericode=NEWCODE77';
        $html = $this->renderView('_email_template_verify_new.php', [
            'fname'               => 'Carol',
            'email'               => 'carol@example.com',
            'vericode'            => 'NEWCODE77',
            'user_id'             => 12,
            'join_vericode_expiry' => 24,
            'url'                 => $relativeUrl,
        ]);

        // The template prepends getBaseUrl() (mocked as 'https://test.elanregistry.org')
        // to the relative $url value.
        $this->assertStringContainsString('https://test.elanregistry.org/' . $relativeUrl, $html);
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
        ]);

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
        ]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testVerifyNewEmailViewEscapesXssInName(): void
    {
        $html = $this->renderView('_email_template_verify_new.php', [
            'fname'               => '<script>alert(\'xss\')</script>',
            'email'               => 'carol@example.com',
            'vericode'            => 'NEWCODE77',
            'user_id'             => 12,
            'join_vericode_expiry' => 24,
            'url'                 => 'users/verify_new.php?vericode=NEWCODE77',
        ]);

        // Malicious fname must be HTML-escaped, not rendered raw.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
