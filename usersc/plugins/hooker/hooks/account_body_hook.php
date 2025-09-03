<?php
if (count(get_included_files()) == 1) die(); //Direct Access Not Permitted Leave this line in place

global $user;

// Get some interesting user information to display later

$user_id = $user->data()->id;

// USER ID is in $user_id .  Use the USER ID to get the users Profile information
$userQ = $db->query("SELECT * FROM usersview WHERE id = ?", array($user_id));
if ($userQ->count() > 0) {
    $thatUser = $userQ->results();
}

$signupdate = new DateTime($thatUser[0]->join_date);
$lastlogin = new DateTime($thatUser[0]->last_login);

?>

<div class="card registry-card">
    <div class="card-header">
        <h4 class="mb-0"><i class="fas fa-user-circle"></i> Account Information</h4>
    </div>
    <div class="card-body">
        <!-- Name Section -->
        <div class="mb-3">
            <div class="d-flex align-items-center mb-2">
                <i class="fas fa-user text-primary me-2"></i>
                <small class="text-muted text-uppercase fw-bold">Full Name</small>
            </div>
            <p class="mb-0 ps-3">
                <?= ucfirst($thatUser[0]->fname) . ' ' . ucfirst($thatUser[0]->lname) ?>
                <br>
                <small class="text-muted">@<?= $thatUser[0]->username ?></small>
            </p>
        </div>

        <!-- Email Section -->
        <div class="mb-3">
            <div class="d-flex align-items-center mb-2">
                <i class="fas fa-envelope text-primary me-2"></i>
                <small class="text-muted text-uppercase fw-bold">Email Address</small>
            </div>
            <p class="mb-0 ps-3">
                <?= $thatUser[0]->email ?>
            </p>
        </div>

        <!-- Location Section -->
        <div class="mb-3">
            <div class="d-flex align-items-center mb-2">
                <i class="fas fa-map-marker-alt text-primary me-2"></i>
                <small class="text-muted text-uppercase fw-bold">Location</small>
            </div>
            <p class="mb-0 ps-3">
                <?php 
                $location = [];
                if (!empty($thatUser[0]->city)) $location[] = html_entity_decode($thatUser[0]->city);
                if (!empty($thatUser[0]->state)) $location[] = html_entity_decode($thatUser[0]->state);
                if (!empty($thatUser[0]->country)) $location[] = html_entity_decode($thatUser[0]->country);
                echo !empty($location) ? implode(', ', $location) : '<span class="text-muted fst-italic">Not specified</span>';
                ?>
            </p>
        </div>

        <hr class="my-3">

        <!-- Account Stats -->
        <div class="row text-center">
            <div class="col-6">
                <div class="border-end">
                    <i class="fas fa-calendar-alt text-success d-block mb-1"></i>
                    <small class="text-muted d-block">Member Since</small>
                    <strong><?= $signupdate->format("M Y") ?></strong>
                </div>
            </div>
            <div class="col-6">
                <i class="fas fa-chart-line text-info d-block mb-1"></i>
                <small class="text-muted d-block">Total Logins</small>
                <strong><?= number_format($thatUser[0]->logins) ?></strong>
            </div>
        </div>

        <div class="mt-3 text-center">
            <small class="text-muted d-block mb-2">
                <i class="fas fa-clock"></i> Last login: <?= $lastlogin->format("M j, Y") ?>
            </small>
        </div>
        
        <div class="mt-3 d-grid">
            <a class="btn btn-primary" href="<?= $us_url_root ?>users/user_settings.php">
                <i class="fas fa-edit"></i> Update Account
            </a>
        </div>
    </div>
</div>


<script>
    $(document).ready(function() {
        // Completely prevent avatar loading to avoid CSP violations
        // Remove avatar image before it can load from Gravatar
        $('.row .col-md-3 img').each(function() {
            // Prevent the image from loading by removing src attribute and element
            $(this).removeAttr('src').remove();
        });
        
        // Remove other avatar-related elements
        $('.row .col-md-3 a').first().remove(); // Edit Button
        $('.row .col-md-3 .idd').remove(); // Username (we're showing it in Account Info instead)
        
        // Remove the name paragraph but keep the container
        $('.row .col-md-3 p').first().remove(); // Remove name paragraph that creates spacing
        
        // Fix column layout without breaking Bootstrap grid
        $('.col-sm-12.col-md-3').removeClass('col-sm-12').addClass('col-12');
        
        // Reduce padding and margins more conservatively
        $('.row .col-md-3').removeClass('mt-2').addClass('mt-1');
        $('.row .col-md-3 .card').removeClass('p-4').addClass('p-3');
        
        // Add a notice about disabled avatars (optional)
        $('.row .col-md-3 .image').prepend(
            '<div class="text-center mb-3">' +
            '<i class="fas fa-user-circle fa-4x text-muted"></i>' +
            '<p class="small text-muted mt-2">Profile pictures disabled</p>' +
            '</div>'
        );
    });
    
    // Additional protection: intercept any image requests to Gravatar
    $(document).on('error', 'img[src*="gravatar.com"]', function() {
        $(this).remove(); // Remove any Gravatar images that might slip through
    });
</script>