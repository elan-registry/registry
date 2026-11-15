<?php
if (count(get_included_files()) == 1) { die(); }

/** @var string $context Set by the including page. */
$_baseUrl    = htmlspecialchars($us_url_root, ENT_QUOTES, 'UTF-8');
$heroCarId   = (int)($heroCarId ?? 0);
$heroNoOwner = (bool)($heroNoOwner ?? false);
// Status, not an action: a car of the noowner system account has nobody to contact (#2505).
$heroNoOwnerStatus = '<p class="mb-2 small text-white-75" data-testid="hero-no-owner-status">'
    . '<i class="fas fa-info-circle me-1" aria-hidden="true"></i>This car has no current owner.</p>';

switch ($context) {
    case 'owner_detail': ?>
        <form method="POST" action="<?= $_baseUrl ?>app/owner/cars/edit.php" class="d-inline">
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
        <?php if ($heroNoOwner): ?>
        <?= $heroNoOwnerStatus ?>
        <?php endif; ?>
        <form method="POST" action="<?= $_baseUrl ?>app/owner/cars/edit.php" class="d-inline me-2">
            <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
            <input type="hidden" name="action" value="updateCar" />
            <input type="hidden" name="car_id" value="<?= $heroCarId ?>" />
            <input type="hidden" name="admin_override" value="1" />
            <button class="btn btn-warning btn-lg" type="submit">
                <i class="fas fa-edit" aria-hidden="true"></i> Admin Edit Car
            </button>
        </form>
        <?php if (!$heroNoOwner): ?>
        <form method="POST" action="<?= $_baseUrl ?>app/owner/contact/owner.php" class="d-inline">
            <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
            <input type="hidden" name="action" value="contact_owner" />
            <input type="hidden" name="car_id" value="<?= $heroCarId ?>" />
            <button class="btn btn-light btn-lg" type="submit">
                <i class="fas fa-envelope" aria-hidden="true"></i> Contact Owner
            </button>
        </form>
        <?php endif; ?>
        <?php break;

    case 'no_owner_detail': ?>
        <?= $heroNoOwnerStatus ?>
        <a class="btn btn-light btn-lg" role="button" href="<?= $_baseUrl ?>app/owner/contact/index.php">
            <i class="fas fa-comment" aria-hidden="true"></i> Contact the Registry
        </a>
        <?php break;

    case 'visitor_detail': ?>
        <form method="POST" action="<?= $_baseUrl ?>app/owner/contact/owner.php" class="d-inline">
            <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
            <input type="hidden" name="action" value="contact_owner" />
            <input type="hidden" name="car_id" value="<?= $heroCarId ?>" />
            <button class="btn btn-light btn-lg" type="submit">
                <i class="fas fa-envelope" aria-hidden="true"></i> Contact Owner
            </button>
        </form>
        <?php break;

    case 'guest_detail':
        // The login link leads to a Contact Owner control that a noowner car does not have.
        if ($heroNoOwner): ?>
        <?= $heroNoOwnerStatus ?>
        <?php else: ?>
        <a href="<?= $_baseUrl ?>users/login.php" class="btn btn-outline-light btn">
            <i class="fas fa-sign-in-alt me-1" aria-hidden="true"></i> Log in to contact owner
        </a>
        <?php endif;
        break;

    case 'owner_account': ?>
        <form method="POST" action="<?= $_baseUrl ?>app/owner/cars/edit.php" class="d-inline me-2">
            <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
            <input type="hidden" name="action" value="updateCar" />
            <input type="hidden" name="car_id" value="<?= $heroCarId ?>" />
            <button class="btn btn-light btn-lg" type="submit">
                <i class="fas fa-edit" aria-hidden="true"></i> Update Car
            </button>
        </form>
        <a class="btn btn-outline-light btn-lg" role="button" href="<?= $_baseUrl ?>app/owner/cars/details.php?car_id=<?= $heroCarId ?>">
            <i class="fas fa-eye" aria-hidden="true"></i> View Details
        </a>
        <?php break;
}
