<?php
declare(strict_types=1);

use ElanRegistry\Exceptions\AdminContactException;

/**
 * process-admin-contact.php
 * Processes admin-to-owner contact requests from data quality dashboard
 *
 * Handles admin messages to car owners through the registry email system,
 * including data quality context and proper admin credentials.
 *
 * @author Elan Registry Team
 * @copyright 2025
 */
require_once '../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

if (!securePage($php_self)) {
    die();
}

// Verify admin/editor permissions
if (!isRegistryAdmin()) { // Administrator (2) or Editor (3)
    logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_ACTIONS, 'Unauthorized access attempt to admin contact system');
    Redirect::to($us_url_root . 'users/login.php');
}

// Initialize message arrays
$errors = [];
$successes = [];

// Process form submission
if (Input::exists('post')) {
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
        exit();
    }

    $action = Input::get('action');
    if ($action === 'admin_contact_owner') {
        // Validate required fields
        $message = trim(Input::get('message'));
        $carId = Input::get('car_id');
        $ownerId = Input::get('owner_id');
        $qualityIssue = Input::get('quality_issue');

        if (empty($message)) {
            $errors[] = 'Message cannot be empty';
        }
        if (strlen($message) > 2000) {
            $errors[] = 'Message is too long (maximum 2000 characters)';
        }
        if (empty($carId)) {
            $errors[] = 'Car ID is required';
        }
        if (empty($ownerId)) {
            $errors[] = 'Owner ID is required';
        }

        if (empty($errors)) {
            try {
                $db = DB::getInstance();

                // Get admin user data
                $adminData = $db->query('SELECT id, email, fname, lname FROM users WHERE id = ?', [$user->data()->id])->first();
                if (!$adminData) {
                    throw new AdminContactException('Admin user not found');
                }

                // Get owner data - handle special 'Multiple' case for duplicate emails
                if ($ownerId === 'Multiple') {
                    $targetEmail = Input::get('target_email');
                    if (empty($targetEmail)) {
                        throw new AdminContactException('Target email not provided for multiple users');
                    }
                    if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
                        throw new AdminContactException('Invalid target email address format');
                    }
                    $ownerData = (object)[
                        'id' => 'Multiple',
                        'email' => $targetEmail,
                        'fname' => 'Multiple',
                        'lname' => 'Users'
                    ];
                } else {
                    $ownerData = $db->query('SELECT id, email, fname, lname FROM users WHERE id = ?', [$ownerId])->first();
                    if (!$ownerData) {
                        throw new AdminContactException('Owner user not found');
                    }
                }

                // Get car data for context
                $carData = null;
                if ($carId !== 'Multiple') {
                    $carQuery = $db->query('SELECT id, year, model, series, variant, type, chassis FROM cars WHERE id = ?', [$carId]);
                    if ($carQuery->count() > 0) {
                        $carData = $carQuery->first();
                    }
                }

                // Prepare email data — strip CR/LF from all header-bound values (#660)
                $toEmail = preg_replace('/[\r\n\t]/', '', $ownerData->email);
                $toName = trim($ownerData->fname . ' ' . $ownerData->lname);
                $fromEmail = preg_replace('/[\r\n\t]/', '', $adminData->email);
                $fromName = trim($adminData->fname . ' ' . $adminData->lname);
                $qualityIssue = preg_replace('/[\r\n\t]/', '', (string)($qualityIssue ?? ''));

                // Email subject
                $subject = '[ELANREGISTRY] Administrator Message';
                if ($qualityIssue) {
                    $subject .= ' - ' . $qualityIssue;
                }

                // Prepare template variables
                $template = [
                    'message' => $message,
                    'from' => $fromName,
                    'fromEmail' => $fromEmail,
                    'to' => $toName
                ];

                // Add car context if available
                if ($carData) {
                    $template['carContext'] = [
                        'id' => $carData->id,
                        'year' => $carData->year,
                        'model' => $carData->model,
                        'series' => $carData->series,
                        'chassis' => $carData->chassis
                    ];
                }

                // Add quality issue context
                if ($qualityIssue) {
                    $template['qualityIssue'] = $qualityIssue;
                }

                // Generate email body using template
                try {
                    extract($template, EXTR_SKIP);
                    ob_start();
                    include $abs_us_root . $us_url_root . 'app/views/email/_admin_to_owner.php';
                    $body = ob_get_clean() ?: '';
                } catch (\Throwable $e) {
                    if (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR,
                        'process-admin-contact.php: exception during email template render: ' . $e->getMessage());
                    $body = '';
                }

                if ($body === '') {
                    logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR,
                        'process-admin-contact.php: email body render failed — template missing or failed',
                        ['template' => 'app/views/email/_admin_to_owner.php']);
                    $errors[] = 'Email could not be sent. Please try again or contact the administrator.';
                    return;
                }

                // Send email
                $result = email($toEmail, $subject, $body);

                if ($result !== true) {
                    $resultStr = is_string($result) ? preg_replace('/[\r\n\t]/', '', $result) : 'unknown delivery error';
                    $errors[] = 'Failed to send email. Please try again.';
                    logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Admin contact SEND FAILED to {$toEmail}: {$resultStr}");
                } else {
                    if ($ownerId === 'Multiple') {
                        $successes[] = 'Administrator message sent successfully to duplicate accounts at ' . $toEmail;
                    } else {
                        $successes[] = 'Administrator message sent successfully to ' . $toName;
                    }
                    logger($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ACTIONS, "Admin contact sent - Admin: {$fromEmail}, Owner: {$toEmail}, Car: {$carId}, Issue: {$qualityIssue}");
                }

            } catch (AdminContactException $e) {
                $errors[] = $e->getUserMessage();
                logger($user->data()->id, $e->getLogCategory(), "Admin contact error: " . $e->getMessage());
            }
        }
    } else {
        $errors[] = 'Invalid action specified';
    }

    // Convert error/success arrays to UserSpice session messages (Issue #237)
    if (!empty($errors)) {
        foreach ($errors as $error) {
            usError($error);
        }
    }
    if (!empty($successes)) {
        foreach ($successes as $success) {
            usSuccess($success);
        }
    }

    Redirect::to($us_url_root . 'app/admin/index.php?tab=manage-cars');
} else {
    Redirect::to($us_url_root . 'app/admin/index.php?tab=manage-cars');
}
?>