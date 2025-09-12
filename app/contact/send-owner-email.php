<?php
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

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}
// EDIT THE 2 LINES BELOW AS REQUIRED
$subject = 'elanregistry.org - Owner to Owner Contact';

// Initialize message arrays
$errors = [];
$successes = [];

function died($error)
{
    // your error code can go here
    echo 'We are very sorry, but there were error(s) found with the form you submitted. ';
    echo 'These errors appear below.<br /><br />';
    echo $error . '<br /><br />';
    echo 'Please go back and fix these errors.<br /><br />';
    die();
}

// Make sure no one tries to add header like keywords
function clean_string($string)
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

            $options        =  array(
                'reply_name' => $fromName,
                'reply'     => $fromEmail,
            );

            $template       =  array(
                'message'   => clean_string($message),
                'from'      => $fromName,
                'fromEmail' => $fromEmail,
                'to'        => $toName
            );

            $body = email_body('_email_contact_owner.php', $template);

            $result = email($toEmail, $subject, $body, $options);

            // Log the email sending (no session message needed - we show "Message Sent" page)
            logger($user->data()->id, "ElanRegistry", "contact_owner_email.php from " . $fromEmail . " to " . $toEmail);
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
    if (!empty($successes)) {
        foreach ($successes as $success) {
            usSuccess($success);
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
                    <!-- Messages now handled by UserSpice session system in template (Issue #237) -->
                    <div class="text-center py-4">
                        <i class="fas fa-envelope fa-3x text-success mb-3"></i>
                        <h4>Message Sent</h4>
                        <p class="text-muted">Taking you back to the car in a few seconds</p>
                    </div>
                    <script>
                        //Using setTimeout to execute a function after 5 seconds.
                        setTimeout(function() {
                            //Redirect back to the car details page
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
                </div><!-- End of main content section -->
            </div> <!-- /.col -->
        </div> <!-- /.row -->
    </div> <!-- /.container -->
</div> <!-- /.wrapper -->