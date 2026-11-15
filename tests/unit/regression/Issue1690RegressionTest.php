<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;

/**
 * Regression test for Issue #1690: join form webview silent fail —
 * server-side visibility.
 *
 * Before this issue, three registration-rejection paths in usersc/join.php
 * left zero server-side trace or the wrong log category:
 *  - CSRF token check failure: no logger() call at all (died via
 *    token_error.php before anything could be logged).
 *  - Rate-limit block: no logger() call at all.
 *  - Validation failure (incl. Turnstile rejection): logged, but under
 *    LOG_CATEGORY_SYSTEM_ERROR — indistinguishable from an actual server
 *    error, and not queryable as "every rejected join attempt."
 *
 * This test asserts, source-text style (matching Issue1406RegressionTest's
 * approach — join.php is a top-level script with no independently
 * callable branch-selection logic to unit-test at the HTTP/behavior
 * level), that all three branches now call logger() with the new
 * LogCategories::LOG_CATEGORY_REGISTRATION_FAILED category, positioned
 * before the branch's die/redirect, and that #1406's enumeration-safety
 * property (field-name-only metadata) is preserved.
 *
 * @issue 1690
 * @link https://github.com/elan-registry/registry/issues/1690
 * @description Every failed/blocked join attempt reaching the server is
 *   now logged under one queryable category (RegistrationFailed),
 *   differentiated by a `stage` metadata key.
 * @category regression
 */
#[Group('regression')]
#[Group('fast')]
final class Issue1690RegressionTest extends RegressionTestCase
{
    private const JOIN_PHP_PATH = __DIR__ . '/../../../usersc/join.php';

    private const CATEGORY_CONST = 'LogCategories::LOG_CATEGORY_REGISTRATION_FAILED';

    private function joinPhpSource(): string
    {
        $source = file_get_contents(self::JOIN_PHP_PATH);
        $this->assertIsString($source, 'usersc/join.php must be readable');
        return $source;
    }

    /**
     * The CSRF-failure branch must call logger() with the new category
     * BEFORE including token_error.php — that script die()s unconditionally,
     * so any logger() call placed after it would never execute.
     */
    public function testCsrfFailureBranchLogsBeforeTokenErrorInclude(): void
    {
        $source = $this->joinPhpSource();

        $loggerPos = strpos($source, 'logger(0, ' . self::CATEGORY_CONST);
        $this->assertNotFalse(
            $loggerPos,
            'usersc/join.php must call logger() with LogCategories::LOG_CATEGORY_REGISTRATION_FAILED'
        );

        $tokenErrorPos = strpos($source, "usersc/scripts/token_error.php");
        $this->assertNotFalse(
            $tokenErrorPos,
            'Could not locate the token_error.php include in usersc/join.php'
        );

        $this->assertLessThan(
            $tokenErrorPos,
            $loggerPos,
            'The CSRF-failure logger() call must appear before the token_error.php include, '
                . 'since that script die()s unconditionally and nothing after it can run'
        );

        // Confirm this specific logger() call is inside the CSRF-check branch
        // (i.e. closely follows the Token::check(...) failure condition), not
        // just anywhere earlier in the file.
        $tokenCheckPos = strpos($source, '!Token::check($token)');
        $this->assertNotFalse($tokenCheckPos, 'Could not locate the Token::check() failure condition');
        $this->assertGreaterThan(
            $tokenCheckPos,
            $loggerPos,
            'The CSRF logger() call must be inside the Token::check() failure branch, after the condition'
        );

        $csrfBranch = substr($source, $tokenCheckPos, $tokenErrorPos - $tokenCheckPos);
        $this->assertStringContainsString(
            "'stage' => 'csrf'",
            $csrfBranch,
            "The CSRF-failure logger() call must tag metadata with stage => 'csrf'"
        );
    }

    /**
     * The rate-limit-block branch must call logger() with the new category
     * BEFORE the usError() call in that same branch.
     */
    public function testRateLimitBranchLogsBeforeUsError(): void
    {
        $source = $this->joinPhpSource();

        $rateLimitCheckPos = strpos($source, "!checkRateLimit('registration_attempt')");
        $this->assertNotFalse($rateLimitCheckPos, 'Could not locate the registration_attempt rate-limit check');

        $usErrorPos = strpos($source, 'usError(getRateLimitErrorMessage', $rateLimitCheckPos);
        $this->assertNotFalse(
            $usErrorPos,
            'Could not locate the rate-limit branch\'s usError(getRateLimitErrorMessage(...)) call'
        );

        $branch = substr($source, $rateLimitCheckPos, $usErrorPos - $rateLimitCheckPos);

        $this->assertStringContainsString(
            'logger(0, ' . self::CATEGORY_CONST,
            $branch,
            'The rate-limit-block branch must call logger() with LogCategories::LOG_CATEGORY_REGISTRATION_FAILED '
                . 'before its usError() call'
        );

        $this->assertStringContainsString(
            "'stage' => 'rate_limit'",
            $branch,
            "The rate-limit-block logger() call must tag metadata with stage => 'rate_limit'"
        );
    }

    /**
     * The validation-failure branch's logger() call must use the new
     * LOG_CATEGORY_REGISTRATION_FAILED category — not the old
     * LOG_CATEGORY_SYSTEM_ERROR, which made rejected registrations
     * indistinguishable from actual server errors.
     */
    public function testValidationFailureBranchUsesRegistrationFailedCategory(): void
    {
        $source = $this->joinPhpSource();

        $this->assertMatchesRegularExpression(
            '/logger\(0,\s*' . preg_quote(self::CATEGORY_CONST, '/') . ',\s*\r?\n\s*\'join\.php: Registration failed/',
            $source,
            'The validation-failure branch\'s logger() call must use LOG_CATEGORY_REGISTRATION_FAILED'
        );
    }

    /**
     * The validation-branch metadata array must reference only field names
     * (via array_column(...)) and counts/context — never raw validation
     * message text or the submitted email/username. This protects #1406's
     * enumeration-safety fix: metadata is operator-only (server logs), but
     * must not casually grow to include anything that would matter if ever
     * exposed via a future logs-viewing feature.
     */
    public function testValidationBranchMetadataOmitsRawMessagesAndPii(): void
    {
        $source = $this->joinPhpSource();

        $this->assertMatchesRegularExpression(
            '/logger\(0,\s*' . preg_quote(self::CATEGORY_CONST, '/') . ',\s*\r?\n\s*\'join\.php: Registration failed/',
            $source,
            'Could not locate the validation-branch logger() call'
        );
        preg_match(
            '/logger\(0,\s*' . preg_quote(self::CATEGORY_CONST, '/') . ',\s*\r?\n\s*\'join\.php: Registration failed/',
            $source,
            $callStartMatch,
            PREG_OFFSET_CAPTURE
        );
        $categoryPos = $callStartMatch[0][1];

        // The metadata array for this call ends at the closing "]);" that
        // terminates the logger() call — find it starting from the call site.
        $closePos = strpos($source, ']);', $categoryPos);
        $this->assertNotFalse($closePos, 'Could not locate the end of the validation-branch logger() call');

        $call = substr($source, $categoryPos, $closePos - $categoryPos + 3);

        // error_fields was removed: Validate::addError() (upstream, users/classes/Validate.php)
        // collapses each [message, field] pair down to just the message before it
        // ever reaches $validation->_errors, so array_column($validation->_errors, 1)
        // was silently always empty — dead code, not a PII leak, but misleading.
        // Assert it stays gone rather than reappearing as fabricated/incorrect data.
        $this->assertStringNotContainsString(
            'error_fields',
            $call,
            'error_fields was removed as dead code ($validation->_errors is a flat array of '
                . 'message strings — Validate::addError() discards the field name — so '
                . 'array_column(..., 1) always returned []); it should not be reintroduced '
                . 'without first fixing the upstream data loss'
        );

        $this->assertStringContainsString('error_count', $call);
        $this->assertStringContainsString("'stage'        => 'validation'", $call);
        $this->assertStringContainsString('user_agent', $call);

        // Never the raw error message text (index 0 of each _errors entry)
        // or PII fields directly.
        $this->assertStringNotContainsString(
            "\$validation->_errors[0][0]",
            $call,
            'Metadata must never include raw validation message text (index 0 of an _errors entry)'
        );
        $this->assertStringNotContainsString(
            '$email',
            $call,
            'Metadata must never include the submitted email — that would reintroduce an '
                . 'enumeration/PII leak into server logs'
        );
        $this->assertStringNotContainsString(
            '$fname',
            $call,
            'Metadata must never include submitted PII fields such as first name'
        );
        $this->assertStringNotContainsString(
            '$lname',
            $call,
            'Metadata must never include submitted PII fields such as last name'
        );
    }

    /**
     * Location is a client-side-required field (HTML5 `required` on the
     * LocationPicker widget, not enforced server-side in join.php at all —
     * confirmed by grep, join.php never references city/lat/lon). A GPS
     * lookup failure that leaves it unset blocks submission the same way a
     * blocked Turnstile submit does, with the same "invisible server-side"
     * property #1690 exists to fix — so it must route through the same
     * beacon rather than being a silent, unreported dead end.
     */
    public function testLocationPickerGpsFailureIsWiredToTheJoinFailureBeacon(): void
    {
        $source = $this->joinPhpSource();

        $this->assertStringContainsString(
            'onGPSError',
            $source,
            'join.php must pass an onGPSError callback to LocationPicker — otherwise a GPS '
                . 'failure on this required field is a silent, unreported registration blocker'
        );

        $onGpsErrorPos = strpos($source, 'onGPSError');
        $this->assertNotFalse($onGpsErrorPos);
        $callbackEnd = strpos($source, '}', $onGpsErrorPos);
        $this->assertNotFalse($callbackEnd, 'Could not locate the end of the onGPSError callback');
        $callback = substr($source, $onGpsErrorPos, $callbackEnd - $onGpsErrorPos + 1);

        $this->assertStringContainsString(
            'elanReportJoinFailure',
            $callback,
            'onGPSError must report through the shared join-form-beacon reporter '
                . '(window.elanReportJoinFailure), not a new/duplicate reporting path'
        );
        $this->assertStringContainsString(
            "'location_gps_failed'",
            $callback,
            'onGPSError must report a distinguishable reason so this failure mode is '
                . 'queryable separately from Turnstile failures'
        );
    }

    public function testLocationGpsFailedReasonIsAllowedByTheBeaconEndpoint(): void
    {
        $endpointPath = __DIR__ . '/../../../app/api/shared/join-failure-report.php';
        $source = file_get_contents($endpointPath);
        $this->assertIsString($source, 'join-failure-report.php must be readable');

        $this->assertStringContainsString(
            "'location_gps_failed'",
            $source,
            'The beacon endpoint must allowlist location_gps_failed as a valid reason, '
                . 'otherwise it gets silently normalized to "unknown" and loses its signal'
        );
    }
}
