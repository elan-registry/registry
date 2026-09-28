<?php
declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\Input;
use ElanRegistry\LogCategories;

/**
 * AJAX Endpoint: Join Form Client-Side Failure Beacon
 *
 * Reports a join submission blocked entirely client-side (Turnstile
 * failed to load/error/expire, or a JS exception before submit) — cases
 * where the POST to join.php never happens and would otherwise leave
 * zero server-side trace.
 *
 * Anonymous and deliberately CSRF-free. The beacon used to carry the join
 * page's render-time CSRF token. In production that token went stale in a
 * session that went on to reset a password and log in, and the endpoint
 * answered 403, dropping the very reports it exists to collect (#2227). The
 * cause of the staleness is unexplained (see ADR-019, Notes).
 *
 * Because the endpoint reads no session state, checks no identity and
 * appends one fixed-shape log row with user_id 0, a forged cross-site
 * request can do nothing an attacker's own direct request could not — the
 * token bought no protection. Abuse is bounded instead by this endpoint's
 * own enforced 'join_failure_beacon' rate limit, under ADR-019's
 * anonymous-diagnostic-log exception.
 *
 * @package ElanRegistry
 * @since v2.29.2
 * @link https://github.com/elan-registry/registry/issues/1690
 * @link https://github.com/elan-registry/registry/issues/2227
 * @link https://github.com/elan-registry/registry/blob/main/docs/development/adr/ADR-019-no-csrf-on-public-read-only-endpoints.md
 */

require_once '../../../users/init.php';

// Only allow POST requests
if ($method !== 'POST') {
    ApiResponse::error('Method not allowed', 405)->send();
}

// Uses its own dedicated rate limit ('join_failure_beacon'), deliberately
// separate from 'registration_attempt' — sharing that tight bucket
// (ip_max=5/hr) would let beacon traffic (Turnstile retries, GPS failures,
// JS exceptions — none of them a real registration attempt) exhaust the cap
// for every visitor behind a shared/NAT IP before any of them could submit
// the form. See usersc/includes/rate_limits.php for the current values.
//
// checkRateLimit() lazily constructs \RateLimit on first call per request,
// whose constructor opens a database connection and can throw — the same
// failure mode LocationService::rateLimiterAllows() already documents and
// fails open around. This endpoint's whole purpose is to never lose a
// server-side trace of a failed join attempt, so a DB hiccup here must not
// turn into an uncaught fatal; fail open (treat as allowed) and log instead.
//
// Every admitted request must also be recorded: RateLimit::check() counts
// us_rate_limits rows, and only record() writes them, so a check-without-record
// endpoint can never trip its own limit (this one never did until #2227 made
// the limit the only abuse control). Recording successes only — as the ADR-019
// endpoints do — makes total_max (per IP) the operative cap; ip_max counts
// failures, of which there are none here. See the comment above cars_list in
// usersc/includes/rate_limits.php.
try {
    $rateLimitAllowed = checkRateLimit('join_failure_beacon');
} catch (\Throwable $e) {
    logger(0, LogCategories::LOG_CATEGORY_REGISTRATION_FAILED, 'join-failure-report: rate limit check failed, failing open: ' . $e->getMessage());
    $rateLimitAllowed = true;
}
if (!$rateLimitAllowed) {
    ApiResponse::error(getRateLimitErrorMessage('join_failure_beacon'), 429)->send();
}

// Separate from the check's try so a failed write is logged as what it is:
// the request is still admitted, but it is not counted, and repeated
// failures here stop the limit from tripping.
try {
    recordRateLimit('join_failure_beacon', true);
} catch (\Throwable $e) {
    logger(0, LogCategories::LOG_CATEGORY_REGISTRATION_FAILED, 'join-failure-report: rate limit record failed, request not counted toward the limit: ' . $e->getMessage());
}

// Client sends a short enum reason, not free-text, to keep log payloads
// bounded — this is not an arbitrary-text logging endpoint.
$allowedReasons = ['turnstile_error', 'turnstile_expired', 'js_exception', 'turnstile_not_loaded', 'location_gps_failed'];
$reason = Input::raw('reason') ?? '';
if (!in_array($reason, $allowedReasons, true)) {
    $reason = 'unknown';
}
$detail = mb_substr(Input::raw('detail') ?? '', 0, 300);

logger(0, LogCategories::LOG_CATEGORY_REGISTRATION_FAILED,
    'join-failure-report: Client-side submission blocked — ' . $reason,
    [
        'stage'      => 'client_blocked',
        'reason'     => $reason,
        'detail'     => $detail,
        'user_agent' => $user_agent ?? '',
    ]);

ApiResponse::success('Reported')->send();
