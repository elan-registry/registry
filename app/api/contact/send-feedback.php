<?php
declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\Input;
use ElanRegistry\LogCategories;

/**
 * send-feedback.php
 * JSON API endpoint for feedback form submissions.
 *
 * Validates input, checks CSRF token, and sends feedback to the registry
 * admin via email. Returns Pattern A (ApiResponse) JSON responses. Owner
 * identity (name, email, id) is read from the trusted session, not POST.
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

$logUserId = ($user->isLoggedIn() && $user->data()) ? (int)$user->data()->id : 0;

if (!Token::check(Input::get('csrf'))) {
    ApiResponse::forbidden('Invalid CSRF token')
        ->withLogging($logUserId, LogCategories::LOG_CATEGORY_SECURITY, 'send-feedback.php: CSRF validation failed from ' . $remote_addr)
        ->send();
}

if (!checkRateLimit('feedback_submission', $logUserId ?: null)) {
    recordRateLimit('feedback_submission', false, $logUserId ?: null);
    ApiResponse::error('Too many submissions. Please wait before trying again.', 429)
        ->withLogging($logUserId, LogCategories::LOG_CATEGORY_ACCESS_DENIED, 'send-feedback.php: rate limit exceeded from ' . $remote_addr)
        ->send();
}
recordRateLimit('feedback_submission', true, $logUserId ?: null);

// Owner identity comes from the trusted session, not POST hidden fields.
$name = $user->data()->fname . ' ' . $user->data()->lname;
$emailFrom = $user->data()->email;
$idFrom = (string)$user->data()->id;

// Raw value — the email view template escapes via EmailTemplate methods
$comments = Input::raw('comments');

// Validate the only user-supplied field
if ($comments === null || $comments === '' || strlen($comments) < 2) {
    ApiResponse::validationError(['comments' => 'Please enter your feedback.'])->send();
}
if (strlen($comments) > 1000) {
    ApiResponse::validationError(['comments' => 'Feedback must be 1000 characters or fewer.'])->send();
}

$emailTo = getFeedbackEmail();
$emailSubject = '[ELANREGISTRY] Feedback';

$template = array(
    'name' => $name,
    'email' => $emailFrom,
    'accountId' => $idFrom,
    'comments' => $comments
);

try {
    extract($template, EXTR_SKIP);
    ob_start();
    include $abs_us_root . $us_url_root . 'app/views/email/_feedback.php';
    $body = ob_get_clean() ?: '';
} catch (\Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    ApiResponse::serverError('Your message could not be sent due to a server configuration error.')
        ->withLogging($logUserId, LogCategories::LOG_CATEGORY_FEEDBACK_FORM, 'send-feedback.php: exception during email template render: ' . $e->getMessage())
        ->send();
}

if ($body === '') {
    ApiResponse::serverError('Your message could not be sent due to a server configuration error.')
        ->withLogging($logUserId, LogCategories::LOG_CATEGORY_FEEDBACK_FORM, 'send-feedback.php: email body render failed — template missing or failed')
        ->send();
}

// Reply-to is set to the submitter so the admin can reply directly.
$result = email($emailTo, $emailSubject, $body, ['replyTo' => $emailFrom, 'reply_name' => $name]);

if ($result !== true) {
    ApiResponse::serverError('Your message could not be delivered. Please try again or contact the administrator.')
        ->withLogging($logUserId, LogCategories::LOG_CATEGORY_FEEDBACK_FORM, 'send-feedback.php: email() returned false sending to ' . $emailTo . ' — see sendinblue log for detail')
        ->send();
}

ApiResponse::success('Thank you for your feedback! Your help makes the Elan Registry better!')
    ->withLogging($logUserId, LogCategories::LOG_CATEGORY_FEEDBACK_FORM, "send-feedback.php: complete: sent to $emailTo with subject '$emailSubject'")
    ->send();
