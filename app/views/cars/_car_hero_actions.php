<?php
if (count(get_included_files()) == 1) { die(); }

$_baseUrl  = htmlspecialchars($us_url_root, ENT_QUOTES, 'UTF-8');
$heroCarId = (int)($heroCarId ?? 0);

switch ($context) {
    case 'owner_detail': ?>
        <form method="POST" action="<?= $_baseUrl ?>app/cars/edit.php" class="d-inline">
            <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
            <input type="hidden" name="action" value="updateCar" />
            <input type="hidden" name="car_id" value="<?= $heroCarId ?>" />
            <button class="btn btn-light btn-lg" type="submit">
                <i class="fas fa-edit" aria-hidden="true"></i> Update Car
            </button>
        </form>
        <?php break;

    case 'admin_detail': ?>
        <div class="alert alert-warning mb-2">
            <i class="fas fa-shield-alt" aria-hidden="true"></i> <strong>Administrative Override:</strong>
            You are editing a car that you do not own using Administrator/Editor privileges.
        </div>
        <form method="POST" action="<?= $_baseUrl ?>app/cars/edit.php" class="d-inline me-2">
            <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
            <input type="hidden" name="action" value="updateCar" />
            <input type="hidden" name="car_id" value="<?= $heroCarId ?>" />
            <input type="hidden" name="admin_override" value="1" />
            <button class="btn btn-warning btn-lg" type="submit">
                <i class="fas fa-edit" aria-hidden="true"></i> Admin Edit Car
            </button>
        </form>
        <form method="POST" action="<?= $_baseUrl ?>app/contact/owner.php" class="d-inline">
            <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
            <input type="hidden" name="action" value="contact_owner" />
            <input type="hidden" name="car_id" value="<?= $heroCarId ?>" />
            <button class="btn btn-light btn-lg" type="submit">
                <i class="fas fa-envelope" aria-hidden="true"></i> Contact Owner
            </button>
        </form>
        <?php break;

    case 'visitor_detail': ?>
        <form method="POST" action="<?= $_baseUrl ?>app/contact/owner.php" class="d-inline">
            <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
            <input type="hidden" name="action" value="contact_owner" />
            <input type="hidden" name="car_id" value="<?= $heroCarId ?>" />
            <button class="btn btn-light btn-lg" type="submit">
                <i class="fas fa-envelope" aria-hidden="true"></i> Contact Owner
            </button>
        </form>
        <?php break;

    case 'guest_detail': ?>
        <a href="<?= $_baseUrl ?>users/login.php" class="btn btn-outline-light btn">
            <i class="fas fa-sign-in-alt me-1" aria-hidden="true"></i> Log in to contact owner
        </a>
        <?php break;

    case 'owner_account': ?>
        <form method="POST" action="<?= $_baseUrl ?>app/cars/edit.php" class="d-inline me-2">
            <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
            <input type="hidden" name="action" value="updateCar" />
            <input type="hidden" name="car_id" value="<?= $heroCarId ?>" />
            <button class="btn btn-light btn-lg" type="submit">
                <i class="fas fa-edit" aria-hidden="true"></i> Update Car
            </button>
        </form>
        <a class="btn btn-outline-light btn-lg" role="button" href="<?= $_baseUrl ?>app/cars/details.php?car_id=<?= $heroCarId ?>">
            <i class="fas fa-eye" aria-hidden="true"></i> View Details
        </a>
        <?php break;
}
