<?php
declare(strict_types=1);

use ElanRegistry\Input;

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
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';
?>

<?php if (!securePage($php_self)) {
    die();
} ?>

<?php
$errors = [];
$email_sent = false;
$post_attempted = isset($_POST['email']);

/**
 * Remove suspicious email-header keywords from user input.
 *
 * This is a legacy defense-in-depth measure. Email is sent via the Brevo
 * API (not raw SMTP headers), so header injection via concatenation is not
 * a risk. The function also strips "href" from all input fields to reduce
 * link injection in rendered output.
 *
 * Note: str_replace is not a robust injection defense — it can be bypassed
 * by wrapping the target string within itself (e.g. "ccontent-typeontent-type").
 * Retained for backward compatibility and minimal harm.
 *
 * @param string $string Raw user input
 * @return string String with header keywords removed
 */
function cleanString(string $string): string
{
    $bad = array("content-type", "bcc:", "to:", "cc:", "href");
    return str_replace($bad, "", $string);
}

if ($post_attempted) {

    // CSRF Protection
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        include_once $abs_us_root . $us_url_root . 'usersc/scripts/token_error.php';
        exit;
    }

    $logUserId = ($user->isLoggedIn() && $user->data()) ? (int)$user->data()->id : 0;
    $email_to = getFeedbackEmail();
    $email_subject = "[ELANREGISTRY] Feedback";

    // Raw values — the email view template escapes via EmailTemplate methods
    $name = Input::raw('name');
    $email_from = Input::raw('email');
    $id_from = Input::raw('id');
    $comments = Input::raw('comments');

    // Validate required fields
    if ($name === null || $name === '' ||
        $email_from === null || $email_from === '' ||
        $id_from === null || $id_from === '' ||
        $comments === null || $comments === '') {
        $errors[] = 'We are sorry, but there appears to be a problem with the form you submitted.';
    }

    if (empty($errors)) {
        $email_exp = '/^[A-Za-z0-9._%-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,4}$/';
        if (!preg_match($email_exp, $email_from)) {
            $errors[] = 'The Email Address you entered does not appear to be valid.';
        }

        $string_exp = "/^[A-Za-z .'-]+$/";
        if (!preg_match($string_exp, $name)) {
            $errors[] = 'The Name you entered does not appear to be valid.';
        }

        if (strlen($comments) < 2) {
            $errors[] = 'The Comments you entered do not appear to be valid.';
        }
    }

    if (empty($errors)) {
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

        if (empty($body)) {
            logger($logUserId, LogCategories::LOG_CATEGORY_FEEDBACK_FORM, "send-feedback.php: email_body() returned empty — template missing or failed");
            $errors[] = 'Your message could not be sent due to a server configuration error. Please contact the administrator.';
        }
    }

    if (empty($errors)) {
        // Reply-to is set to the submitter so the admin can reply directly.
        $result = email($email_to, $email_subject, $body, ['replyTo' => $email_from, 'reply_name' => $name]);

        if ($result !== true) {
            logger($logUserId, LogCategories::LOG_CATEGORY_FEEDBACK_FORM, "Error sending feedback email: unknown delivery error");
            $errors[] = 'Your message could not be delivered. Please try again or contact the administrator.';
        } else {
            logger($logUserId, LogCategories::LOG_CATEGORY_FEEDBACK_FORM, "Complete: sent to " . $email_to . " with subject '" . $email_subject . "'");
            $email_sent = true;
        }
    }

    foreach ($errors as $error) {
        usError($error);
    }
}

?>
<div class="page-wrapper">
    <div class="container-fluid">
        <div class="row">
            <div class="col-sm-12">
                <div class="jumbotron">
                    <?php if ($email_sent): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-envelope fa-3x text-success mb-3"></i>
                        <h4>Message Sent</h4>
                        <p class="text-muted">Thank you for your feedback! Your help makes the Elan Registry better!</p>
                        <p class="text-muted">Taking you back in a few seconds</p>
                    </div>
                    <script>
                        setTimeout(function() {
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
                    <?php elseif ($post_attempted): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
                        <h4>Message Not Sent</h4>
                        <p class="text-muted">There was a problem with your submission. Please go back and try again.</p>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <p class="text-muted">Please submit the feedback form to send a message.</p>
                    </div>
                    <?php endif; ?>
                </div><!-- End of main content section -->
            </div> <!-- /.col -->
        </div> <!-- /.row -->
    </div> <!-- /.container -->
</div> <!-- /.wrapper -->


<!-- footers -->
<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; //custom template footer
?>