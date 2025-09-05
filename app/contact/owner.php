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
require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
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

            $carID = Input::get('car_id');
            // Get the combined user+profile
            $fromData = $db->findById($user->data()->id, "usersview")->results()[0];
            $toData = $db->findById($carID, "cars")->results()[0];

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
            <div class="card-header">
                <h2 class="mb-0">Contact Owner</h2>
            </div>
            <div class="card-body">
                <form name="contactform" method="post" action="send-owner-email.php">

                    <table id="cartable" class="table table-striped table-bordered table-sm" aria-describedby="card-header">
                        <tr>
                            <th scope=column><strong>From User ID</strong></th>
                            <th scope=column><?= $from['id'] ?></th>
                        </tr>
                        <tr>
                            <td><strong>From</strong></td>
                            <td> <?php echo $from['fname'] . ' ' . $from['lname']; ?> </td>
                        </tr>
                        <tr>
                            <td><strong>From email</strong></td>
                            <td> <?= $from['email'] ?> </td>
                        </tr>
                        <tr></tr>
                        <tr>
                            <td><strong>To User ID</strong></td>
                            <td><?= $to['id'] ?></td>
                        </tr>
                        <tr>
                            <td><strong>To</strong></td>
                            <td> <?php echo $to['fname'] . ' ' . $to['lname']; ?> </td>
                        </tr>
                        <tr>
                            <td><label for='message'><strong>Message</strong></label></td>
                            <td><textarea required class="form-control" name="message" id="message" rows="10" wrap="soft" placeholder="Enter a message" oninvalid="this.setCustomValidity('Please enter a message')" oninput="setCustomValidity('')"></textarea></td>
                        </tr>
                        <tr>
                            <td></td>
                            <td>
                                <input type='hidden' name='csrf' value='<?= Token::generate(); ?>' />
                                <input type='hidden' name='action' value='send_message' />
                                <input type='hidden' name='from_user_id' id='from_user_id' value='<?= htmlspecialchars($from['id'], ENT_QUOTES, 'UTF-8'); ?>' />
                                <input type='hidden' name='to_user_id' id='to_user_id' value='<?= htmlspecialchars($to['id'], ENT_QUOTES, 'UTF-8'); ?>' />
                                <input class='btn btn-primary' type='submit' value='Send' class='Submit' />
                            </td>
                        </tr>
                    </table>
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
