<?php
declare(strict_types=1);

use ElanRegistry\Input;

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

if ($method !== 'POST' || !Input::exists('post')) {
    ApiResponse::error('Method not allowed', 405)->send();
}

if (!securePage($php_self)) {
    die();
}

$subject = '[ELANREGISTRY] Owner to Owner Message';

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
$message = Input::raw('message'); // raw — _member_to_owner.php escapes via EmailTemplate

if ($action !== 'send_message' || !Input::get('to_user_id')) {
    $safeAction = preg_replace('/[\r\n\t]/', '', (string)$action);
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

// Verify the recipient owns the car — prevents sending to arbitrary users (IDOR)
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

// DB is a singleton — _error, _results, and _count are overwritten on every
// query() call. Call error() and first() immediately after each query before
// issuing the next one.
$db->query('SELECT id, email, fname, lname FROM users WHERE id = ?', [$fromUserId]);
if ($db->error()) {
    ApiResponse::serverError()
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'send-owner-email.php: DB error fetching from-user from_id=' . $fromUserId)
        ->send();
}
$fromUser = $db->first();

$db->query('SELECT id, email, fname, lname FROM users WHERE id = ?', [$toUserId]);
if ($db->error()) {
    ApiResponse::serverError()
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'send-owner-email.php: DB error fetching to-user to_id=' . $toUserId)
        ->send();
}
$toUser = $db->first();

if (!$fromUser || !$toUser) {
    ApiResponse::serverError('Invalid user data')
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'send-owner-email.php: fromUser or toUser not found')
        ->send();
}

$toEmail   = preg_replace('/[\r\n\t]/', '', $toUser->email);
$toName    = $toUser->fname . ' ' . $toUser->lname;
$fromEmail = $fromUser->email;
$fromName  = $fromUser->fname . ' ' . $fromUser->lname;

$db->query('SELECT chassis, year, series, variant, type FROM cars WHERE id = ?', [$carId]);
if ($db->error()) {
    ApiResponse::serverError()
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'send-owner-email.php: DB error fetching car details for car_id=' . $carId)
        ->send();
}
$carRow = $db->first();
if (!$carRow) {
    ApiResponse::serverError()
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'send-owner-email.php: car not found for car_id=' . $carId . ' (concurrent delete?)')
        ->send();
}
$carUrl = $current_origin . $us_url_root . 'app/owner/cars/details.php?car_id=' . $carId;

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
    logger($logUserId ?? 0, LogCategories::LOG_CATEGORY_ELAN_REGISTRY, "send-owner-email.php invalid fromEmail for reply-to: " . preg_replace('/[\r\n\t]/', '', $fromEmail));
}
$replyOpts = $fromEmailValid ? ['replyTo' => $fromEmail, 'reply_name' => $fromName] : [];

$result = email($toEmail, $subject, $body, $replyOpts);
$safeFromLog = preg_replace('/[\r\n\t]/', '', $fromEmail);
$safeToLog   = preg_replace('/[\r\n\t]/', '', $toEmail);

if ($result !== true) {
    ApiResponse::serverError()
        ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "send-owner-email.php SEND FAILED from $safeFromLog to $safeToLog")
        ->send();
}

ApiResponse::success('Your message has been sent.')
    ->withLogging($logUserId ?? 0, LogCategories::LOG_CATEGORY_ELAN_REGISTRY, "send-owner-email.php sent from $safeFromLog to $safeToLog")
    ->send();
