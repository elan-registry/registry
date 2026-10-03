<?php
declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\Car\Car;
use ElanRegistry\Input;
use ElanRegistry\InputSanitizer;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;

/**
 * send-owner-email.php
 * JSON API endpoint for owner-to-owner contact messages.
 *
 * Handles backend processing for the contact owner functionality, including
 * email composition, validation, IDOR protection, and delivery. Returns
 * Pattern A (ApiResponse) JSON responses.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 */
require_once '../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

if ($method !== 'POST' || !Input::existsPost()) {
    ApiResponse::error('Method not allowed', 405)->send();
}

if (!securePage($php_self)) {
    die();
}

$subject = EMAIL_SUBJECT_PREFIX . ' Owner to Owner Message';

$logUserId = ($user->isLoggedIn() && $user->data()) ? (int)$user->data()->id : null;

if (!Token::check(Input::get('csrf'))) {
    ApiResponse::forbidden('Invalid CSRF token')
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_SECURITY, 'send-owner-email.php: CSRF validation failed from ' . $remote_addr)
        ->send();
}

if (!checkRateLimit('owner_contact_email', $logUserId)) {
    recordRateLimit('owner_contact_email', false, $logUserId);
    ApiResponse::error('Too many messages sent. Please wait before sending another.', 429)
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_ACCESS_DENIED, 'send-owner-email.php: rate limit exceeded from ' . $remote_addr)
        ->send();
}
recordRateLimit('owner_contact_email', true, $logUserId);

$action = Input::get('action');
$message = Input::raw('message'); // raw — output-escaped in _member_to_owner.php at the render layer

if ($action !== 'send_message' || !Input::get('to_user_id')) {
    // Guard against InputSanitizer::stripHeaderInjectionChars() throwing (PCRE engine
    // failure) — this call site is before the file's first try/catch, so an uncaught
    // exception here would escape as a raw error page instead of the ApiResponse JSON
    // contract this endpoint promises.
    try {
        $safeAction = InputSanitizer::stripHeaderInjectionChars((string)$action);
    } catch (\Throwable $e) {
        $safeAction = '[unsanitizable]';
    }
    logger($logUserId ?? 0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, 'send-owner-email.php: missing parameters — action=' . $safeAction);
    ApiResponse::error('Invalid parameters', 400)->send();
}

if ($message === null || trim($message) === '') {
    ApiResponse::validationError(['message' => 'Please enter a message.'])->send();
}

if (strlen($message) > 2000) {
    ApiResponse::validationError(['message' => 'Message is too long (maximum 2000 characters)'])->send();
}

$fromUserId = (int) $user->data()->id;
$toUserId   = (int) Input::get('to_user_id');
$carId      = (int) Input::get('car_id');

if ($toUserId <= 0 || $carId <= 0) {
    ApiResponse::error('Invalid parameters', 400)
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_ACCESS_DENIED, 'send-owner-email.php: invalid to_user_id=' . $toUserId . ' or car_id=' . $carId)
        ->send();
}

$db = DB::getInstance();

// Verify the recipient owns the car — prevents sending to arbitrary users (IDOR).
// Raw SQL preserved so DB errors are logged as DATABASE_ERROR and not
// misclassified as IDOR access denials (#1014).
$carOwnerResult = $db->query('SELECT user_id FROM cars WHERE id = ?', [$carId]);
if ($carOwnerResult->error()) {
    ApiResponse::serverError()
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'send-owner-email.php: DB error fetching owner for car_id=' . $carId)
        ->send();
}
$carOwner = $carOwnerResult->first();
if (!$carOwner || (int)$carOwner->user_id !== $toUserId) {
    ApiResponse::forbidden()
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_ACCESS_DENIED, 'send-owner-email.php: to_user_id ' . $toUserId . ' does not match owner of car_id=' . $carId)
        ->send();
}

try {
    $fromOwner = new Owner($fromUserId);
    $toOwner   = new Owner($toUserId);
} catch (\Throwable $e) {
    ApiResponse::serverError()
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'send-owner-email.php: exception loading owner data: ' . $e->getMessage())
        ->send();
}

if (!$fromOwner->data() || !$toOwner->data()) {
    ApiResponse::serverError('Invalid user data')
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'send-owner-email.php: fromUser or toUser not found')
        ->send();
}

$toData   = $toOwner->data();
$fromData = $fromOwner->data();

// InputSanitizer::stripHeaderInjectionChars() can throw on a PCRE engine failure;
// catch it here rather than let it escape as a raw error page instead of the
// ApiResponse JSON contract this endpoint promises.
try {
    $toEmail   = InputSanitizer::stripHeaderInjectionChars($toData->email);
    $fromEmail = InputSanitizer::stripHeaderInjectionChars($fromData->email);
    $fromName  = InputSanitizer::stripHeaderInjectionChars((string)($fromData->fname ?? ''));     // strip header-injection chars — reply_name is a display-name header value
} catch (\Throwable $e) {
    ApiResponse::serverError()
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, 'send-owner-email.php: exception sanitizing header-bound values: ' . $e->getMessage())
        ->send();
}
$toName = (string)($toData->fname ?? '');                                        // first name only — flows to HTML template, not headers

if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    ApiResponse::serverError()
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_EMAIL_ERROR,
            'send-owner-email.php: invalid recipient email for to_user_id=' . $toUserId)
        ->send();
}

try {
    $car = new Car($carId);
} catch (\Throwable $e) {
    ApiResponse::serverError()
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'send-owner-email.php: exception loading car for car_id=' . $carId . ': ' . $e->getMessage())
        ->send();
}
if (!$car->exists()) {
    ApiResponse::serverError()
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'send-owner-email.php: car not found for car_id=' . $carId . ' (concurrent delete?)')
        ->send();
}
$carRow = $car->data();
// getBaseUrl(), not $current_origin: it falls back to the email.verify_url
// setting when the request's host is not one this application serves.
$carUrl = getBaseUrl() . '/app/owner/cars/details.php?car_id=' . $carId;

$template = array(
    'message'      => $message,
    'from'         => $fromName,
    'to'           => $toName,
    'car_year'     => (int)$carRow->year,
    'car_series'   => (string)$carRow->series,
    'car_variant'  => (string)$carRow->variant,
    'car_type'     => (string)$carRow->type,
    'car_chassis'  => (string)$carRow->chassis,
    'carUrl'       => $carUrl,
);

try {
    extract($template, EXTR_SKIP);
    ob_start();
    include $abs_us_root . $us_url_root . 'app/views/email/_member_to_owner.php';
    $body = ob_get_clean() ?: '';
} catch (\Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    ApiResponse::serverError()
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, 'send-owner-email.php: exception during email template render: ' . $e->getMessage())
        ->send();
}

if ($body === '') {
    ApiResponse::serverError()
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, 'send-owner-email.php: email body render failed — template missing or failed')
        ->send();
}

// Validate email format before using as reply-to (defense-in-depth;
// $fromEmail comes from the database but we guard anyway).
$fromEmailValid = filter_var($fromEmail, FILTER_VALIDATE_EMAIL);
if (!$fromEmailValid) {
    // $fromEmail already passed through stripHeaderInjectionChars() once above without
    // throwing, so re-stripping it here (already CR/LF/tab-free) cannot fail — but guard
    // anyway for consistency with the other call sites in this file.
    try {
        $safeFromEmailLog = InputSanitizer::stripHeaderInjectionChars($fromEmail);
    } catch (\Throwable $e) {
        $safeFromEmailLog = '[unsanitizable]';
    }
    logger($logUserId ?? 0, LogCategories::LOG_CATEGORY_ELAN_REGISTRY, "send-owner-email.php invalid fromEmail for reply-to: " . $safeFromEmailLog);
}
$replyOpts = $fromEmailValid ? ['replyTo' => $fromEmail, 'reply_name' => $fromName] : [];

$result = email($toEmail, $subject, $body, $replyOpts);
try {
    $safeFromLog = InputSanitizer::stripHeaderInjectionChars($fromEmail);
    $safeToLog   = InputSanitizer::stripHeaderInjectionChars($toEmail);
} catch (\Throwable $e) {
    $safeFromLog = '[unsanitizable]';
    $safeToLog   = '[unsanitizable]';
}

if ($result !== true) {
    ApiResponse::serverError()
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "send-owner-email.php SEND FAILED from $safeFromLog to $safeToLog")
        ->send();
}

ApiResponse::success('Your message has been sent.')
    ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_ELAN_REGISTRY, "send-owner-email.php sent from $safeFromLog to $safeToLog")
    ->send();
