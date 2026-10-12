<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SOURCE-WIRING PIN TEST for usersc/login.php. It reads the file as text and
 * does not run it, because the page needs a session, a CSRF token and a
 * database. It pins two sets of calls so that an edit or an upstream merge
 * cannot remove them without a failing test.
 *
 * - The #2189 wiring: only a matched account passes `$knownIdentifier` to the
 *   rate limiter, and an unmatched attempt logs the fixed `(unrecognised)`.
 *   LoginFailureLoggingTest covers the behavior, but CI does not run
 *   integration tests.
 * - The UserSpice 6.1.7 port: the TOTP rate limit, the TOTP-unavailable
 *   message, the version guard on `recordLogin`, the `loginEmail()` call that
 *   skips the early login count, `$currentSessionName` set on every path, and
 *   the session unset in each inline TOTP branch without a verified code.
 *
 * @see usersc/login.php
 * @see tests/integration/LoginFailureLoggingTest.php
 */
#[Group('fast')]
#[Group('unit')]
#[Group('security')]
final class LoginWiringTest extends TestCase
{
    private string $source = '';

    protected function setUp(): void
    {
        parent::setUp();

        $path = __DIR__ . '/../../../usersc/login.php';
        $this->assertFileExists($path);
        $source = file_get_contents($path);
        $this->assertIsString($source);
        $this->source = $source;
    }

    public function test_totpCodeSentWithLoginFormIsRateLimited(): void
    {
        $this->assertStringContainsString("checkRateLimit('totp_verify'", $this->source);
        $this->assertMatchesRegularExpression(
            "/!empty\\(\\\$totpCode\\)\\s*&&\\s*!checkRateLimit\\('totp_verify',\\s*\\\$tempUser->data\\(\\)->id\\)/",
            $this->source,
            'The TOTP code sent with the password must pass the rate limit before it is verified'
        );
    }

    /**
     * loginEmail() signs the user in before TOTP is checked. In each branch that
     * stops short of a verified code, the unset is all that keeps a password-only
     * session from surviving. A rate-limited code must also never reach
     * verifyCode(), so the verify branch must be joined by elseif.
     */
    public function test_everyInlineTotpBranchWithoutAVerifiedCodeEndsTheSession(): void
    {
        $branches = [
            'rate-limited code' => $this->sliceBetween(
                "if (!empty(\$totpCode) && !checkRateLimit('totp_verify', \$tempUser->data()->id)) {",
                '} elseif (!empty($totpCode)) {'
            ),
            'invalid code' => $this->sliceBetween(
                "handleAuthFailure('totp_verify', \$tempUser->data()->id",
                '$awaitingTOTP = false;'
            ),
            'start two-step verification' => $this->sliceBefore(
                "\$_SESSION[\$currentSessionName . '_totp_user_id_to_verify'] = \$tempUser->data()->id;",
                '} else {'
            ),
        ];

        foreach ($branches as $name => $body) {
            $this->assertStringContainsString('unset($_SESSION[$currentSessionName]);', $body, "The {$name} branch must end the password-only session");
            $this->assertStringContainsString('$clearReauthConfirmation();', $body, "The {$name} branch must clear the reauth confirmation");
        }
    }

    public function test_unreadableTotpKeyShowsUnavailableMessageInsteadOfInvalidCode(): void
    {
        $this->assertStringContainsString('encryptionAvailable', $this->source);
        $this->assertMatchesRegularExpression(
            "/method_exists\\(\\\$totpHandler,\\s*'encryptionAvailable'\\)\\s*&&\\s*!\\\$totpHandler->encryptionAvailable\\(\\)/",
            $this->source
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($this->source, '$totpUnavailableMsg ?: lang("2FA_ERR_INVALID_CODE")'),
            'Both TOTP verification paths must show the unavailable message'
        );
    }

    public function test_recordLoginIsGuardedByMethodExists(): void
    {
        $this->assertMatchesRegularExpression(
            "/method_exists\\(\\\$loginUser,\\s*'recordLogin'\\)/",
            $this->source
        );
        $this->assertSame(
            3,
            substr_count($this->source, '$recordCompletedLogin($user'),
            'The step-2 TOTP, inline TOTP and no-TOTP paths must each record the completed login'
        );
        // On step 2, $user is not the signed-in user yet, so the id must come from the TOTP session.
        $this->assertStringContainsString('$recordCompletedLogin($user, $tempUserId);', $this->source);
    }

    /**
     * An undefined $currentSessionName would make unset($_SESSION[$currentSessionName])
     * a no-op, and a password-only session would survive a failed TOTP step.
     */
    public function test_sessionNameIsSetBeforeTheTotpCheckAndEverySessionUnset(): void
    {
        $assignment = strpos($this->source, "\$currentSessionName = Config::get('session/session_name');");
        $this->assertIsInt($assignment, '$currentSessionName must be assigned at the top level of login.php');

        $totpCheck = strpos($this->source, 'if($settings->totp > 0');
        $firstUnset = strpos($this->source, 'unset($_SESSION[$currentSessionName])');
        $this->assertIsInt($totpCheck);
        $this->assertIsInt($firstUnset);
        $this->assertLessThan($totpCheck, $assignment, 'Set the session name before the TOTP check');
        $this->assertLessThan($firstUnset, $assignment, 'Set the session name before the first session unset');
    }

    public function test_loginEmailIsCalledWithoutTheEarlyLoginCount(): void
    {
        $this->assertMatchesRegularExpression(
            '/->loginEmail\([^;]*,\s*false\);/',
            $this->source,
            'loginEmail() must get a trailing false so UserSpice 6.1.7 counts the login only after TOTP passes'
        );
    }

    public function test_onlyAMatchedAccountPassesKnownIdentifierToTheRateLimiter(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$knownIdentifier\s*=\s*\(int\)\s*\(\$userId\s*\?\?\s*0\)\s*>\s*0\s*\?\s*\$username\s*:\s*null;/',
            $this->source
        );
        $this->assertMatchesRegularExpression(
            "/checkRateLimit\\('login_attempt',\\s*\\\$userId,\\s*\\\$knownIdentifier\\)/",
            $this->source
        );
        $this->assertMatchesRegularExpression(
            "/handleAuthFailure\\('login_attempt',\\s*\\\$userId,\\s*\\\$knownIdentifier,/",
            $this->source
        );
    }

    private function sliceBetween(string $start, string $end): string
    {
        $from = strpos($this->source, $start);
        $this->assertIsInt($from, "Not found in login.php: {$start}");
        $to = strpos($this->source, $end, $from + strlen($start));
        $this->assertIsInt($to, "Not found after the start marker: {$end}");

        return substr($this->source, $from, $to - $from);
    }

    /** The text from the last $start before $anchor up to $anchor. */
    private function sliceBefore(string $anchor, string $start): string
    {
        $to = strpos($this->source, $anchor);
        $this->assertIsInt($to, "Not found in login.php: {$anchor}");
        $from = strrpos(substr($this->source, 0, $to), $start);
        $this->assertIsInt($from, "Not found before the anchor: {$start}");

        return substr($this->source, $from, $to - $from);
    }

    public function test_unmatchedAttemptLogsFixedPlaceholderNotTheSubmittedValue(): void
    {
        $this->assertStringContainsString("'(unrecognised)'", $this->source);
        $this->assertMatchesRegularExpression(
            "/'username_attempted'\\s*=>\\s*\\\$knownIdentifier\\s*\\?\\?\\s*'\\(unrecognised\\)'/",
            $this->source
        );
    }
}
