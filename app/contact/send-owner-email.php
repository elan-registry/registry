<?php
declare(strict_types=1);

/**
 * contact_owner_email.php
 * Processes contact owner requests and sends emails between users.
 *
 * Handles the backend processing for the contact owner functionality, including
 * email composition, validation, and delivery. Includes security measures and
 * user privacy protection.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 */
require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}
$subject = '[ELANREGISTRY] Owner to Owner Message';

// Initialize message arrays
$errors = [];

// Make sure no one tries to add header like keywords
function clean_string(string $string): string
{
    $bad = array('content-type', 'bcc:', 'to:', 'cc:', 'href');
    return str_replace($bad, '', $string);
}

//Forms posted now process it
if (Input::exists('post')) {
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
    } else {
        $action = Input::get('action');
        if ($action === 'send_message' && Input::get('from_user_id') && Input::get('to_user_id') && Input::get('message')) {
            // Validate message input
            $message = Input::get('message');
            if (empty(trim($message))) {
                $errors[] = 'Message cannot be empty';
                include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
                exit();
            }
            if (strlen($message) > 2000) {
                $errors[] = 'Message is too long (maximum 2000 characters)';
                include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
                exit();
            }
            
            // Security: Get user data from database instead of trusting serialized data
            $fromUserId = (int) Input::get('from_user_id');
            $toUserId = (int) Input::get('to_user_id');
            
            // Validate user IDs and get user data from database
            $db = DB::getInstance();
            $fromUser = $db->query('SELECT id, email, fname, lname FROM users WHERE id = ?', [$fromUserId])->first();
            $toUser = $db->query('SELECT id, email, fname, lname FROM users WHERE id = ?', [$toUserId])->first();
            
            if (!$fromUser || !$toUser) {
                $errors[] = 'Invalid user data';
                include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
                exit();
            }
            
            $f = (array) $fromUser;
            $t = (array) $toUser;

            $toEmail        =  $t['email'];

            $toName         =  $t['fname'] . ' ' . $t['lname'];
            $fromEmail      =  $f['email'];
            $fromName       =  $f['fname'] . ' ' . $f['lname'];

            $template       =  array(
                'message'   => clean_string($message),
                'from'      => $fromName,
                'to'        => $toName
            );

            $body = email_body('_email_contact_owner.php', $template);

            // Validate email format before using as reply-to header (defense-in-depth against
            // header injection, even though $fromEmail is sourced from the users table).
            // Call sendinblue() directly to set reply-to: the override.php wrapper places $to_name
            // before $options, requiring an empty display-name argument. Direct call is cleaner
            // and avoids coupling to wrapper internals. See docs/bugs/userspice-brevo-override-signature-bug.txt.
            if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                if (function_exists('sendinblue')) {
                    $result = sendinblue($toEmail, $subject, $body, '', ['reply' => $fromEmail]);
                } else {
                    $result = email($toEmail, $subject, $body, ['replyTo' => $fromEmail]);
                }
            } else {
                logger($user->data()->id, LogCategories::LOG_CATEGORY_ELAN_REGISTRY, "contact_owner_email.php invalid fromEmail for reply-to: " . preg_replace('/[\r\n\t]/', '', $fromEmail));
                $result = email($toEmail, $subject, $body);
            }

            // sendinblue() returns true on success, error string on failure.
            // email() (PHPMailer fallback) returns true on success, false on failure.
            $safeFromLog = preg_replace('/[\r\n\t]/', '', $fromEmail);
            $safeToLog   = preg_replace('/[\r\n\t]/', '', $toEmail);
            if (isset($result) && $result !== true) {
                $resultStr = is_string($result) ? $result : 'unknown delivery error';
                logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "contact_owner_email.php SEND FAILED from " . $safeFromLog . " to " . $safeToLog . ": " . $resultStr);
                $errors[] = 'Your message could not be delivered. Please try again or contact the administrator.';
            } else {
                logger($user->data()->id, LogCategories::LOG_CATEGORY_ELAN_REGISTRY, "contact_owner_email.php sent from " . $safeFromLog . " to " . $safeToLog);
            }
        } else {
            $errors[] = 'Not enough parameters provided';
        }
    } // End Post with data
    
    // Convert error/success arrays to UserSpice session messages (Issue #237)
    if (!empty($errors)) {
        foreach ($errors as $error) {
            usError($error);
        }
    }
    // Messages will be displayed by UserSpice session system in template
} // End Post

?>

<div id='page-wrapper'>
    <div class='container-fluid'>
        <div class='row'>
            <div class='col-sm-12'>
                <div class='jumbotron'>
                    <?php if (empty($errors)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-envelope fa-3x text-success mb-3"></i>
                        <h4>Message Sent</h4>
                        <p class="text-muted">Taking you back to the car in a few seconds</p>
                    </div>
                    <script>
                        setTimeout(function() {
                            <?php
                            $carId = Input::get('car_id');
                            if ($carId) {
                                echo "window.location.href = '" . $us_url_root . "app/cars/details.php?car_id=" . (int)$carId . "';";
                            } else {
                                echo "window.location.href = '" . $us_url_root . "';";
                            }
                            ?>
                        }, 5000);
                    </script>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
                        <h4>Message Not Sent</h4>
                        <p class="text-muted">There was a problem delivering your message. Please try again or contact the administrator.</p>
                    </div>
                    <?php endif; ?>
                </div><!-- End of main content section -->
            </div> <!-- /.col -->
        </div> <!-- /.row -->
    </div> <!-- /.container -->
</div> <!-- /.wrapper -->