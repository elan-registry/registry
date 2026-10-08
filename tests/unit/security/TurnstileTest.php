<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__ . '/../../../usersc/includes/turnstile.php';

/**
 * Issue #1798. A non-empty token is not tested: _verifyTurnstileToken() calls
 * Cloudflare over the network with no mock seam.
 */
#[Group('fast')]
#[Group('unit')]
#[Group('security')]
class TurnstileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $mockLogEntries, $is_https, $remote_addr;
        $mockLogEntries = [];
        $is_https = true;
        $remote_addr = '203.0.113.42';
        $_ENV['TURNSTILE_SITE_KEY'] = 'test-site-key';
        $_ENV['TURNSTILE_SECRET_KEY'] = 'test-secret-key';
        $_POST = [];
    }

    protected function tearDown(): void
    {
        unset($_ENV['TURNSTILE_SITE_KEY'], $_ENV['TURNSTILE_SECRET_KEY'], $_POST['cf-turnstile-response']);
        parent::tearDown();
    }

    #[Group('fast')]
    public function testEmptyTokenLogsSecurityEntryWithClientIp(): void
    {
        global $mockLogEntries;
        unset($_POST['cf-turnstile-response']);

        $result = verifyTurnstile();

        $this->assertFalse($result);
        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_SECURITY, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('empty token', $mockLogEntries[0]['message']);
        $this->assertStringContainsString('203.0.113.42', $mockLogEntries[0]['message']);
    }

    #[Group('fast')]
    public function testEmptyStringTokenIsLoggedTheSameAsAbsentKey(): void
    {
        global $mockLogEntries;
        $_POST['cf-turnstile-response'] = '';

        $result = verifyTurnstile();

        $this->assertFalse($result);
        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_SECURITY, $mockLogEntries[0]['category']);
    }

    #[Group('fast')]
    public function testDisabledTurnstileSkipsVerificationAndDoesNotLog(): void
    {
        global $mockLogEntries, $is_https;
        $is_https = false;
        unset($_POST['cf-turnstile-response']);

        $result = verifyTurnstile();

        $this->assertTrue($result);
        $this->assertEmpty($mockLogEntries);
    }

    #[Group('fast')]
    public function testEmptyTokenLogMessageIsDistinctFromRejectionLogMessage(): void
    {
        global $mockLogEntries;
        unset($_POST['cf-turnstile-response']);

        verifyTurnstile();

        // Keeps the empty-token line distinct from "Turnstile rejected token from <ip>".
        $this->assertStringNotContainsString('rejected', $mockLogEntries[0]['message']);
    }

    #[Group('fast')]
    public function testAddTurnstileWithFailureCallbacksEmitsCallbackAttributesAndScriptId(): void
    {
        ob_start();
        addTurnstile(true);
        $html = ob_get_clean();

        $this->assertStringContainsString('data-error-callback="elanTurnstileError"', $html);
        $this->assertStringContainsString('data-expired-callback="elanTurnstileExpired"', $html);
        $this->assertStringContainsString('id="elan-turnstile-script"', $html);
    }

    /**
     * The Playwright check of this wiring skips in CI (no Turnstile keys),
     * so this is the only CI guard against a revert to addTurnstile().
     */
    #[Group('fast')]
    public function testLoginHookCallsAddTurnstileWithFailureCallbacksEnabled(): void
    {
        ob_start();
        require __DIR__ . '/../../../usersc/plugins/hooker/hooks/login_form_turnstile.php';
        $html = ob_get_clean();

        $this->assertStringContainsString(
            'data-error-callback="elanTurnstileError"',
            $html,
            'login_form_turnstile.php must call addTurnstile(true) — reverting to addTurnstile() ' .
            'reintroduces the #1798 regression (no reset path on the login form)'
        );
        $this->assertStringContainsString('data-expired-callback="elanTurnstileExpired"', $html);
    }

    #[Group('fast')]
    public function testAddTurnstileWithoutFailureCallbacksOmitsCallbackAttributesAndScriptId(): void
    {
        ob_start();
        addTurnstile(false);
        $html = ob_get_clean();

        $this->assertStringNotContainsString('data-error-callback', $html);
        $this->assertStringNotContainsString('data-expired-callback', $html);
        $this->assertStringNotContainsString('id="elan-turnstile-script"', $html);
    }
}
