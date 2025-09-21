<?php
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
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Verify admin/editor permissions
if (!hasPerm([1, 2])) { // Administrator (1) or Editor (2)
    logger($user->data()->id ?? 0, 'ElanRegistry', 'Unauthorized access attempt to admin contact system');
    Redirect::to($us_url_root . 'users/login.php');
}

// Initialize message arrays
$errors = [];
$successes = [];

// Security function to clean email content
function clean_string($string) {
    $bad = array('content-type', 'bcc:', 'to:', 'cc:', 'href');
    return str_replace($bad, '', $string);
}

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
                    $errors[] = 'Admin user data not found';
                    throw new Exception('Admin user not found');
                }

                // Get owner data - handle special 'Multiple' case for duplicate emails
                if ($ownerId === 'Multiple') {
                    // For duplicate emails, extract the actual email from the form data
                    // The email would be passed in the quality issue context
                    $ownerData = (object)[
                        'id' => 'Multiple',
                        'email' => Input::get('target_email'), // We'll need to pass this from the form
                        'fname' => 'Multiple',
                        'lname' => 'Users'
                    ];
                    if (empty($ownerData->email)) {
                        $errors[] = 'Target email is required for multiple user contact';
                        throw new Exception('Target email not provided for multiple users');
                    }
                } else {
                    $ownerData = $db->query('SELECT id, email, fname, lname FROM users WHERE id = ?', [$ownerId])->first();
                    if (!$ownerData) {
                        $errors[] = 'Owner user data not found (Owner ID: ' . $ownerId . ')';
                        throw new Exception('Owner user not found');
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

                // Prepare email data
                $toEmail = $ownerData->email;
                $toName = trim($ownerData->fname . ' ' . $ownerData->lname);
                $fromEmail = $adminData->email;
                $fromName = trim($adminData->fname . ' ' . $adminData->lname);

                // Email subject
                $subject = '[ELANREGISTRY] Administrator Message';
                if ($qualityIssue) {
                    $subject .= ' - ' . $qualityIssue;
                }

                // Prepare template variables
                $template = [
                    'message' => clean_string($message),
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

                // Email options for reply-to
                $options = [
                    'reply_name' => $fromName,
                    'reply' => $fromEmail,
                ];

                // Generate email body using template
                $body = email_body('_email_admin_contact_owner.php', $template);

                // Send email
                $result = email($toEmail, $subject, $body, $options);

                if ($result) {
                    // For duplicate accounts, show the email address instead of "Multiple Users"
                    if ($ownerId === 'Multiple') {
                        $successes[] = 'Administrator message sent successfully to duplicate accounts at ' . $toEmail;
                    } else {
                        $successes[] = 'Administrator message sent successfully to ' . $toName;
                    }

                    // Log the admin contact for audit trail
                    logger($user->data()->id, "ElanRegistry", "Admin contact sent - Admin: {$fromEmail}, Owner: {$toEmail}, Car: {$carId}, Issue: {$qualityIssue}");
                } else {
                    $errors[] = 'Failed to send email. Please try again.';
                    logger($user->data()->id, "ElanRegistry", "Failed admin contact email - Admin: {$fromEmail}, Owner: {$toEmail}");
                }

            } catch (Exception $e) {
                $errors[] = 'An error occurred while sending the message: ' . $e->getMessage();
                logger($user->data()->id, "ElanRegistry", "Admin contact error: " . $e->getMessage());
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

    // Redirect back to data quality tab
    Redirect::to($us_url_root . 'app/admin/manage-consolidated.php?tab=data-quality');
} else {
    // No POST data
    Redirect::to($us_url_root . 'app/admin/manage-consolidated.php?tab=data-quality');
}
?>