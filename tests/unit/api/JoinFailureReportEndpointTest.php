<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for app/api/shared/join-failure-report.php — the client-side
 * join-failure beacon added for Issue #1690.
 *
 * This file is a top-level, procedural API endpoint script (require_once
 * '../../../users/init.php' at the top, ApiResponse::send() calls that
 * return `never` / exit the process) — it cannot be require()'d directly
 * inside a PHPUnit process without terminating the test run, and this repo
 * has no HTTP-level harness for app/api/ endpoints (confirmed: no existing
 * test in tests/unit/api/ executes an endpoint file directly; ApiResponseTest
 * only tests the ApiResponse class itself).
 *
 * Following the same source-text regression pattern used by
 * Issue1406RegressionTest for usersc/join.php, this test asserts the
 * endpoint's control flow (method check, then the rate limit — checked and
 * recorded, with no CSRF check since #2227 — then reason/detail
 * normalization, then logging with the correct category and
 * stage) directly against the live source. The pure reason/detail
 * normalization logic is also duplicated and exercised directly below
 * (dataProvider-driven) since it has no side effects and is safe to
 * evaluate in isolation — this at least proves the documented normalization
 * rules are self-consistent and gives fast feedback if they're ever
 * tightened/loosened, even though it can't prove the endpoint file wires
 * them up identically (the source-text tests below cover that gap).
 *
 * @issue 1690
 * @link https://github.com/elan-registry/registry/issues/1690
 * @link https://github.com/elan-registry/registry/issues/2227
 */
#[Group('regression')]
#[Group('fast')]
#[Group('api')]
final class JoinFailureReportEndpointTest extends TestCase
{
    private const ENDPOINT_PATH = __DIR__ . '/../../../app/api/shared/join-failure-report.php';

    private function endpointSource(): string
    {
        $source = file_get_contents(self::ENDPOINT_PATH);
        $this->assertIsString($source, 'app/api/shared/join-failure-report.php must be readable');
        return $source;
    }

    // ---------------------------------------------------------------
    // Source-text assertions: control flow, ordering, category/stage
    // ---------------------------------------------------------------

    public function testRejectsNonPostMethod(): void
    {
        $source = $this->endpointSource();

        $this->assertStringContainsString(
            "if (\$method !== 'POST')",
            $source,
            'The endpoint must reject non-POST requests'
        );
    }

    /**
     * The beacon carried the join page's render-time CSRF token until #2227.
     * In production that token went stale and the endpoint answered 403,
     * dropping the reports it exists to collect. The token is gone for good:
     * re-adding it would reintroduce the bug, so assert its absence rather
     * than only its non-use.
     */
    public function testHasNoCsrfCheck(): void
    {
        $source = $this->endpointSource();

        $this->assertStringNotContainsString(
            'Token::check(',
            $source,
            'The beacon must not validate a CSRF token — a render-time token can go stale and '
                . 'silently drop the report (#2227). Abuse is bounded by the enforced '
                . "'join_failure_beacon' rate limit under ADR-019 instead"
        );

        $this->assertStringNotContainsString(
            "Input::get('csrf')",
            $source,
            'The beacon must not read a csrf field at all — the client stopped sending one in #2227'
        );
    }

    /**
     * The client half of #2227. Before it, the beacon looked up the join
     * form's csrf input and returned early, with no warning, when the input
     * was missing — a silent drop of the report. The server test above cannot
     * see a token quietly returning on the client, so pin it here too.
     *
     * The source file keeps a comment that mentions CSRF, so it is checked
     * for the two code shapes that were removed. The minified build has no
     * comments and is what production serves, so it must not mention csrf at
     * all.
     */
    public function testBeaconClientSendsNoCsrfToken(): void
    {
        $jsDir = __DIR__ . '/../../../app/assets/js/';

        $js = file_get_contents($jsDir . 'join-form-beacon.js');
        $this->assertIsString($js, 'app/assets/js/join-form-beacon.js must be readable');
        $this->assertStringNotContainsString(
            'input[name="csrf"]',
            $js,
            'The beacon must not look up the join form\'s csrf input — its early return on a '
                . 'missing input silently dropped reports'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\bcsrf\s*:/',
            $js,
            'The beacon must not send a csrf field (#2227)'
        );

        $minJs = file_get_contents($jsDir . 'join-form-beacon.min.js');
        $this->assertIsString($minJs, 'app/assets/js/join-form-beacon.min.js must be readable');
        $this->assertStringNotContainsStringIgnoringCase(
            'csrf',
            $minJs,
            'The served build still references csrf — run npm run build after editing the source'
        );
    }

    /**
     * This is the test that would have caught the never-trips defect folded
     * into #2227: the endpoint called checkRateLimit() but never
     * recordRateLimit(). RateLimit::check() counts us_rate_limits rows and
     * only record() writes them, so the count stayed at 0 forever and the
     * configured limit could not trip. With CSRF removed this limit is the
     * only abuse control, so the record call must stay.
     */
    public function testRecordsAdmittedRequestsAgainstBeaconRateLimit(): void
    {
        $source = $this->endpointSource();

        $recordPos = strpos($source, "recordRateLimit('join_failure_beacon', true)");
        $this->assertNotFalse(
            $recordPos,
            "The endpoint must record admitted requests via recordRateLimit('join_failure_beacon', true) — "
                . 'checkRateLimit() alone counts rows that nothing writes, so the limit never trips'
        );

        // ApiResponse::send() exits, so code after the 429 send runs only for
        // admitted requests. Pinning the record call after it rejects every
        // wrong placement a plain "after the check" test would accept: in the
        // check's catch, or between the check and the refusal.
        $refusalSend = "ApiResponse::error(getRateLimitErrorMessage('join_failure_beacon'), 429)->send();";
        $refusalPos = strpos($source, $refusalSend);
        $this->assertNotFalse($refusalPos, 'Could not locate the 429 refusal');

        $this->assertGreaterThan(
            $refusalPos,
            $recordPos,
            'The attempt must be recorded only after the 429 refusal has had its chance to exit — '
                . 'anywhere earlier also records refused requests'
        );

        $recordSection = substr($source, $refusalPos + strlen($refusalSend), $recordPos - $refusalPos);
        $this->assertStringContainsString(
            'try {',
            $recordSection,
            'recordRateLimit() writes to the database and can throw — it needs its own try'
        );

        // Slice to the start of the next section rather than a fixed length,
        // so a comment added inside the catch cannot push the log call out of
        // the window and fail this test for the wrong reason.
        $nextSectionPos = strpos($source, '$allowedReasons', $recordPos);
        $this->assertNotFalse($nextSectionPos, 'Could not locate the section after the record block');
        $recordCatch = substr($source, $recordPos, $nextSectionPos - $recordPos);

        $this->assertStringContainsString(
            'rate limit record failed',
            $recordCatch,
            'A failed record must be logged as a record failure, not as a failed check'
        );
        $this->assertStringContainsString(
            'LogCategories::LOG_CATEGORY_SYSTEM_ERROR',
            $recordCatch,
            'A failed record is an infrastructure fault, not a registration event — log it as '
                . 'SystemError so it is not mixed in with the client-blocked registration rows'
        );
        $this->assertStringNotContainsString(
            '$rateLimitAllowed',
            $recordCatch,
            'A failed record must not change the admission decision the check already made'
        );

        $loggerPos = strpos($source, "logger(0, LogCategories::LOG_CATEGORY_REGISTRATION_FAILED,\n"
            . "    'join-failure-report: Client-side submission blocked");
        $this->assertNotFalse($loggerPos, 'Could not locate the client-blocked logger() call');

        $this->assertLessThan(
            $loggerPos,
            $recordPos,
            'The rate limit must be recorded before the beacon writes its log row'
        );
    }

    public function testRateLimitFailureReturns429(): void
    {
        $source = $this->endpointSource();

        $rateLimitPos = strpos($source, "checkRateLimit('join_failure_beacon')");
        $this->assertNotFalse($rateLimitPos);

        $reasonPos = strpos($source, '$allowedReasons');
        $this->assertNotFalse($reasonPos, 'Could not locate the reason-normalization block');

        $rateLimitBranch = substr($source, $rateLimitPos, $reasonPos - $rateLimitPos);

        $this->assertStringContainsString(
            'ApiResponse::error(getRateLimitErrorMessage(\'join_failure_beacon\'), 429)',
            $rateLimitBranch,
            'A rate-limited request must return HTTP 429 via ApiResponse::error()'
        );

        $this->assertStringContainsString(
            '!$rateLimitAllowed',
            $rateLimitBranch,
            'The 429 response must be gated on the rate-limit result, not the raw checkRateLimit() call — '
                . 'the raw call is wrapped in try/catch so a thrown error fails open instead of fataling '
                . '(see LocationService::rateLimiterAllows() for the same documented pattern)'
        );
    }

    public function testRateLimitCheckFailsOpenOnThrow(): void
    {
        $source = $this->endpointSource();

        $rateLimitPos = strpos($source, "checkRateLimit('join_failure_beacon')");
        $this->assertNotFalse($rateLimitPos, 'Could not locate the rate-limit check');

        $reasonPos = strpos($source, '$allowedReasons');
        $this->assertNotFalse($reasonPos, 'Could not locate the reason-normalization block');

        $rateLimitBranch = substr($source, $rateLimitPos - 400, $reasonPos - $rateLimitPos + 400);

        $this->assertStringContainsString(
            'try {',
            $rateLimitBranch,
            'checkRateLimit() opens a lazily-constructed \RateLimit, whose constructor can throw on a '
                . 'DB connection failure — this endpoint must not let that become an uncaught fatal'
        );
        $this->assertStringContainsString('catch (\Throwable $e)', $rateLimitBranch);
        $this->assertStringContainsString(
            '$rateLimitAllowed = true;',
            $rateLimitBranch,
            'A thrown rate-limiter error must fail open (treat the request as allowed), matching '
                . 'LocationService::rateLimiterAllows()\'s documented fail-open behavior'
        );
    }

    public function testAllowedReasonsEnumMatchesDocumentedSet(): void
    {
        $source = $this->endpointSource();

        $this->assertStringContainsString(
            "\$allowedReasons = ['turnstile_error', 'turnstile_expired', 'js_exception', "
                . "'turnstile_not_loaded', 'location_gps_failed']",
            $source,
            'The allowed-reasons enum must match the documented set of client-side failure reasons '
                . '(Turnstile failures plus location_gps_failed for the required-field GPS blocker)'
        );
    }

    public function testUnrecognizedReasonNormalizesToUnknown(): void
    {
        $source = $this->endpointSource();

        $this->assertStringContainsString(
            "if (!in_array(\$reason, \$allowedReasons, true)) {\n    \$reason = 'unknown';\n}",
            $source,
            'A reason value outside the allowed enum must be normalized to \'unknown\', not rejected outright'
        );
    }

    public function testDetailIsTruncatedTo300CharsViaMbSubstr(): void
    {
        $source = $this->endpointSource();

        $this->assertStringContainsString(
            "mb_substr(Input::raw('detail') ?? '', 0, 300)",
            $source,
            'detail must be truncated to 300 chars using a multibyte-safe substr, not the byte-unsafe substr()'
        );

        $this->assertStringNotContainsString(
            "substr(Input::raw('detail')",
            str_replace('mb_substr', '', $source),
            'detail truncation must use mb_substr, not the byte-unsafe substr()'
        );
    }

    public function testLogsWithRegistrationFailedCategoryAndClientBlockedStage(): void
    {
        $source = $this->endpointSource();

        $loggerPos = strpos($source, 'logger(0, LogCategories::LOG_CATEGORY_REGISTRATION_FAILED');
        $this->assertNotFalse(
            $loggerPos,
            'The endpoint must log via LogCategories::LOG_CATEGORY_REGISTRATION_FAILED'
        );

        $sendPos = strpos($source, "ApiResponse::success('Reported')->send()");
        $this->assertNotFalse($sendPos, 'Could not locate the success response');

        $call = substr($source, $loggerPos, $sendPos - $loggerPos);

        $this->assertStringContainsString("'stage'      => 'client_blocked'", $call);
        $this->assertStringContainsString("'reason'     => \$reason", $call);
        $this->assertStringContainsString("'detail'     => \$detail", $call);
        $this->assertStringContainsString("'user_agent' => \$user_agent ?? ''", $call);

        // Confirm all four metadata keys live inside THIS logger() call's
        // own metadata array, not just somewhere in the wider
        // logger()...send() window — bound the search to the metadata
        // array's own brackets (the first "[" after the logger() call
        // opens, up to its matching top-level "]" before the closing ");").
        $metadataStart = strpos($call, '[');
        $this->assertNotFalse($metadataStart, 'Could not locate the metadata array');
        $metadataEnd = strpos($call, ']);', $metadataStart);
        $this->assertNotFalse($metadataEnd, 'Could not locate the end of the metadata array');
        $metadataArray = substr($call, $metadataStart, $metadataEnd - $metadataStart);

        foreach (["'stage'", "'reason'", "'detail'", "'user_agent'"] as $key) {
            $this->assertStringContainsString(
                $key,
                $metadataArray,
                "Metadata key {$key} must be inside this logger() call's own array, not elsewhere in the file"
            );
        }
    }

    public function testLoggingHappensBeforeSuccessResponse(): void
    {
        $source = $this->endpointSource();

        $loggerPos = strpos($source, 'logger(0, LogCategories::LOG_CATEGORY_REGISTRATION_FAILED');
        $sendPos = strpos($source, "ApiResponse::success('Reported')->send()");

        $this->assertNotFalse($loggerPos);
        $this->assertNotFalse($sendPos);
        $this->assertLessThan(
            $sendPos,
            $loggerPos,
            'The failure must be logged before the success response is sent'
        );
    }

    public function testNotAddedToSecurePagePathArray(): void
    {
        // Per the implementation plan and CLAUDE.md convention: pure API
        // endpoints with no securePage() call are not added to
        // z_us_root.php's $path array. Confirm this endpoint has no
        // securePage() call (which would make omission from $path a bug).
        $source = $this->endpointSource();

        $this->assertStringNotContainsString(
            'securePage(',
            $source,
            'join-failure-report.php must not call securePage() — it is an anonymous, '
                . 'rate-limited endpoint with no CSRF token (ADR-019 diagnostic-log exception), '
                . 'consistent with other app/api/shared/ scripts'
        );
    }

    // ---------------------------------------------------------------
    // Pure logic checks: reason normalization / detail truncation,
    // evaluated directly (documents the intended behavior; the
    // source-text tests above confirm the endpoint file matches it).
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function reasonProvider(): array
    {
        return [
            'turnstile_error is allowed' => ['turnstile_error', 'turnstile_error'],
            'turnstile_expired is allowed' => ['turnstile_expired', 'turnstile_expired'],
            'js_exception is allowed' => ['js_exception', 'js_exception'],
            'turnstile_not_loaded is allowed' => ['turnstile_not_loaded', 'turnstile_not_loaded'],
            'location_gps_failed is allowed' => ['location_gps_failed', 'location_gps_failed'],
            'unrecognized string normalizes to unknown' => ['bogus_reason', 'unknown'],
            'empty string normalizes to unknown' => ['', 'unknown'],
            'sql-injection-shaped input normalizes to unknown' => ["'; DROP TABLE logs; --", 'unknown'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reasonProvider')]
    public function testReasonNormalizationLogic(string $input, string $expected): void
    {
        $allowedReasons = ['turnstile_error', 'turnstile_expired', 'js_exception', 'turnstile_not_loaded', 'location_gps_failed'];
        $reason = $input;
        if (!in_array($reason, $allowedReasons, true)) {
            $reason = 'unknown';
        }

        $this->assertSame($expected, $reason);
    }

    public function testDetailTruncationLogicCapsAt300Chars(): void
    {
        $detail = mb_substr(str_repeat('a', 500), 0, 300);

        $this->assertSame(300, mb_strlen($detail));
    }

    public function testDetailTruncationLogicPreservesShorterStrings(): void
    {
        $detail = mb_substr('short detail', 0, 300);

        $this->assertSame('short detail', $detail);
    }

    public function testDetailTruncationLogicIsMultibyteSafe(): void
    {
        // 300 multi-byte characters (each 3 bytes in UTF-8) — byte-based
        // substr(...,0,300) would cut mid-character; mb_substr must not.
        $input = str_repeat('€', 400);
        $detail = mb_substr($input, 0, 300);

        $this->assertSame(300, mb_strlen($detail));
        $this->assertSame(str_repeat('€', 300), $detail);
    }
}
