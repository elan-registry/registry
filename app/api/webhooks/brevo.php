<?php

declare(strict_types=1);

use ElanRegistry\Car\BrevoWebhookEventProcessor;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\ProcessingResult;
use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\LogCategories;

/**
 * Brevo webhook receiver
 *
 * Receives Brevo's delivery-status events (bounces, blocks, spam complaints,
 * deliveries, opens) for transactional verification emails and records them
 * against the matching car(s), escalating to a bounced/suppressed flag per
 * the rules in BrevoWebhookEventProcessor.
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
 * Never logs the token itself, only a short hashed prefix, so a leaked log
 * line cannot leak the credential.
 *
 * RATE LIMITING: IP-scoped ('brevo_webhook' in usersc/includes/rate_limits.php),
 * checked only AFTER auth passes — so a rate-limiter failure (which fails
 * open, matching join-failure-report.php's pattern) can only ever become a
 * throughput bypass, never an auth bypass.
 *
 * HTTP status contract:
 *   | Situation                                    | Response |
 *   |-----------------------------------------------|----------|
 *   | Parsed and durably written                    | 2xx      |
 *   | No recognized tag                             | 2xx, logged |
 *   | Recipient matches no car                      | 2xx, logged, unmatched counter incremented |
 *   | Malformed/unparseable payload (incl. list body)| 4xx, logged |
 *   | Auth token missing/empty/wrong                | 4xx, logged (hashed prefix only) |
 *   | DB write failure                              | 5xx — the only retryable case |
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
    respondAndExit(
        401,
        LogCategories::LOG_CATEGORY_SECURITY,
        "Brevo webhook: rejected request with invalid/missing bearer token (hash prefix: {$tokenPrefix})"
    );
}

// --- 2. Rate limit (IP-scoped; fails open on limiter exception, matching
// join-failure-report.php's pattern). recordRateLimit() is required, not
// optional: RateLimit::check()'s total_max/ip_max paths count only rows
// written by record() — without it, checkRateLimit() always sees zero
// attempts and this limit can never trip. See send-owner-email.php for the
// same record-on-both-outcomes shape. -----------------------------------
try {
    $rateLimitAllowed = checkRateLimit('brevo_webhook');
} catch (\Throwable $e) {
    logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
        'Brevo webhook: rate limit check failed (%s), failing open: %s',
        get_class($e),
        $e->getMessage()
    ));
    $rateLimitAllowed = true;
}
if (!$rateLimitAllowed) {
    recordRateLimit('brevo_webhook', false);
    respondAndExit(429);
}
recordRateLimit('brevo_webhook', true);

// --- 3-5. Gates, parse, and process — wrapped in a top-level catch. This is
// a request boundary whose entire job is to turn any failure into a logged,
// retryable status: without it, an exception thrown by anything below (a
// dropped DB connection surfacing as a raw PDOException/\Exception rather
// than the CarDatabaseException BrevoWebhookEventProcessor::process() itself
// catches, for instance) would escape as an uncaught fatal — Brevo still
// gets a 5xx and retries, but with zero log line, which is indistinguishable
// from the endpoint being silently broken. Broad-by-design, not a lazy catch.
try {
    $settings = new VerificationSettings(dbi());

    if (!$settings->isEnabled()) {
        // Verification is switched off site-wide: accept and drop, silently —
        // this is an expected, common state, not worth a log line per hit.
        respondAndExit(200);
    }

    if (!$settings->brevoReady()) {
        respondAndExit(
            200,
            LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
            'Brevo webhook received but Brevo prerequisites are not met — event dropped.'
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
    $processor = new BrevoWebhookEventProcessor($repo, $verificationManager);

    $result = $processor->process($decoded);

    switch ($result) {
        case ProcessingResult::MATCHED_AND_RECORDED:
            respondAndExit(200);
            // no break — respondAndExit() never returns

        case ProcessingResult::NO_TAG_MATCH:
            respondAndExit(
                200,
                LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
                'Brevo webhook: event carries no recognized verification-system tag — dropped.'
            );
            // no break — respondAndExit() never returns

        case ProcessingResult::NO_CAR_MATCH:
            $settings->incrementUnmatchedRecipientCounter();
            respondAndExit(
                200,
                LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
                'Brevo webhook: event recipient matched no car — dropped, unmatched counter incremented.'
            );
            // no break — respondAndExit() never returns

        case ProcessingResult::MALFORMED:
            respondAndExit(
                400,
                LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
                'Brevo webhook: payload is malformed (not a JSON object, or missing a required field).'
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
            'Brevo webhook: unhandled %s while processing event: %s',
            get_class($e),
            $e->getMessage()
        )
    );
}
