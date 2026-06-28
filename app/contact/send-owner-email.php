<?php
declare(strict_types=1);

use ElanRegistry\Input;

/**
 * send-owner-email.php
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
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

if (!securePage($php_self)) {
    die();
}
$subject = '[ELANREGISTRY] Owner to Owner Message';

// Initialize message arrays
$errors = [];
$email_sent = false;
$post_attempted = Input::exists('post');
$carId = 0;

if ($post_attempted) {
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
        exit;
    } else {
        $senderId = ($user->isLoggedIn() && $user->data()) ? (int)$user->data()->id : null;
        if (!checkRateLimit('owner_contact_email', $senderId)) {
            logger($senderId ?? 0, LogCategories::LOG_CATEGORY_ACCESS_DENIED, 'send-owner-email.php: rate limit exceeded from ' . $remote_addr);
            $errors[] = 'Too many messages sent. Please wait before sending another.';
        }

        $action = Input::get('action');
        $message = Input::raw('message'); // raw — _email_contact_owner.php escapes via EmailTemplate
        if ($action === 'send_message' && Input::get('to_user_id') && $message !== null && $message !== '') {
            if (strlen($message) > 2000) {
                $errors[] = 'Message is too long (maximum 2000 characters)';
                include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
                exit();
            }

            $fromUserId = (int) $user->data()->id;
            $toUserId   = (int) Input::get('to_user_id');
            $carId      = (int) Input::get('car_id');

            if ($toUserId <= 0 || $carId <= 0) {
                logger(
                    $user->data()->id,
                    LogCategories::LOG_CATEGORY_ACCESS_DENIED,
                    'send-owner-email.php: invalid to_user_id=' . $toUserId . ' or car_id=' . $carId
                );
                include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
                exit();
            }

            // Validate user IDs and get user data from database
            $db = DB::getInstance();

            // Verify the recipient owns the car — prevents sending to arbitrary users (IDOR)
            $carOwnerResult = $db->query('SELECT user_id FROM cars WHERE id = ?', [$carId]);
            if ($carOwnerResult->error()) {
                logger(
                    $user->data()->id,
                    LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                    'send-owner-email.php: DB error fetching owner for car_id=' . $carId
                );
                include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
                exit();
            }
            $carOwner = $carOwnerResult->first();
            if (!$carOwner || (int)$carOwner->user_id !== $toUserId) {
                logger(
                    $user->data()->id,
                    LogCategories::LOG_CATEGORY_ACCESS_DENIED,
                    'send-owner-email.php: to_user_id ' . $toUserId . ' does not match owner of car_id=' . $carId
                );
                include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
                exit();
            }

            // DB is a singleton — call first() immediately after each query; do not
            // store the result and query again before reading, or both variables
            // will resolve to the last query's data.
            $db->query('SELECT id, email, fname, lname FROM users WHERE id = ?', [$fromUserId]);
            if ($db->error()) {
                logger(
                    $user->data()->id,
                    LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                    'send-owner-email.php: DB error fetching from-user from_id=' . $fromUserId
                );
                include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
                exit();
            }
            $fromUser = $db->first();

            $db->query('SELECT id, email, fname, lname FROM users WHERE id = ?', [$toUserId]);
            if ($db->error()) {
                logger(
                    $user->data()->id,
                    LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                    'send-owner-email.php: DB error fetching to-user to_id=' . $toUserId
                );
                include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
                exit();
            }
            $toUser = $db->first();

            if (!$fromUser || !$toUser) {
                $errors[] = 'Invalid user data';
                include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
                exit();
            }
            
            $toEmail   = preg_replace('/[\r\n\t]/', '', $toUser->email);
            $toName    = $toUser->fname . ' ' . $toUser->lname;
            $fromEmail = $fromUser->email;
            $fromName  = $fromUser->fname . ' ' . $fromUser->lname;

            // Query car details for the email body
            $db->query('SELECT chassis, year, series, variant, type FROM cars WHERE id = ?', [$carId]);
            $carRow = $db->first();
            $carUrl = $current_origin . $us_url_root . 'app/cars/details.php?car_id=' . $carId;

            $template = array(
                'message'      => $message,
                'from'         => $fromName,
                'to'           => $toName,
                'car_year'     => $carRow ? (int)$carRow->year     : null,
                'car_series'   => $carRow ? (string)$carRow->series  : null,
                'car_variant'  => $carRow ? (string)$carRow->variant : null,
                'car_type'     => $carRow ? (string)$carRow->type    : null,
                'car_chassis'  => $carRow ? (string)$carRow->chassis : null,
                'carUrl'       => $carUrl,
            );

            $body = email_body('_email_contact_owner.php', $template);

            if (empty($body)) {
                logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "contact_owner_email.php: email_body() returned empty — template missing or failed");
                $errors[] = 'Your message could not be sent due to a server configuration error. Please contact the administrator.';
            }

            if (empty($errors)) {
                // Validate email format before using as reply-to (defense-in-depth;
                // $fromEmail comes from the database but we guard anyway).
                $fromEmailValid = filter_var($fromEmail, FILTER_VALIDATE_EMAIL);
                if (!$fromEmailValid) {
                    logger($user->data()->id, LogCategories::LOG_CATEGORY_ELAN_REGISTRY, "contact_owner_email.php invalid fromEmail for reply-to: " . preg_replace('/[\r\n\t]/', '', $fromEmail));
                }
                $replyOpts = $fromEmailValid ? ['replyTo' => $fromEmail, 'reply_name' => $fromName] : [];

                $result = email($toEmail, $subject, $body, $replyOpts);
                $safeFromLog = preg_replace('/[\r\n\t]/', '', $fromEmail);
                $safeToLog   = preg_replace('/[\r\n\t]/', '', $toEmail);
                if ($result !== true) {
                    logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "contact_owner_email.php SEND FAILED from " . $safeFromLog . " to " . $safeToLog);
                    $errors[] = 'Your message could not be delivered. Please try again or contact the administrator.';
                } else {
                    logger($user->data()->id, LogCategories::LOG_CATEGORY_ELAN_REGISTRY, "contact_owner_email.php sent from " . $safeFromLog . " to " . $safeToLog);
                    $email_sent = true;
                }
            }
        } else {
            $errors[] = 'Not enough parameters provided';
            $safeAction = preg_replace('/[\r\n\t]/', '', (string)$action);
            logger(
                isset($user) && $user->isLoggedIn() ? $user->data()->id : 0,
                LogCategories::LOG_CATEGORY_EMAIL_ERROR,
                'send-owner-email.php: missing parameters — action=' . $safeAction
            );
        }
    } // End CSRF check

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
                    <?php if ($email_sent): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-envelope fa-3x text-success mb-3"></i>
                        <h4>Message Sent</h4>
                        <p class="text-muted">Taking you back to the car in a few seconds</p>
                    </div>
                    <script>
                        setTimeout(function() {
                            <?php
                            if ($carId) {
                                echo "window.location.href = '" . $us_url_root . "app/cars/details.php?car_id=" . (int)$carId . "';";
                            } else {
                                echo "window.location.href = '" . $us_url_root . "';";
                            }
                            ?>
                        }, 5000);
                    </script>
                    <?php elseif ($post_attempted): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
                        <h4>Message Not Sent</h4>
                        <p class="text-muted">There was a problem delivering your message. Please try again or contact the administrator.</p>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <p class="text-muted">Please use the contact form on the car details page to send a message.</p>
                    </div>
                    <?php endif; ?>
                </div><!-- End of main content section -->
            </div> <!-- /.col -->
        </div> <!-- /.row -->
    </div> <!-- /.container -->
</div> <!-- /.wrapper -->