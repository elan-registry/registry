<?php
/**
 * contact_owner.php
 * Renders the contact-owner form pre-populated with sender and recipient info.
 * Form submission is handled client-side via ElanRegistryAPI → app/api/contact/send-owner-email.php.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 */
use ElanRegistry\OwnerView;

require_once '../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

if (!securePage($php_self)) {
    die();
}

$carID = (int) Input::get('car_id');
if ($carID <= 0) {
    Redirect::to('/');
}

$carResults = $db->findById($carID, 'cars')->results();
if (empty($carResults)) {
    Redirect::to('/');
}

$ownerResults = $db->findById((int) $carResults[0]->user_id, 'users')->results();
if (empty($ownerResults)) {
    Redirect::to('/');
}
$ownerData = $ownerResults[0];

$from = [
    'id'    => $user->data()->id,
    'fname' => $user->data()->fname,
    'lname' => $user->data()->lname,
];

$to = [
    'id'    => $ownerData->id,
    'fname' => $ownerData->fname,
    'lname' => $ownerData->lname,
];
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
                <form name="contactform" method="post" action="<?= $us_url_root ?>app/api/contact/send-owner-email.php" class="needs-validation" novalidate>
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
                    <input type='hidden' name='csrf' value='<?= htmlspecialchars(Token::generate(), ENT_QUOTES, 'UTF-8'); ?>' />
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


<script>
$(document).ready(function () {
    $('form[name="contactform"]').on('submit', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn  = $form.find('button[type="submit"]');
        var originalHtml = $btn.html();

        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sending...');

        new ElanRegistryAPI().post(
            '<?= $us_url_root ?>app/api/contact/send-owner-email.php',
            {
                action:      $form.find('input[name="action"]').val(),
                to_user_id:  $form.find('input[name="to_user_id"]').val(),
                car_id:      $form.find('input[name="car_id"]').val(),
                message:     $('#message').val()
            }
        ).then(function (response) {
            window.usSuccess(response.message);
            $form[0].reset();
        }).catch(function (error) {
            console.error('Contact owner form submission failed:', error);
            window.usError(error.message || 'Failed to send message.');
        }).finally(function () {
            $btn.prop('disabled', false).html(originalHtml);
        });
    });
});
</script>

<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>
