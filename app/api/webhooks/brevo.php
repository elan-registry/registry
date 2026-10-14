<?php

declare(strict_types=1);

use ElanRegistry\Car\BrevoWebhookEventProcessor;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailEventMatcher;
use ElanRegistry\Car\ProcessingResult;
use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\Exceptions\ExceptionLogSummary;
use ElanRegistry\LogCategories;

/**
 * Brevo webhook receiver
 *
 * Receives Brevo's delivery-status events (deliveries, bounces, blocks,
 * spam complaints, unsubscribes) for all registry mail (#2359). The event is
 * matched to its user by message ID, recorded against that user, and
 * escalated to a bounced/suppressed flag per the rules in EmailEventApplier
 * (which BrevoWebhookEventProcessor delegates to, and which the #1889
 * reconciliation job shares).
 *
 * NO `securePage()` AND NOT IN `$path`. Brevo is an external caller with no
 * UserSpice session, so this endpoint must stay reachable unauthenticated by
 * UserSpice's own session system. Authentication instead uses a static
 * bearer token (see below).
 *
 * AUTH: `Authorization: Bearer <token>`, compared with hash_equals() against
 * `$_ENV['BREVO_WEBHOOK_TOKEN']`. Reads `$_ENV`, not `getenv()` — this app's
 * `.env` is loaded via `Dotenv::createImmutable()` (users/init.php), which
 * populates `$_ENV`/`$_SERVER` but deliberately never calls `putenv()` (so
 * the integration test bootstrap's own `createMutable()` pre-population of
 * `$_ENV` isn't clobbered) — `getenv()` would therefore always return false
 * here, even with a correctly configured `.env`. Fails CLOSED: an empty/
 * missing value rejects every request rather than accepting everything.
 * The verification switch stops only the send batch, not this endpoint. To
 * stop it in an incident, see docs/development/EMAIL_SYSTEM.md, "Stop Event
 * Writes in an Incident".
 * Never logs the token itself, only a short hashed prefix, so a leaked log
 * line cannot leak the credential.
 *
 * RATE LIMITING: Two IP-scoped keys (usersc/includes/rate_limits.php):
 * - 'brevo_webhook': checked AFTER auth passes; gates entire request via 429.
 * - 'brevo_webhook_auth_failure': checked DURING auth-failure path to gate only
 *   whether the failure gets logged (401 response is never affected). Prevents
 *   log-table flooding from spammed invalid tokens. Both keys respect the core
 *   invariant: a rate-limiter failure can only become a throughput bypass,
 *   never an auth bypass.
 *
 * Gates, in order: method, auth, rate limit, Brevo prerequisites.
 *
 * HTTP status contract:
 *   | Situation                                         | Response |
 *   |---------------------------------------------------|----------|
 *   | Matched to one user, transaction committed        | 200      |
 *   | Not a delivery event (for example opened, click)  | 200, logged (event name only) |
 *   | Message not sent by this environment              | 200, no log |
 *   | Our message, no single user holds the address     | 200, logged (address hash prefix only). A car_verification-tagged event increments the unmatched counter |
 *   | Brevo prerequisites not met                       | 200, logged |
 *   | Malformed payload (incl. list body, bad address)  | 400, logged |
 *   | Stored data that cannot be parsed (for example a zero message time) | 400, logged (no message) |
 *   | Auth token missing/empty/wrong                    | 401, logged subject to brevo_webhook_auth_failure rate limit (hashed prefix only) |
 *   | Method is not POST                                | 405, no log |
 *   | Rate limit exceeded                               | 429      |
 *   | DB read or write failure                          | 500, logged |
 *   | Any other unhandled failure                       | 500, logged (no message) |
 *
 * No log line holds an email address, and no catch block logs an exception
 * message: CarRepository puts the address in its exception messages.
 *
 * Never acknowledges (2xx) before the er_email_events write commits — Brevo
 * does not retry a 2xx, so acking early on a write that then fails would
 * silently lose the event forever.
 *
 * No response body and no ApiResponse — Brevo parses no body, only the HTTP
 * status.
 *
 * @see docs/development/EMAIL_SYSTEM.md — Brevo webhook receiver contract
 * @see https://github.com/elan-registry/registry/issues/1887
 * @since v2.30.2
 */

require_once '../../../users/init.php';

/**
 * Reject the request with the given status, logging a message that never
 * includes the raw bearer token or full request body.
 */
function respondAndExit(int $status, ?string $logCategory = null, ?string $logMessage = null): never
{
    if ($logCategory !== null && $logMessage !== null) {
        logger(0, $logCategory, $logMessage);
    }
    http_response_code($status);
    exit;
}

// Brevo only ever POSTs. Rejecting anything else matches every other
// app/api/ endpoint's convention (see join-failure-report.php,
// verification-toggle.php) and closes off a GET-with-bearer-token request
// being logged/cached/replayed by an intermediary that saw the URL+headers.
if ($method !== 'POST') {
    respondAndExit(405);
}

// --- 1. Auth (must run before rate limiting — see file docblock) ----------
$authHeader = Server::get('HTTP_AUTHORIZATION', '');
$providedToken = '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $providedToken = substr($authHeader, 7);
}

$expectedToken = (string) ($_ENV['BREVO_WEBHOOK_TOKEN'] ?? '');

// Fails closed: an empty configured token rejects every request rather than
// accepting everything (hash_equals('', '') would otherwise return true).
if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    $tokenPrefix = $providedToken === '' ? '(empty)' : substr(hash('sha256', $providedToken), 0, 8);

    // Gate ONLY the log line, never the 401 itself (see file docblock) — an
    // attacker spamming invalid tokens must still always get 401, but must
    // not be able to grow the `logs` table unboundedly by doing so.
    // Default: never suppress unless the limiter genuinely says so.
    $shouldLog = true;
    try {
        /** @var array<string, array<string, int>> $rateLimits */
        $rateLimits = [];
        require __DIR__ . '/../../../usersc/includes/rate_limits.php';
        if (!isset($rateLimits['brevo_webhook_auth_failure'])) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
                "Brevo webhook: 'brevo_webhook_auth_failure' rate-limit config is missing — running unthrottled.");
        }

        $shouldLog = checkRateLimit('brevo_webhook_auth_failure');

        // Record every attempt as success=false unconditionally — this key's
        // job is counting the RATE of failures, not gating a response, so
        // check() must see every attempt (logged or suppressed) to compute the
        // rolling window correctly. Recording conditionally on $shouldLog would
        // let the count "coast" once suppression starts, defeating the limit.
        recordRateLimit('brevo_webhook_auth_failure', false);
    } catch (\Throwable $e) {
        // Broad by design: this path's ONLY contract is "always respond 401."
        // A rate-limiter or config failure here must never become an uncaught
        // fatal — that would turn a should-be-401 into a 500, which Brevo
        // treats as retryable and redelivers, amplifying exactly the traffic
        // this rate limit exists to bound. (RateLimit::__construct() throws a
        // bare \Exception when its table can't be created, and this is that
        // instance's first construction site.) Fail open toward MORE logging,
        // never toward fewer 401s, matching this file's broader invariant that
        // a limiter failure can only ever become a throughput/logging bypass,
        // never an auth bypass.
        $shouldLog = true;
        try {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo webhook: auth-failure rate limit failed (%s), failing open (will log).',
                ExceptionLogSummary::of($e)
            ));
        } catch (\Throwable) {
            // Logging itself is down. The 401 below still must fire —
            // nothing else to do here.
        }
    }

    respondAndExit(
        401,
        $shouldLog ? LogCategories::LOG_CATEGORY_SECURITY : null,
        $shouldLog ? "Brevo webhook: rejected request with invalid/missing bearer token (hash prefix: {$tokenPrefix})" : null
    );
}

// --- 2-5. Rate limit, gates, parse, and process — wrapped in a single
// top-level catch. This is a request boundary whose entire job is to turn
// any failure into a logged, retryable status: without it, an exception
// thrown by anything below (a dropped DB connection surfacing as a raw
// PDOException/\Exception rather than the CarDatabaseException
// BrevoWebhookEventProcessor::process() itself catches, or a failed
// recordRateLimit() insert, for instance) would escape as an uncaught
// fatal — Brevo still gets a 5xx and retries, but with zero log line, which
// is indistinguishable from the endpoint being silently broken. The
// rate-limit block is inside this boundary (not before it, as an earlier
// version had it) for exactly that reason: an uncaught exception from
// recordRateLimit() must not skip past this handler. Broad-by-design, not a
// lazy catch.
try {
    // Rate limit (IP-scoped). recordRateLimit() is required, not optional:
    // RateLimit::check()'s total_max/ip_max paths count only rows written
    // by record() — without it, checkRateLimit() always sees zero attempts
    // and this limit can never trip. See send-owner-email.php for the same
    // record-on-both-outcomes shape.
    //
    // Config-missing is a distinct, louder failure from a genuine limiter
    // exception: RateLimit::check() returns true with NO exception at all
    // when the 'brevo_webhook' key is absent from $rateLimits (see
    // usersc/includes/rate_limits.php), so an accidentally-deleted config
    // block would otherwise look identical to healthy, unthrottled traffic.
    // Checking for the key explicitly turns that into a logged condition.
    /** @var array<string, array<string, int>> $rateLimits */
    $rateLimits = [];
    require __DIR__ . '/../../../usersc/includes/rate_limits.php';
    if (!isset($rateLimits['brevo_webhook'])) {
        logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
            "Brevo webhook: 'brevo_webhook' rate-limit config is missing — running unthrottled.");
    }

    try {
        $rateLimitAllowed = checkRateLimit('brevo_webhook');
    } catch (\PDOException | \RuntimeException $e) {
        // Narrowed to storage-layer failures (a dropped connection or a
        // failed query inside RateLimit itself) so this catch cannot also
        // swallow a \TypeError/\Error from a genuinely broken
        // rate_limits.php — that class of bug now surfaces via the outer
        // catch below instead of silently running unthrottled forever.
        logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
            'Brevo webhook: rate limit check failed (%s), failing open.',
            ExceptionLogSummary::of($e)
        ));
        $rateLimitAllowed = true;
    }
    if (!$rateLimitAllowed) {
        recordRateLimit('brevo_webhook', false);
        respondAndExit(429);
    }
    recordRateLimit('brevo_webhook', true);

    // --- 3. Brevo prerequisites ---------------------------------------------
    // The verification switch stops only the send batch. Events from all
    // registry mail still count while it is off, because the switch does
    // not stop other mail (for example join or password-reset mail).
    $settings = new VerificationSettings(dbi());

    // brevoReady() is brevoNotReadyReason() === null. One call gives the gate
    // and the reason for the log from the same check.
    $brevoNotReadyReason = $settings->brevoNotReadyReason();
    if ($brevoNotReadyReason !== null) {
        respondAndExit(
            200,
            LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
            'Brevo webhook received but Brevo prerequisites are not met — event dropped. Reason: '
                . $brevoNotReadyReason
        );
    }

    // --- 4. Parse the payload -----------------------------------------------
    $rawBody = file_get_contents('php://input');
    $decoded = $rawBody !== false ? json_decode($rawBody, true) : null;

    if ($rawBody === false || $rawBody === '' || json_last_error() !== JSON_ERROR_NONE) {
        respondAndExit(
            400,
            LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
            'Brevo webhook: request body is not valid JSON — ' . json_last_error_msg()
        );
    }

    // --- 5. Process -----------------------------------------------------
    $repo = new CarRepository(dbi());
    $verificationManager = new CarVerificationManager($repo);
    $applier = new EmailEventApplier($repo, $verificationManager);
    $processor = new BrevoWebhookEventProcessor(new EmailEventMatcher($repo), $applier);

    $result = $processor->process($decoded);

    switch ($result) {
        case ProcessingResult::MATCHED_AND_RECORDED:
            respondAndExit(200);
            // no break — respondAndExit() never returns

        case ProcessingResult::IGNORED:
            respondAndExit(200);
            // no break — respondAndExit() never returns

        case ProcessingResult::NO_USER_MATCH:
            // The counter measures verification recipients that no owner
            // holds, so other mail does not count. The processor already
            // logged the event.
            if (BrevoWebhookEventProcessor::hasVerificationTag($decoded)) {
                // incrementUnmatchedRecipientCounter() never throws and logs
                // its own failure.
                $settings->incrementUnmatchedRecipientCounter();
            }
            respondAndExit(200);
            // no break — respondAndExit() never returns

        case ProcessingResult::MALFORMED:
            respondAndExit(
                400,
                LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
                'Brevo webhook: payload is malformed (not a JSON object, a missing required field, a bad address,'
                . ' a message ID over 255 bytes, or a stored value that the match or apply step cannot parse).'
            );
            // no break — respondAndExit() never returns

        case ProcessingResult::WRITE_FAILURE:
            respondAndExit(
                500,
                LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
                'Brevo webhook: a database write failed while processing this event.'
            );
            // no break — respondAndExit() never returns

        default:
            // Unreachable today — every current ProcessingResult case is
            // mapped above. Exists so that adding a case to the enum without
            // updating this switch fails loudly (a retryable 5xx) instead of
            // falling through to an implicit 200 that Brevo will never retry,
            // silently losing the event.
            respondAndExit(
                500,
                LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
                'Brevo webhook: unmapped ProcessingResult — endpoint status contract is out of date.'
            );
    }
} catch (\Throwable $e) {
    respondAndExit(
        500,
        LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
        sprintf(
            'Brevo webhook: unhandled %s while processing event.',
            ExceptionLogSummary::of($e)
        )
    );
}
