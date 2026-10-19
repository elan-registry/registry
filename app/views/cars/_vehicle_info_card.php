<?php
if (count(get_included_files()) == 1) { die(); }

/**
 * Vehicle Information card, shared by app/owner/cars/details.php,
 * usersc/account.php, and app/views/cars/_verify_landing.php.
 *
 * Caller must set:
 *   $carData      object        Car row (Car::data() or a plain `cars` row)
 *   $purchaseDate ?DateTime     Parsed purchasedate, or null
 *   $soldDate     ?DateTimeInterface From CarBadges::soldDate(), or null.
 *                               When set, the card shows the Sold row: the Sold
 *                               stamp and the date.
 *   $headingTag   string        Card heading element, h1-h6 (default h3)
 *
 * Caller may set:
 *   $viewerIsRegistryAdmin bool  True only when the viewer is an admin or an
 *                               editor (isRegistryAdmin()). Only true (strict)
 *                               shows the Email on file row. Default false.
 *   $ownerEmailState ?EffectiveEmailFlags The owner's deliverability flags
 *                               that apply now, from
 *                               CarRepository::effectiveEmailFlags(). The
 *                               profile is the system of record (#2101), so the
 *                               Email on file row reads its flags from here,
 *                               not from $carData. A block or bounce for an old
 *                               address does not apply (#2356). The row shows
 *                               "Opted out", "Bounced" and "Blocked" for each
 *                               flag that applies, each with its date and its
 *                               own tooltip (#2361). Null shows no row.
 *                               Default null.
 *
 * The card computes the Verified row itself from CarBadges::verifiedStatus().
 * A sold car has no Verified row.
 *
 * The defaults below let PHPStan analyze this file alone. They also make a
 * caller that forgets a variable get no warning. When $carData is missing or
 * is not an object, the card draws nothing and logs one entry.
 */
$carData ??= null;
$purchaseDate ??= null;
$soldDate ??= null;
$headingTag ??= 'h3';
$viewerIsRegistryAdmin ??= false;
$ownerEmailState ??= null;

if (!is_object($carData)) {
    logger(0, ElanRegistry\LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        '_vehicle_info_card.php: $carData is ' . get_debug_type($carData) . ', not an object. Card not drawn.');
    return;
}

$headingTag       = in_array($headingTag, ['h1','h2','h3','h4','h5','h6'], true) ? $headingTag : 'h3';
$_cardHeaderClass = $headingTag === 'h4' ? ' card-header-er-l2' : '';
$_headingClass    = $headingTag === 'h4' ? 'mb-0 card-header-er-l2-text' : 'mb-0';
$_subHeadingClass = $headingTag === 'h4' ? 'card-header-er-l4-text mb-2' : 'text-muted mb-3';

$_isSold          = \ElanRegistry\Car\CarBadges::isSold($carData->solddate ?? null);
$_verifiedStatus  = \ElanRegistry\Car\CarBadges::verifiedStatus($carData);
$_hasEmailState   = $ownerEmailState instanceof \ElanRegistry\Car\EffectiveEmailFlags;
$_emailOptedOut   = $_hasEmailState && $ownerEmailState->optedOut;
$_emailBounced    = $_hasEmailState && $ownerEmailState->bounced;
$_emailBlocked    = $_hasEmailState && $ownerEmailState->blocked;
// The row names why the registry does not mail this owner, so only admins
// and editors get it. The car's own owner does not.
$_showEmailRow    = $viewerIsRegistryAdmin === true && ($_emailOptedOut || $_emailBounced || $_emailBlocked);
// An unsold car always has a Verified row, so the section is never empty for it.
$_showOwnershipSection = $purchaseDate || $soldDate || !$_isSold || $_showEmailRow;

/**
 * Get a focusable help button that shows $tooltip in a Bootstrap tooltip.
 *
 * @param string $ariaLabel Accessible name of the button
 * @param string $tooltip   Tooltip text
 * @return string Button HTML with all values escaped
 */
$_helpButton = static function (string $ariaLabel, string $tooltip): string {
    return '<button type="button" class="btn btn-link p-0 align-baseline"'
        . ' data-bs-toggle="tooltip" data-bs-title="' . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . '"'
        . ' aria-label="' . htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') . '">'
        . '<i class="fas fa-circle-question" aria-hidden="true"></i></button>';
};

$_carLabel = 'car ' . (is_scalar($carData->id ?? null) ? (string) $carData->id : 'unknown');

/**
 * Get a date for display.
 *
 * DB.php sets sql_mode = '', so MySQL can return a zero date. DateTime turns
 * '0000-00-00' into Nov 30, -0001, so the card shows a zero date as missing.
 * A NULL or empty value also shows no date. A value that DateTime cannot
 * parse is a data defect, so it goes to the log.
 *
 * @param string|null $value   The date column value
 * @param string      $format  DateTime format for the output
 * @param string      $context Start of the log entry, for example "car 5 has ctime"
 * @return string The formatted date, or '' when the value is missing or bad
 */
$_formatDate = static function (?string $value, string $format, string $context): string {
    if (empty($value) || str_starts_with($value, '0000-00-00')) {
        return '';
    }
    try {
        return (new DateTime($value))->format($format);
    } catch (\Exception $e) {
        logger(0, ElanRegistry\LogCategories::LOG_CATEGORY_CAR_ERRORS, sprintf(
            "_vehicle_info_card.php: %s '%s', which is not a valid date. Date not shown: %s",
            $context,
            $value,
            $e->getMessage()
        ));
        return '';
    }
};
?>
<div class="card registry-card mb-4">
    <div class="card-header<?= $_cardHeaderClass ?>">
        <<?= $headingTag ?> class="<?= $_headingClass ?>">
            <i class="fas fa-car text-primary" aria-hidden="true"></i> Vehicle Information
        </<?= $headingTag ?>>
    </div>
    <div class="card-body">
        <!-- Basic Vehicle Details -->
        <dl class="row mb-4">
            <dt class="col-sm-4 text-muted">
                <i class="fas fa-calendar text-primary" aria-hidden="true"></i> Model Year
            </dt>
            <dd class="col-sm-8">
                <?= htmlspecialchars((string)($carData->year ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </dd>

            <dt class="col-sm-4 text-muted">
                <i class="fas fa-tag text-primary" aria-hidden="true"></i> Series
            </dt>
            <dd class="col-sm-8">
                <?= htmlspecialchars($carData->series ?? '', ENT_QUOTES, 'UTF-8') ?>
                <?= !empty($carData->variant) ? ' - ' . htmlspecialchars($carData->variant, ENT_QUOTES, 'UTF-8') : '' ?>
            </dd>

            <dt class="col-sm-4 text-muted">
                <i class="fas fa-car text-primary" aria-hidden="true"></i> Type
            </dt>
            <dd class="col-sm-8">
                <?= $carData->type ? htmlspecialchars($carData->type, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Not specified</em>' ?>
            </dd>

            <dt class="col-sm-4 text-muted">
                <i class="fas fa-barcode text-primary" aria-hidden="true"></i> Chassis
            </dt>
            <dd class="col-sm-8">
                <strong><?= $carData->chassis ? htmlspecialchars($carData->chassis, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Not specified</em>' ?></strong>
                <?php if (!empty($carData->chassis_override)): ?>
                    <span class="badge bg-warning text-dark ms-1">
                        <i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Validation Override
                    </span>
                <?php endif; ?>
            </dd>
        </dl>

        <hr>

        <!-- Appearance & Technical -->
        <h6 class="<?= $_subHeadingClass ?>">
            <i class="fas fa-palette text-secondary" aria-hidden="true"></i> Appearance & Technical
        </h6>
        <dl class="row mb-4">
            <dt class="col-sm-4 text-muted">Color</dt>
            <dd class="col-sm-8">
                <?= $carData->color ? htmlspecialchars($carData->color, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Not specified</em>' ?>
            </dd>

            <dt class="col-sm-4 text-muted">Engine</dt>
            <dd class="col-sm-8">
                <?= $carData->engine ? htmlspecialchars($carData->engine, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Not specified</em>' ?>
            </dd>
        </dl>

        <?php if ($_showOwnershipSection) { ?>
        <hr>

        <!-- Ownership & History -->
        <h6 class="<?= $_subHeadingClass ?>">
            <i class="fas fa-history text-secondary" aria-hidden="true"></i> Ownership & History
        </h6>
        <dl class="row mb-4">
            <?php if ($purchaseDate) { ?>
            <dt class="col-sm-4 text-muted">Purchase Date</dt>
            <dd class="col-sm-8"><?= $purchaseDate->format('F j, Y') ?></dd>
            <?php } ?>

            <?php if ($soldDate) { ?>
            <dt class="col-sm-4 text-muted">Sold</dt>
            <dd class="col-sm-8">
                <?= \ElanRegistry\Car\CarBadges::html(['sold'], 'stamp') ?>
                <span class="ms-2"><?= $soldDate->format('F j, Y') ?></span>
            </dd>
            <?php } ?>

            <?php if (!$_isSold) { ?>
            <dt class="col-sm-4 text-muted">
                Verified <?= $_helpButton('What Verified means', \ElanRegistry\Car\CarBadges::verifiedTooltip()) ?>
            </dt>
            <dd class="col-sm-8">
                <?php if ($_verifiedStatus !== null) { ?>
                <?= \ElanRegistry\Car\CarBadges::html(['verified'], 'stamp') ?>
                <span class="ms-2"><?= ($_verifiedStatus['source'] === 'confirmed' ? 'Last confirmed ' : 'Current since ') . $_verifiedStatus['date']->format('F j, Y') ?></span>
                <?php } else { ?>
                <i class="far fa-square text-muted" aria-hidden="true"></i>
                <em class="text-muted">Not specified</em>
                <?php } ?>
            </dd>
            <?php } ?>

            <?php if ($_showEmailRow) { ?>
            <dt class="col-sm-4 text-muted">
                Email on file <?= $_helpButton('What Email on file means', "Only admins and editors see this row. It shows why the registry does not send verification email or messages from other owners to this owner.") ?>
            </dt>
            <dd class="col-sm-8">
                <?php if ($_emailOptedOut) { $_date = $_formatDate($ownerEmailState->optedOutAt, 'F j, Y', $_carLabel . ' owner email flag email_opted_out_at has'); ?>
                <span class="me-3">Opted out<?= $_date !== '' ? ' ' . htmlspecialchars($_date, ENT_QUOTES, 'UTF-8') : '' ?> <?= $_helpButton('What Opted out means', 'The owner stopped all registry email except account email. The owner can lift the opt-out with Resume registry email in Account Settings, or an admin can lift it.') ?></span>
                <?php } ?>
                <?php if ($_emailBounced) { $_date = $_formatDate($ownerEmailState->bouncedAt, 'F j, Y', $_carLabel . ' owner email flag email_bounced_at has'); ?>
                <span class="me-3">Bounced<?= $_date !== '' ? ' ' . htmlspecialchars($_date, ENT_QUOTES, 'UTF-8') : '' ?> <?= $_helpButton('What Bounced means', "Email to this owner's address bounced. The registry does not send verification email, messages from other owners, or messages from an admin to this owner. The owner can lift the bounce by confirming a different email address, or an admin can lift it.") ?></span>
                <?php } ?>
                <?php if ($_emailBlocked) { $_date = $_formatDate($ownerEmailState->blockedAt, 'F j, Y', $_carLabel . ' owner email flag email_blocked_at has'); ?>
                <span class="me-3">Blocked<?= $_date !== '' ? ' ' . htmlspecialchars($_date, ENT_QUOTES, 'UTF-8') : '' ?> <?= $_helpButton('What Blocked means', "This owner's address is blocked. The registry does not send verification email or messages from other owners to this owner. A message from an admin is still sent. The owner can lift the block by confirming a different email address, or an admin can lift it.") ?></span>
                <?php } ?>
            </dd>
            <?php } ?>
        </dl>
        <?php } ?>

        <?php if (!empty($carData->comments)) { ?>
        <hr>
        <h6 class="<?= $_subHeadingClass ?>">
            <i class="fas fa-comment text-secondary" aria-hidden="true"></i> Owner Comments
        </h6>
        <div class="bg-light p-3 rounded">
            <?= nl2br(htmlspecialchars($carData->comments, ENT_QUOTES, 'UTF-8')) ?>
        </div>
        <?php } ?>

        <!-- Registry Information -->
        <hr>
        <div class="row text-center">
            <div class="col-6">
                <i class="fas fa-plus-circle text-success d-block mb-1" aria-hidden="true"></i>
                <small class="text-muted d-block">Added to Registry</small>
                <strong>
                    <?= $_formatDate(isset($carData->ctime) ? (string) $carData->ctime : null, 'M j, Y', $_carLabel . ' has ctime') ?>
                </strong>
            </div>
            <div class="col-6">
                <i class="fas fa-edit text-info d-block mb-1" aria-hidden="true"></i>
                <small class="text-muted d-block">Last Updated</small>
                <strong>
                    <?= $_formatDate(isset($carData->mtime) ? (string) $carData->mtime : null, 'M j, Y', $_carLabel . ' has mtime') ?>
                </strong>
            </div>
        </div>
    </div>
</div>
