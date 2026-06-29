<?php
/**
 * contact_owner.php
 * Allows registered users to contact the owner of a car in the registry.
 *
 * Handles form submission, validates CSRF token, and retrieves user/car info for messaging.
 * Uses the site template for layout and security.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 */
use ElanRegistry\OwnerView;

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

if (!securePage($php_self)) {
    die();
}

//Forms posted now process it
if (!empty($_POST)) {
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        include_once $abs_us_root . $us_url_root . 'usersc/scripts/token_error.php';
    } else {
        $action = Input::get('action');
        if ($action === 'contact_owner') {

            $carID = (int) Input::get('car_id');
            if ($carID <= 0) {
                Redirect::to('/');
            }
            $fromResults = $db->findById($user->data()->id, "users")->results();
            $toResults   = $db->findById($carID, "cars")->results();
            if (empty($fromResults) || empty($toResults)) {
                Redirect::to('/');
            }
            $fromData = $fromResults[0];
            $toData   = $toResults[0];

            $from = array(
                'id'    => $fromData->id,
                'fname' => $fromData->fname,
                'lname' => $fromData->lname,
                'email' => $fromData->email,
            );

            $to = array(
                'id' => $toData->user_id,
                'fname' => $toData->fname,
                'lname' => $toData->lname,
                'email' => $toData->email,
            );
        } else {
            Redirect::to('/');
        }
    } // End Post with data
} // End Post
?>


<div class="page-wrapper">
    <div class="container-fluid">
        <div class="page-container">
        <br>
        <div class="card registry-card">
            <div class="card-header card-header-er-primary">
                <h2 class="mb-0 card-header-er-primary-text">Contact Owner</h2>
            </div>
            <div class="card-body">
                <!-- Contact Information -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h5 class="text-primary"><i class="fas fa-user-circle"></i> From</h5>
                        <div class="bg-light p-3 rounded">
                            <div class="mb-2">
                                <strong><?= OwnerView::displayName((object)$from) ?></strong>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h5 class="text-primary"><i class="fas fa-user"></i> To</h5>
                        <div class="bg-light p-3 rounded">
                            <div class="mb-2">
                                <strong><?= OwnerView::displayName((object)$to) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Message Form -->
                <form name="contactform" method="post" action="send-owner-email.php" class="needs-validation" novalidate>
                    <div class="mb-4">
                        <label for="message" class="form-label h5">
                            <i class="fas fa-comment text-primary"></i> Your Message
                        </label>
                        <textarea 
                            required 
                            class="form-control" 
                            name="message" 
                            id="message"
                            maxlength="2000" 
                            rows="8" 
                            placeholder="Enter your message to the car owner..."
                            style="resize: vertical;"
                        ></textarea>
                        <div class="form-text">
                            <i class="fas fa-info-circle"></i> Maximum 2000 characters. Your contact information will be included so the owner can reply directly to you.
                        </div>
                        <div class="invalid-feedback">
                            Please enter a message.
                        </div>
                    </div>

                    <!-- Hidden Fields -->
                    <input type='hidden' name='csrf' value='<?= Token::generate(); ?>' />
                    <input type='hidden' name='action' value='send_message' />
                    <input type='hidden' name='to_user_id' value='<?= htmlspecialchars($to['id'], ENT_QUOTES, 'UTF-8'); ?>' />
                    <input type='hidden' name='car_id' value='<?= htmlspecialchars($carID, ENT_QUOTES, 'UTF-8'); ?>' />

                    <!-- Submit Button -->
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <a href="javascript:history.back()" class="btn btn-outline-secondary me-md-2">
                            <i class="fas fa-arrow-left"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-paper-plane"></i> Send Message
                        </button>
                    </div>
                </form>
            </div> <!-- car body -->
        </div> <!-- /.col -->
    </div> <!-- /.row -->
        </div> <!-- page-container -->
    </div> <!-- container-fluid -->
</div> <!-- page-wrapper -->


<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>
