<?php

declare(strict_types=1);

/**
 * verify_car.php — public car verification landing page
 *
 * The destination for the Verify, Sold, and opt-out links in the periodic
 * owner verification email. An owner arrives here from their mail client,
 * almost always logged out, and can confirm their car's record is still
 * accurate, report that the car has been sold, opt out of future
 * verification emails, or jump to the full edit form.
 *
 * SCOPE ASYMMETRY (security-relevant): Verify and Sold only ever mutate the
 * ONE car the vericode resolved to. Opt-out is different — it is an
 * owner-level decision ("stop emailing me"), so its effect fans out across
 * EVERY car the resolved owner has, not just the car named by the vericode.
 * $ownerId is derived exclusively from the vericode lookup (never from any
 * POST field), so this fan-out is scoped correctly, but the scope itself is
 * wider than the other two actions and that distinction matters when
 * reasoning about what a single compromised/guessed vericode can affect.
 *
 * NO `securePage()` AND NOT IN `$path`. The visitor has no UserSpice session
 * — they clicked a link in an email. Authentication is instead the vericode
 * carried in the URL, matched against the HMAC-SHA256 hash stored in
 * `cars.vericode`. `app/api/webhooks/brevo.php` is the only other page in the
 * codebase with this shape, and this file follows its ordering: authenticate
 * (resolve the vericode) BEFORE rate limiting or anything session-reliant, so
 * a rate-limiter failure can only ever become a throughput bypass, never an
 * auth bypass. Unlike brevo.php, this page renders full HTML through the site
 * template — `elanregistry_prep.php` and the chrome below it have no
 * `securePage()` or login-state dependency (usersc/login.php renders the same
 * chrome pre-authentication).
 *
 * NO CSRF TOKEN — THIS IS DELIBERATE, DO NOT "FIX" IT.
 * A CSRF token must be bound to a session, and there is no session to bind
 * one to: the browser opening this link has never authenticated with
 * UserSpice. The vericode IS the credential, and it is unguessable (128 bits
 * of `random_bytes` entropy, hashed at rest), so an attacker who could forge
 * a cross-site POST here would already need the secret that authorizes the
 * action in the first place. Adding `Token::check()` would break every real
 * owner's link — the one arriving from the email has no token to send —
 * without closing any attack. The defenses that do apply are enforced
 * instead: GET never mutates, the vericode format is validated before any DB
 * access, attempts are rate-limited per-token, and the owner/car(s) mutated
 * are always those the vericode resolves to server-side — the vericode's car
 * for Verify/Sold, the vericode's resolved owner (and every car they have,
 * see the SCOPE ASYMMETRY note above) for opt-out — never an id read from the
 * POST body.
 *
 * NO ENUMERATION SIGNAL. A malformed vericode, an unknown one, an expired
 * one, and one whose car has since become ownerless all render the identical
 * generic "expired or invalid link" page. A prober must not be able to tell
 * which case they hit.
 *
 * @see docs/plans/issue-1881-verify-car-landing-page.md
 * @see https://github.com/elan-registry/registry/issues/1881
 * @see https://github.com/elan-registry/registry/issues/1882
 * @see https://github.com/elan-registry/registry/issues/1883
 * @since v2.30.3
 */

$pageTitle = 'Verify Your Car';
$pageDescription = 'Confirm your Lotus Elan registry record is up to date, or tell us it has been sold.';
// The URL carries the vericode credential in its query string, which
// head_tags.php would otherwise republish in a canonical link and og:url tag
// on an "index, follow" page — handing the secret to any crawler that
// reaches it. noindex closes that off; nothing about this page belongs in a
// search index regardless.
$pageRobots = 'noindex, follow';

require_once '../../users/init.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\Input;
use ElanRegistry\LogCategories;

/** Verification links stop working this many days after the email was sent. */
const VERIFY_LINK_TTL_DAYS = 60;

/** Earliest plausible sale date for a car whose build year is unknown. */
const VERIFY_FALLBACK_MIN_YEAR = 1962;

/**
 * Render the generic expired/invalid-link page and stop.
 *
 * Every rejection funnels through here — unparseable vericode, unknown
 * vericode, expired link, ownerless car, rate-limited request — so that no
 * response distinguishes the cases for a prober. Only the HTTP status varies
 * (429 for a throttled request), which is a transport-level fact a prober
 * already controls by making the requests.
 */
function renderInvalidLink(int $httpStatus = 404): never
{
    global $verifyNoticeIcon, $verifyNoticeHeading, $verifyNoticeBody, $verifyNoticeState;

    http_response_code($httpStatus);

    $verifyNoticeState   = 'invalid';
    $verifyNoticeIcon    = 'fa-link-slash';
    $verifyNoticeHeading = 'This link has expired or is no longer valid';
    $verifyNoticeBody    = 'Verification links work for ' . VERIFY_LINK_TTL_DAYS
        . ' days from the date we emailed them, and this one is past that — or it '
        . 'was never a valid link. Nothing is wrong with your car\'s record.';

    renderVerifyPage(__DIR__ . '/../views/cars/_verify_notice.php');
}

/**
 * Render the "we couldn't complete that" page and stop.
 *
 * Distinct from renderInvalidLink(): this is reached only AFTER the vericode
 * has already authenticated and a write was attempted — the visitor has
 * proven they hold the credential, so there is no prober to defend against
 * here and no enumeration-resistance reason to reuse the generic "expired or
 * invalid link" copy. Telling an owner whose opt-out (or verify/sold) write
 * failed that "nothing is wrong with your car's record" would be an
 * affirmatively false statement — the write did not happen and, for
 * opt-out specifically, they will keep receiving the emails they just asked
 * to stop. This page instead says plainly that something failed on our end
 * and that the link is still good to retry.
 *
 * @param string $whatFailed One sentence naming what didn't complete, e.g.
 *                           'We could not record your opt-out.' Concatenated
 *                           directly into the notice body — pass only static,
 *                           developer-authored copy, never request-derived text.
 */
function renderActionFailed(string $whatFailed): never
{
    global $verifyNoticeIcon, $verifyNoticeHeading, $verifyNoticeBody, $verifyNoticeState;

    http_response_code(500);

    $verifyNoticeState   = 'error';
    $verifyNoticeIcon    = 'fa-triangle-exclamation';
    $verifyNoticeHeading = 'Something went wrong on our end';
    $verifyNoticeBody    = $whatFailed . ' Your link is still valid — please try again in a few minutes. '
        . 'If it keeps happening, email the registrar at registrar@elanregistry.org and we\'ll sort it out.';

    renderVerifyPage(__DIR__ . '/../views/cars/_verify_notice.php');
}

/**
 * Render one content partial inside the full site template, then stop.
 *
 * The template include is deferred to here (rather than running at the top of
 * the file, as most pages do) so that every rejection path above can respond
 * without ever loading page chrome.
 *
 * @param string $partial Absolute path to the content partial to include
 */
function renderVerifyPage(string $partial): never
{
    // NOTE: this list must stay complete. Two prior versions each omitted one
    // entry ($settings, then $usplugins/$currentPage) and every render (any
    // state, GET or POST) fatal'd or warned inside the shared template chrome
    // — each caught only by exercising the actual render (integration tests
    // for the first; a live manual check for the second), not by inspection
    // (issue #1881). This list was built by grepping every file in the
    // elanregistry_prep.php include chain (usersc/includes/elanregistry_prep.php,
    // users/includes/template/{prep,header3_must_include}.php,
    // usersc/templates/customizer/{header,file_nav_custom,navigation}.php,
    // usersc/includes/head_tags.php) for every variable each one reads but
    // does not itself assign — the ones below are the required (non-isset()-
    // guarded) results. All are set at file scope well before this function
    // ever runs: $abs_us_root/$us_url_root/$settings/$user/$usplugins/
    // $currentPage by users/init.php and its includes; $host/$current_url/
    // $current_origin/$php_self by usersc/includes/server_globals.php
    // (loaded by users/init.php); $pageTitle/$pageDescription/$pageRobots at
    // the very top of this file. The remainder are this page's own
    // render-context variables.
    global $abs_us_root, $us_url_root, $settings, $user, $usplugins, $currentPage,
           $pageTitle, $pageDescription, $pageRobots, $host, $current_url, $current_origin,
           $php_self,
           $verifyCar, $verifyPhoto, $verifyCode,
           $verifySelfUrl, $verifyEditUrl, $soldDateValue, $soldDateMin, $soldDateMax,
           $soldDateError, $verifyNoticeIcon, $verifyNoticeHeading, $verifyNoticeBody,
           $verifyNoticeState, $optOutCarCount, $optOutAlreadySuppressed;

    // head_tags.php echoes $current_url verbatim into <link rel="canonical">,
    // og:url, and twitter:url — which would otherwise republish the vericode
    // credential in the page's own markup (readable via "view source" or any
    // link-preview/social-unfurl bot, independent of the noindex directive
    // above, which only stops search-engine crawling/indexing). Strip the
    // query string here, before elanregistry_prep.php renders head_tags.php,
    // so every rendered state shares one vericode-free canonical identity.
    $current_url = $current_origin . $us_url_root . 'app/verify/verify_car.php';

    require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

    echo '<div class="page-wrapper"><div class="container"><div class="row justify-content-center">'
        . '<div class="col-12 col-lg-8">';
    include $partial;
    echo '<p class="text-muted small text-center mt-3">'
        . 'This link came from a verification email we sent you. It works without signing in.'
        . '</p>';
    echo '</div></div></div></div>';

    require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php';
    exit;
}

// --- 1. Vericode extraction + format validation --------------------------
// Runs before ANY database access or rate-limit bookkeeping: a vericode that
// cannot possibly match a stored hash must not cost a query or a rate-limit
// row. generateVerificationCode() emits bin2hex(random_bytes(16)), so the
// only valid shape is 32 lowercase hex characters.
$verifyCode = Input::raw('vericode') ?? '';

if (!preg_match('/^[0-9a-f]{32}$/', $verifyCode)) {
    renderInvalidLink();
}

// --- 2. Rate limiting (token-scoped) -------------------------------------
// Scoped to the vericode itself as well as IP: an attacker grinding
// candidate codes presents a fresh token every request, so token_max alone
// does not bound that threat — ip_max and total_max are what actually cap a
// single grinder, since IP is far more expensive to rotate than a guess is
// to make. Token-scoping instead bounds repeated guessing FROM one IP
// against one specific car's code (e.g. a credential-stuffing-style replay),
// and — now that recording below reflects real lookup outcomes rather than
// the throttle decision — protects a legitimate owner's own link from being
// grief-guessed by someone else sharing their network.
//
// Config-missing is a distinct, louder failure than a limiter exception:
// RateLimit::check() returns true with NO exception when the key is absent
// from $rateLimits, so a deleted config block would otherwise be
// indistinguishable from healthy, unthrottled traffic.
//
// IMPORTANT: this guard must load the FRAMEWORK entry point
// (users/includes/rate_limits.php), not usersc/includes/rate_limits.php
// directly. RateLimit::__construct() reads `global $rateLimits` and only
// loads config itself `if (!isset($rateLimits))`. At this file's scope
// `$rateLimits` IS that global — assigning it here (even to run the guard)
// would make RateLimit::__construct() see it as already-loaded and skip
// loading users/includes/rate_limits.php entirely, silently discarding the
// framework defaults every other action's config depends on for the rest of
// the request. Loading the framework file (which itself pulls in the
// usersc/ override at its tail, see users/includes/rate_limits.php:148-149)
// avoids that: it's the same file RateLimit::__construct() would have
// loaded anyway, so no truncation occurs.
if (!isset($rateLimits['verification_code_attempt'])) {
    require_once $abs_us_root . $us_url_root . 'users/includes/rate_limits.php';
}
if (!isset($rateLimits['verification_code_attempt'])) {
    logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
        "verify_car.php: 'verification_code_attempt' rate-limit config is missing — running unthrottled.");
}

try {
    $rateLimitAllowed = checkRateLimit('verification_code_attempt', null, null, ['token' => $verifyCode]);
} catch (\PDOException | \RuntimeException $e) {
    // Narrowed to storage-layer failures so this cannot also swallow a
    // \TypeError/\Error from a genuinely broken rate_limits.php.
    logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
        'verify_car.php: rate limit check failed (%s), failing open: %s',
        get_class($e),
        $e->getMessage()
    ));
    $rateLimitAllowed = true;
}

if (!$rateLimitAllowed) {
    // recordRateLimit() is required, not optional: without it, total_max
    // (which counts every row regardless of outcome) can never trip. The
    // success flag here is `false` — this request never got to resolve, so
    // by the same failed-attempt semantics the successful-lookup recording
    // below uses, it counts as one — which also keeps token_max/ip_max
    // (success=0-only counters) climbing for the duration of a throttle,
    // making it self-sustaining across its window rather than resetting.
    recordRateLimit('verification_code_attempt', false, null, null, ['token' => $verifyCode]);
    // Same page as every other rejection — a throttled prober must not learn
    // that they were throttled rather than simply wrong.
    renderInvalidLink(429);
}

// --- 3. Car lookup -------------------------------------------------------
$repo = new CarRepository(dbi());

try {
    $verifyCar = $repo->findByVerificationCode($verifyCode);
} catch (ElanRegistryException $e) {
    logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
        'verify_car.php: verification code lookup failed: ' . $e->getMessage());
    // Deliberately NOT recorded as an attempt: this is a storage fault on
    // our side, not a failed guess by the visitor. Counting it toward
    // token_max/ip_max would let a database blip throttle the legitimate
    // owner whose vericode happened to trigger it — the same
    // fail-open-without-punishing-the-caller reasoning as the
    // checkRateLimit() PDOException handler above.
    renderInvalidLink(500);
}

// recordRateLimit() must record whether the vericode ACTUALLY resolved, not
// merely whether the request avoided being throttled: RateLimit::check()'s
// token_max/ip_max paths count only `success = 0` rows (see
// getAttemptCount()'s $successOnly=false argument), so recording a blanket
// "allowed" success here — as an earlier version of this file did — writes
// every failed guess as success=1 and makes those two per-identifier
// brute-force ceilings permanently inert; only the outcome-independent
// total_max/total_window ceiling would ever fire. Recording the real
// resolution outcome here (`false` for an unknown/malformed code) is what
// makes token_max/ip_max — the limits that actually bound guessing against
// one car's code and one visitor's IP — count real failures.
recordRateLimit('verification_code_attempt', $verifyCar !== null, null, null, ['token' => $verifyCode]);

if ($verifyCar === null) {
    renderInvalidLink();
}

// --- 4. Ownership defense-in-depth ---------------------------------------
// A vericode reaching this page implies the car passed
// findVerificationEligible()'s exclusion when the email was sent, but
// ownership can change in the 60 days since. Re-check the same condition:
// a live users row (cars.user_id has no FK, so it can point at a deleted
// user) whose username is not the `noowner` GDPR reassignment placeholder.
$ownerId = $verifyCar->user_id === null ? 0 : (int) $verifyCar->user_id;
if ($ownerId <= 0) {
    renderInvalidLink();
}

$ownerRow = dbi()->query(
    "SELECT id, username FROM users WHERE id = ? AND username != 'noowner'",
    [$ownerId]
);

// query() never throws — a failed statement yields count() === 0, which is
// indistinguishable from "this car is ownerless". Both reject (failing
// closed, which is correct), but only one of them is a fault worth a log
// line, so check error() explicitly rather than letting a broken database
// masquerade as a legitimately rejected link.
if ($ownerRow->error()) {
    logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
        "verify_car.php: owner lookup failed for car {$verifyCar->id}: " . $ownerRow->errorString());
    renderInvalidLink(500);
}

if ($ownerRow->count() === 0) {
    renderInvalidLink();
}

// --- 5. Expiry check -----------------------------------------------------
$sentAt = $verifyCar->vericode_sent_at ?? null;
if ($sentAt === null || $sentAt === '') {
    renderInvalidLink();
}

$sentTimestamp = strtotime((string) $sentAt);
if ($sentTimestamp === false
    || $sentTimestamp < strtotime('-' . VERIFY_LINK_TTL_DAYS . ' days')) {
    renderInvalidLink();
}

// --- 6. Shared render context -------------------------------------------
$carId         = (int) $verifyCar->id;
$verifySelfUrl = $us_url_root . 'app/verify/verify_car.php';
$verifyPhoto   = verifyPrimaryPhotoUrl($carId);

// "Review & Update" sends the owner to the full edit form. They are almost
// certainly logged out, so capture this URL first and let usersc/login.php
// return them here afterwards — safelyCaptureDest() is UserSpice's documented
// helper for exactly this (custom auth checks outside securePage()).
$verifyEditUrl = $us_url_root . 'app/owner/cars/edit.php?car_id=' . $carId;
$verifyLoggedIn = $user->isLoggedIn();

/**
 * URL of a car's primary photo at its -resized-300 variant, or null.
 *
 * Car::images() returns pathinfo() arrays whose `path` key is already
 * prefixed with $us_url_root and userimages/{carid}/ — it must not be
 * prefixed again. The variant name is derived the same way
 * CarView::loadPicture() derives it.
 */
function verifyPrimaryPhotoUrl(int $carId): ?string
{
    try {
        $images = (new Car($carId))->images();
    } catch (\Throwable $e) {
        // A photo is decoration on a confirmation page; never let a missing
        // or unreadable image file stop an owner from verifying their car.
        logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            "verify_car.php: could not load images for car {$carId}: " . $e->getMessage());
        return null;
    }

    $primary = $images[0] ?? null;
    if (!is_array($primary) || empty($primary['path'])) {
        return null;
    }

    $parts = pathinfo((string) $primary['path']);
    if (!isset($parts['dirname'], $parts['filename'], $parts['extension'])) {
        return null;
    }

    return $parts['dirname'] . '/' . $parts['filename'] . '-resized-300.' . $parts['extension'];
}

/**
 * Build a cars_hist snapshot row for an owner-initiated verification action.
 *
 * Mirrors CarAdministrationService's history-insert shape (full column
 * snapshot plus `operation`) so a single query on `operation` distinguishes
 * these owner actions from the ordinary edit path's 'UPDATE'.
 *
 * @param object      $carData   The car being recorded, as resolved from the vericode
 * @param string      $operation 'VERIFIED' or 'VERIFIED SOLD'
 * @param string      $comments  Free-text audit note
 * @param string|null $soldDate  solddate value to record (the new value for a sale)
 * @return array<string, mixed>  Field map for CarRepository::insertHistory()
 */
function verifyHistoryFields(object $carData, string $operation, string $comments, ?string $soldDate): array
{
    $owner = (new \ElanRegistry\Owner((int) $carData->user_id))->data();

    // Owner::data() is nullable when find() reports "not found" rather than a
    // DB fault (a distinct path from OwnerDatabaseException). The ownership
    // defense-in-depth check above already proved a live users row exists
    // for this car at lookup time, but that was a separate query — a caller
    // must not silently write a half-populated audit row (or worse, crash
    // uncaught after the car mutation already committed) if the owner
    // vanishes between the two. Fail loudly and let the caller's transaction
    // roll back the car mutation too.
    if ($owner === null) {
        throw new \ElanRegistry\Exceptions\OwnerDatabaseException(
            'verify_car.php: owner ' . (int) $carData->user_id
            . " could not be loaded while building the {$operation} history snapshot for car "
            . (int) $carData->id
        );
    }

    return [
        'operation'             => $operation,
        'car_id'                => (int) $carData->id,
        'comments'              => $comments,
        'ctime'                 => $carData->ctime ?? date(\ElanRegistry\AppConstants::DATETIME_FORMAT),
        'mtime'                 => date(\ElanRegistry\AppConstants::DATETIME_FORMAT),
        'model'                 => $carData->model ?? '',
        'series'                => $carData->series ?? '',
        'variant'               => $carData->variant ?? '',
        'year'                  => $carData->year ?? '',
        'type'                  => $carData->type ?? '',
        'chassis'               => $carData->chassis ?? '',
        'color'                 => $carData->color ?? '',
        'engine'                => $carData->engine ?? '',
        'purchasedate'          => $carData->purchasedate ?? null,
        'solddate'              => $soldDate,
        'email_bounced'         => $carData->email_bounced ?? 0,
        'email_bounced_address' => $carData->email_bounced_address ?? null,
        'email_suppressed'      => $carData->email_suppressed ?? 0,
        'image'                 => $carData->image ?? '',
        'user_id'               => (int) $carData->user_id,
        'email'                 => $carData->email ?? ($owner->email ?? ''),
        'fname'                 => $owner->fname ?? '',
        'lname'                 => $owner->lname ?? '',
        'join_date'             => $owner->join_date ?? null,
        'city'                  => $owner->city ?? '',
        'state'                 => $owner->state ?? '',
        'country'               => $owner->country ?? '',
        'lat'                   => $owner->lat ?? null,
        'lon'                   => $owner->lon ?? null,
        'website'               => $owner->website ?? '',
    ];
}

// --- 7. Dispatch ---------------------------------------------------------
$action   = Input::raw('action') ?? '';
$isPost   = $method === 'POST';
$isSold   = !empty($verifyCar->solddate);
$verifier = new CarVerificationManager($repo);

// Sold-date bounds. Enforced here on POST; the same values are handed to the
// partial as HTML attributes, which are a picker convenience, not the
// enforcement.
$carYear     = (int) ($verifyCar->year ?? 0);
$soldDateMin = ($carYear > 0 ? $carYear : VERIFY_FALLBACK_MIN_YEAR) . '-01-01';
$soldDateMax = date('Y-m-d');
$soldDateValue = $soldDateMax;
$soldDateError = null;

// Already-sold guard. Applies to GET and POST alike so that a repeat POST is
// a strict no-op: first write wins, no second cars_hist row. Only relevant to
// the sold action — a sold car can still be verified. Renders directly with
// a 200 rather than following this file's usual PRG pattern: no write
// happens on this path, so there is nothing for a refresh to re-submit and
// no reason to pay a redirect round-trip just to reach the same notice.
if ($action === 'sold' && $isSold) {
    $verifyNoticeState   = 'sold';
    $verifyNoticeIcon    = 'fa-circle-info';
    $verifyNoticeHeading = 'This car is already recorded as sold.';
    $verifyNoticeBody    = 'We have it as sold on '
        . date('j F Y', (int) strtotime((string) $verifyCar->solddate))
        . '. It stays in your account marked as sold, we won\'t send you verification '
        . 'emails about it, and its history stays safely in the registry.';
    renderVerifyPage(__DIR__ . '/../views/cars/_verify_notice.php');
}

if ($isPost && $action === 'verify') {
    // The car mutated is ALWAYS the one the vericode resolved to. Any car id
    // in the POST body is display-only and is deliberately never read here.
    //
    // The car update and its cars_hist audit row are wrapped in one
    // transaction: without this, a failed insertHistory() left the car
    // mutated with no audit trail while the owner was told (via the 303
    // redirect below) that verification succeeded — a car can silently mark
    // as verified/sold with zero forensic record of who did it or when.
    $repo->beginTransaction();
    try {
        $verifier->markVerified($verifyCar);
        if (!$repo->insertHistory(verifyHistoryFields(
            $verifyCar,
            'VERIFIED',
            'Owner self-verification',
            $verifyCar->solddate ?? null
        ))) {
            throw new \ElanRegistry\Exceptions\CarDatabaseException(
                "verify_car.php: audit trail insert failed for VERIFIED on car {$carId}"
            );
        }
        $repo->commit();
    } catch (ElanRegistryException $e) {
        // Covers CarDatabaseException (from markVerified() or the audit
        // insert above) and OwnerDatabaseException from the history
        // snapshot's owner lookup — both descend from ElanRegistryException.
        $repo->rollback();
        logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            "verify_car.php: markVerified failed for car {$carId}: " . $e->getMessage());
        // The vericode already authenticated by this point, so there is no
        // enumeration risk in saying plainly that the write failed — see
        // renderActionFailed()'s docblock.
        renderActionFailed('We could not record your verification.');
    }

    // Post/Redirect/Get: a refresh must never re-submit.
    header('Location: ' . $verifySelfUrl . '?vericode=' . $verifyCode . '&action=verify', true, 303);
    exit;
}

if ($isPost && $action === 'sold') {
    $submitted = Input::raw('solddate') ?? '';

    // Server-side re-validation of both bounds. The HTML min/max attributes
    // are trivially bypassed; these checks are the enforcement.
    $parsed = DateTime::createFromFormat('Y-m-d', $submitted);
    if (!$parsed || $parsed->format('Y-m-d') !== $submitted) {
        $soldDateError = 'Please give the sale date as a valid date.';
    } elseif ($submitted > $soldDateMax) {
        $soldDateError = 'The sale date can\'t be in the future.';
    } elseif ($submitted < $soldDateMin) {
        $soldDateError = 'The sale date can\'t be before the car was built.';
    }

    if ($soldDateError !== null) {
        // Re-render the form with the rejected value. No write happened.
        $soldDateValue = $submitted;
        renderVerifyPage(__DIR__ . '/../views/cars/sold-confirm.php');
    }

    // See the action=verify branch above for why the car update and its
    // audit row are wrapped in one transaction.
    $repo->beginTransaction();
    try {
        $verifier->markSold($verifyCar, $submitted);
        if (!$repo->insertHistory(verifyHistoryFields(
            $verifyCar,
            'VERIFIED SOLD',
            'Owner-reported sale via verification link',
            $submitted
        ))) {
            throw new \ElanRegistry\Exceptions\CarDatabaseException(
                "verify_car.php: audit trail insert failed for VERIFIED SOLD on car {$carId}"
            );
        }
        $repo->commit();
    } catch (ElanRegistryException $e) {
        // Covers CarValidationException/CarDatabaseException from markSold()
        // (or the audit insert above) and OwnerDatabaseException from the
        // history snapshot's owner lookup.
        $repo->rollback();
        logger(0, LogCategories::LOG_CATEGORY_CAR_SOLD,
            "verify_car.php: markSold failed for car {$carId}: " . $e->getMessage());
        // Post-authentication write failure — see renderActionFailed()'s docblock.
        renderActionFailed('We could not record the sale.');
    }

    header('Location: ' . $verifySelfUrl . '?vericode=' . $verifyCode . '&action=sold', true, 303);
    exit;
}

if ($isPost && $action === 'optout') {
    // Opting out is an OWNER-level decision, not a car-level one: the owner is
    // saying "stop emailing me", so suppression fans out across every car they
    // have. The owner is $ownerId — resolved from the vericode's car and
    // re-checked against a live, non-`noowner` users row above. No id from the
    // POST body is read here, exactly as in the verify/sold branches.
    //
    // One transaction covers the fan-out and every audit row it needs, for the
    // same reason the branches above use one: a car must never end up
    // suppressed with no cars_hist record of who asked for it.
    $repo->beginTransaction();
    try {
        // Cars already suppressed are skipped by setSuppressedForOwner(), so
        // an empty $affected (a repeat POST, or an owner suppressed by a Brevo
        // complaint webhook) commits a no-op and redirects identically — no
        // error, no duplicate history rows.
        $affected = $verifier->setSuppressedForOwner($ownerId);
        foreach ($affected as $suppressedCar) {
            if (!$repo->insertHistory(verifyHistoryFields(
                $suppressedCar,
                'EMAIL SUPPRESSED',
                'Owner self-suppression via verification email opt-out link',
                $suppressedCar->solddate ?? null
            ))) {
                throw new \ElanRegistry\Exceptions\CarDatabaseException(
                    'verify_car.php: audit trail insert failed for EMAIL SUPPRESSED on car '
                    . (int) $suppressedCar->id . " for owner {$ownerId}"
                );
            }
        }
        $repo->commit();
    } catch (ElanRegistryException $e) {
        // Covers CarDatabaseException from setSuppressedForOwner() (including
        // its internal findByOwner()/suppressOwnerProfile() calls) or the
        // audit insert above, and OwnerDatabaseException from the history
        // snapshot's owner lookup — both descend from ElanRegistryException.
        $repo->rollback();
        logger(0, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED,
            "verify_car.php: setSuppressedForOwner failed for owner {$ownerId}: " . $e->getMessage());
        // Post-authentication write failure — see renderActionFailed()'s
        // docblock. Telling the owner "nothing is wrong" here would be false:
        // their opt-out did not take effect and they will keep receiving mail.
        renderActionFailed('We could not record your opt-out, so you may still receive verification emails.');
    }

    header('Location: ' . $verifySelfUrl . '?vericode=' . $verifyCode . '&action=optout', true, 303);
    exit;
}

// Any POST that reaches here carries an action this page does not handle.
// Treat it as a non-action rather than mutating anything.
if ($isPost) {
    header('Location: ' . $verifySelfUrl . '?vericode=' . $verifyCode, true, 303);
    exit;
}

// --- 8. GET rendering — never mutates ------------------------------------
if ($action === 'verify') {
    if (!empty($verifyCar->last_verified)) {
        // The state the PRG redirect lands on, and the state a later revisit
        // renders. `last_verified` being set is enough to know the action is
        // done; distinguishing "just now" from "earlier" would add a state
        // without adding information the owner needs.
        $verifyNoticeState   = 'verified';
        $verifyNoticeIcon    = 'fa-circle-check';
        $verifyNoticeHeading = 'Thank you — your car\'s record is confirmed';
        $verifyNoticeBody    = 'We\'ve noted that your registry entry is up to date as of '
            . date('j F Y', (int) strtotime((string) $verifyCar->last_verified))
            . '. You won\'t hear from us about this car again for a while.';
        renderVerifyPage(__DIR__ . '/../views/cars/_verify_notice.php');
    }

    renderVerifyPage(__DIR__ . '/../views/cars/verify-confirm.php');
}

if ($action === 'sold') {
    renderVerifyPage(__DIR__ . '/../views/cars/sold-confirm.php');
}

if ($action === 'optout') {
    // Read-only, like every branch in this section. The confirmation card, the
    // 303 redirect's landing page, and a later revisit all render from here —
    // the stored `email_suppressed` flag is what tells them apart, so no
    // "did we just write?" flag has to be threaded through the redirect.
    $optOutAlreadySuppressed = ((int) ($verifyCar->email_suppressed ?? 0) === 1);

    try {
        // The card speaks about the owner's whole account, because that is
        // what the POST branch acts on.
        $optOutCarCount = count($repo->findByOwner($ownerId));
    } catch (ElanRegistryException $e) {
        // A count is copy, not a gate: keep the opt-out reachable when the
        // count query fails, rather than denying an owner the unsubscribe
        // they came here for. null means "unknown" — the partial must then
        // speak generically rather than assert a specific car count we do
        // not actually have (a fixed fallback like 1 would tell a multi-car
        // owner their action affects only one car, which is simply false).
        logger(0, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED,
            "verify_car.php: owner car count failed for owner {$ownerId}: " . $e->getMessage());
        $optOutCarCount = null;
    }

    renderVerifyPage(__DIR__ . '/../views/cars/_verify_optout_confirm.php');
}

// No action param — the landing page with the full option set.
if (!$verifyLoggedIn) {
    // Capture this URL so usersc/login.php returns the owner here after they
    // sign in; the Review & Update link then points at login rather than at
    // the edit page they cannot yet reach.
    safelyCaptureDest();
    $verifyEditUrl = $us_url_root . 'usersc/login.php';
}

renderVerifyPage(__DIR__ . '/../views/cars/_verify_landing.php');
