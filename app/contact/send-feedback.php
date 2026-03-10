<?php
declare(strict_types=1);

/**
 * send_form_email.php
 * Handles feedback form submissions and sends emails to the registry admin.
 *
 * Validates input, checks CSRF token, and sends feedback via email.
 * Uses the site template for layout and security.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 */
require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
?>

<?php if (!securePage($php_self)) {
    die();
} ?>

<?php
if (isset($_POST['email'])) {
    
    // CSRF Protection
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        include_once $abs_us_root . $us_url_root . 'usersc/scripts/token_error.php';
        exit;
    }

    // EDIT THE 2 LINES BELOW AS REQUIRED
    $email_to = getFeedbackEmail();
    $email_subject = "[ELANREGISTRY] Feedback";

    function died($error): void
    {
        // your error code can go here
        echo "We are very sorry, but there were error(s) found with the form you submitted. ";
        echo "These errors appear below.<br /><br />";
        echo $error . "<br /><br />";
        echo "Please go back and fix these errors.<br /><br />";
        die();
    }


    // Get and validate form data using secure Input::get()
    $name = Input::get('name');
    $email_from = Input::get('email');
    $id_from = Input::get('id');
    $comments = Input::get('comments');
    
    // Validation expected data exists
    if (empty($name) || empty($email_from) || empty($id_from) || empty($comments)) {
        died('We are sorry, but there appears to be a problem with the form you submitted.');
    }

    $error_message = "";
    $email_exp = '/^[A-Za-z0-9._%-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,4}$/';

    if (!preg_match($email_exp, $email_from)) {
        $error_message .= 'The Email Address you entered does not appear to be valid.<br />';
    }

    $string_exp = "/^[A-Za-z .'-]+$/";

    if (!preg_match($string_exp, $name)) {
        $error_message .= 'The Name you entered does not appear to be valid.<br />';
    }

    if (strlen($comments) < 2) {
        $error_message .= 'The Comments you entered do not appear to be valid.<br />';
    }

    if (strlen($error_message) > 0) {
        died($error_message);
    }

    function cleanString($string): string
    {
        $bad = array("content-type", "bcc:", "to:", "cc:", "href");
        return str_replace($bad, "", $string);
    }

    // Clean the input data
    $name = cleanString($name);
    $email_from = cleanString($email_from);
    $id_from = cleanString($id_from);
    $comments = cleanString($comments);

    // Prepare template data
    $template = array(
        'name' => $name,
        'email' => $email_from,
        'accountId' => $id_from,
        'comments' => $comments
    );

    // Generate email body using template
    $body = email_body('_email_feedback.php', $template);

    // Set reply-to so admin can reply directly to the feedback sender.
    // Call sendinblue() directly when available (test/prod); fall back to PHPMailer email() (dev).
    if (function_exists('sendinblue')) {
        $email_sent = sendinblue($email_to, $email_subject, $body, $name, [
            'reply'      => $email_from,
            'reply_name' => $name,
        ]);
    } else {
        $email_sent = email($email_to, $email_subject, $body, ['replyTo' => $email_from]);
    }
    if (!$email_sent) {
        logger(1, LogCategories::LOG_CATEGORY_FEEDBACK_FORM, "Error sending email");
    }
    logger(1, LogCategories::LOG_CATEGORY_FEEDBACK_FORM, "Complete: sent to " . $email_to . " with subject '" . $email_subject . "'");
}

?>
<div class="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-sm-12">
                <div class="jumbotron">
                    <!-- Use same success page style as contact owner -->
                    <div class="text-center py-4">
                        <i class="fas fa-envelope fa-3x text-success mb-3"></i>
                        <h4>Message Sent</h4>
                        <p class="text-muted">Thank you for your feedback! Your help makes the Elan Registry better!</p>
                        <p class="text-muted">Taking you back in a few seconds</p>
                    </div>
                    <script>
                        //Using setTimeout to execute a function after 5 seconds.
                        setTimeout(function() {
                            //Redirect back to where they came from
                            <?php 
                            $referrer = Input::get('referrer');
                            if ($referrer && filter_var($referrer, FILTER_VALIDATE_URL) && strpos($referrer, $host) !== false) {
                                echo "window.location.href = '" . htmlspecialchars($referrer, ENT_QUOTES, 'UTF-8') . "';";
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


<!-- footers -->
<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; //custom template footer
?>