<?php

declare(strict_types=1);

if (count(get_included_files()) == 1) { die(); }

/**
 * Photos card, shared by app/owner/cars/details.php and
 * app/views/cars/_verify_landing.php. CarView::displayCarousel() renders the
 * body on the server, including the placeholder for a car with no images.
 *
 * Caller must set:
 *   $car        Car    The car (ElanRegistry\Car\Car)
 *   $headingTag string Card heading element, h1-h6 (default h3)
 *
 * The defaults below let PHPStan analyze this file alone. When $car is not a
 * Car, the card draws nothing and logs one entry.
 */
$car ??= null;
$headingTag ??= 'h3';

if (!$car instanceof ElanRegistry\Car\Car) {
    logger(0, ElanRegistry\LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        '_photos_card.php: $car is ' . get_debug_type($car) . ', not a Car. Card not drawn.');
    return;
}

$headingTag = in_array($headingTag, ['h1','h2','h3','h4','h5','h6'], true) ? $headingTag : 'h3';
?>
<div class="card registry-card mb-4">
    <div class="card-header">
        <<?= $headingTag ?> class="mb-0"><i class="fas fa-images text-primary" aria-hidden="true"></i> Photos</<?= $headingTag ?>>
    </div>
    <div class="card-body">
        <?= ElanRegistry\CarView::displayCarousel($car) ?>
    </div>
</div>
