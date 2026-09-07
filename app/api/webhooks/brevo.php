<?php

declare(strict_types=1);

use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\LogCategories;

/**
 * Brevo webhook receiver — PLACEHOLDER for #1887
 *
 * PLACEHOLDER for #1887 — this stub only gates on the verification-system switch
 * and Brevo readiness; it does not parse events, verify signatures, or persist
 * anything. Replace this file entirely when #1887 is implemented; do not extend
 * it in place.
 *
 * Brevo POSTs one event per request. Until #1887 lands, every request is
 * accepted and discarded with an empty HTTP 200 — Brevo needs no response body,
 * and a non-2xx would make it retry the event indefinitely.
 *
 * NO `securePage()` AND NOT IN `$path`. Brevo is an external caller with no
 * UserSpice session, so this endpoint must stay reachable unauthenticated.
 * Signature/IP verification is #1887's job; this stub performs none.
 *
 * NO RATE LIMITING — deliberate for this stub, not an oversight. It runs at
 * most one cheap DB read (`isEnabled()`) when verification is off, and up to
 * three once it's on, and verification defaults off (see
 * `er_verification_settings`). #1887's acceptance criteria requires rate
 * limiting on the real receiver (see `usersc/includes/rate_limits.php` for
 * the project convention) plus an ADR-019 entry; do not add limiting here.
 *
 * @see docs/development/EMAIL_SYSTEM.md — "Brevo Webhooks — Verified Behaviour (#1871)"
 * @see https://github.com/elan-registry/registry/issues/1887
 * @since v2.30.2
 */

require_once '../../../users/init.php';

http_response_code(200);

$settings = new VerificationSettings(dbi());

if (!$settings->isEnabled()) {
    // Verification is switched off site-wide: accept and drop, silently. Logging
    // every dropped hit would be noise; #1887 owns real per-event logging.
    exit;
}

if (!$settings->brevoReady()) {
    // Enabled but misconfigured — worth exactly one log line, since this is the
    // state that signals a genuine misconfiguration rather than a deliberate off.
    logger(0, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, 'Webhook received but Brevo prerequisites are not met — event dropped.');
    exit;
}

// Enabled and ready: this is where #1887 will verify the signature, parse the
// event, and write to `email_events`. Until it lands, real events are ACCEPTED
// AND DISCARDED here — Brevo's dashboard sees 200s and the admin tab's "Last
// webhook received" row is a static placeholder, so without a log trail an
// admin who enables verification ahead of #1887 has no way to discover this
// endpoint is a black hole. Log at most once per hour so real traffic can be
// discovered without flooding `logs`.
$dropNotice = 'Brevo webhook events are being ACCEPTED AND DISCARDED — the receiver is a placeholder until #1887 lands. Bounces/unsubscribes are not being recorded.';
$db = dbi();
$db->query(
    "SELECT 1 FROM logs WHERE logtype = ? AND lognote = ? AND logdate > (NOW() - INTERVAL 1 HOUR) LIMIT 1",
    [LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, $dropNotice]
);
if ($db->error() || !is_object($db->first())) {
    logger(0, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, $dropNotice);
}
exit;
