<?php
declare(strict_types=1);

use ElanRegistry\Exceptions\AdminContactException;
use ElanRegistry\Input;

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

$adminUserId = (int) $user->data()->id;
$errors = [];
$successes = [];

if (Input::exists('post')) {
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
        exit();
    }

    $action = Input::get('action');
    if ($action === 'admin_contact_owner') {
        $message = trim(Input::raw('message'));
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
                $admin = new Owner($adminUserId);
                $adminData = $admin->data();
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
                    $owner = new Owner((int)$ownerId);
                    $ownerData = $owner->data();
                    if (!$ownerData) {
                        throw new AdminContactException('Owner user not found');
                    }
                }

                $carData = null;
                if ($carId !== 'Multiple') {
                    $car = new Car((int)$carId);
                    if ($car->exists()) {
                        $carData = $car->data();
                    } else {
                        logger($adminUserId, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                            "process-admin-contact.php: car ID {$carId} not found; email sent without car context");
                    }
                }

                // Prepare email data — strip CR, LF, and tab from all header-bound values (#660)
                $toEmail = preg_replace('/[\r\n\t]/', '', $ownerData->email);
                $toName = trim($ownerData->fname . ' ' . $ownerData->lname);
                $fromEmail = preg_replace('/[\r\n\t]/', '', $adminData->email);
                $fromName = trim($adminData->fname . ' ' . $adminData->lname);
                $qualityIssue = preg_replace('/[\r\n\t]/', '', (string)($qualityIssue ?? ''));

                $subject = '[ELANREGISTRY] Administrator Message';
                if ($qualityIssue) {
                    $subject .= ' - ' . $qualityIssue;
                }

                $template = [
                    'message' => $message,
                    'from' => $fromName,
                    'fromEmail' => $fromEmail,
                    'to' => $toName
                ];

                if ($carData) {
                    $template['carContext'] = [
                        'id' => $carData->id,
                        'year' => $carData->year,
                        'model' => $carData->model,
                        'series' => $carData->series,
                        'chassis' => $carData->chassis
                    ];
                }

                if ($qualityIssue) {
                    $template['qualityIssue'] = $qualityIssue;
                }

                try {
                    extract($template, EXTR_SKIP);
                    ob_start();
                    include $abs_us_root . $us_url_root . 'app/views/email/_admin_to_owner.php';
                    $body = ob_get_clean() ?: '';
                } catch (\Throwable $e) {
                    if (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    logger($adminUserId, LogCategories::LOG_CATEGORY_EMAIL_ERROR,
                        'process-admin-contact.php: exception during email template render: ' . $e->getMessage());
                    $body = '';
                }

                if ($body === '') {
                    logger($adminUserId, LogCategories::LOG_CATEGORY_EMAIL_ERROR,
                        'process-admin-contact.php: email body render failed — template missing or failed',
                        ['template' => 'app/views/email/_admin_to_owner.php']);
                    $errors[] = 'Email could not be sent. Please try again or contact the administrator.';
                } else {
                    $result = email($toEmail, $subject, $body);

                    if ($result !== true) {
                        $resultStr = is_string($result) ? preg_replace('/[\r\n\t]/', '', $result) : 'unknown delivery error';
                        $errors[] = 'Failed to send email. Please try again.';
                        logger($adminUserId, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Admin contact SEND FAILED to {$toEmail}: {$resultStr}");
                    } else {
                        $successes[] = $ownerId === 'Multiple'
                            ? 'Administrator message sent successfully to duplicate accounts at ' . $toEmail
                            : 'Administrator message sent successfully to ' . $toName;
                        logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_ACTIONS, "Admin contact sent - Admin: {$fromEmail}, Owner: {$toEmail}, Car: {$carId}, Issue: {$qualityIssue}");
                    }
                }

            } catch (AdminContactException $e) {
                $errors[] = $e->getUserMessage();
                logger($adminUserId, $e->getLogCategory(), "Admin contact error: " . $e->getMessage());
            } catch (\Throwable $e) {
                $errors[] = 'An unexpected error occurred. Please try again.';
                logger($adminUserId, LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
                    'process-admin-contact.php: unexpected exception (' . get_class($e) . '): ' . $e->getMessage());
            }
        }
    } else {
        $errors[] = 'Invalid action specified';
    }

    // Convert error/success arrays to UserSpice session messages (Issue #237)
    foreach ($errors as $error) {
        usError($error);
    }
    foreach ($successes as $success) {
        usSuccess($success);
    }

    Redirect::to($us_url_root . 'app/admin/index.php?tab=manage-cars');
} else {
    Redirect::to($us_url_root . 'app/admin/index.php?tab=manage-cars');
}
?>
