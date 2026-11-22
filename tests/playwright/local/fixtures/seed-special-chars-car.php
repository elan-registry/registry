<?php
declare(strict_types=1);

use ElanRegistry\Car\CarRepository;

/**
 * Standalone fixture script for the #844 tests in
 * tests/playwright/car-edit-text-save.spec.js (issue #2737).
 *
 * Seeds one car whose comments hold special characters, stored raw. The spec
 * checks that the details page and the edit form show the text as written, and
 * not as HTML entities. The car belongs to the existing `noowner` user. The
 * script creates and deletes no user rows.
 *
 * It does not reuse seed-output-escaping-car.php. Each seed deletes its own
 * marker rows first, so two specs that share one seed can delete each other's
 * car when they run in parallel workers.
 *
 * Usage:
 *   php seed-special-chars-car.php            seed (deletes earlier marker rows first)
 *   php seed-special-chars-car.php --cleanup  delete the marker rows only
 *
 * Prints one JSON line to stdout: in seed mode {"carId", "comments"}, with
 * --cleanup {"cleaned": true}
 *
 * GUARD: this must NEVER run against a deployed environment. The script
 * refuses to run unless US_ENVIRONMENT=development.
 */

// This script writes to the database. It must never answer a web request.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// users/init.php needs these to locate z_us_root.php (same approach as
// tests/bootstrap-integration.php).
$projectRoot = dirname(__DIR__, 4);
$_SERVER['DOCUMENT_ROOT'] = $projectRoot;
$_SERVER['PHP_SELF'] = '/tests/';

require_once $projectRoot . '/vendor/autoload.php';

// Check the environment guard BEFORE anything can touch a database.
\Dotenv\Dotenv::createImmutable($projectRoot)->safeLoad();

if (($_ENV['US_ENVIRONMENT'] ?? getenv('US_ENVIRONMENT') ?: '') !== 'development') {
    fwrite(STDERR, "ERROR: seed-special-chars-car.php refused to run — US_ENVIRONMENT is not 'development'.\n");
    fwrite(STDERR, "This fixture writes directly to the configured database and must only run against\n");
    fwrite(STDERR, "the local Docker dev database, never test.elanregistry.org or elanregistry.org.\n");
    exit(1);
}

// init.php does session and cookie work that assumes a web request. Those
// errors do not affect the DB singleton, so they are not fatal here.
set_error_handler(static function (): bool {
    return true;
});
ob_start();
try {
    require_once $projectRoot . '/users/init.php';
} catch (\Throwable $e) {
    fwrite(STDERR, "NOTE: users/init.php threw during CLI bootstrap (expected outside a web request): {$e->getMessage()}\n");
}
$initOutput = ob_get_clean();
if (trim((string) $initOutput) !== '') {
    fwrite(STDERR, "NOTE: {$initOutput}\n");
}
restore_error_handler();

/** `cars.model` is varchar(30). Keep the marker short. */
const SEED_CAR_MODEL = '__PW_SEED_844__';
/** `cars.chassis` is varchar(15). */
const SEED_CHASSIS = 'PWSEED844';
const SEED_OWNER_USERNAME = 'noowner';

// The spec reads this string from the output, so the two cannot differ.
const SEED_COMMENTS = "O'Brien & Co <é> \"test\" it´s";

$db = dbi();

// Delete all earlier marker rows, so a re-run is idempotent and --cleanup works.
$cleanupQueries = [
    ['DELETE FROM cars WHERE model = ?', [SEED_CAR_MODEL]],
    // Last, and by model, not car_id: the cars_insert and cars_delete triggers
    // each write a history row with this model, and the car id changes per run.
    ['DELETE FROM cars_hist WHERE model = ?', [SEED_CAR_MODEL]],
];
foreach ($cleanupQueries as [$cleanupSql, $cleanupParams]) {
    $db->query($cleanupSql, $cleanupParams);
    if ($db->error()) {
        fwrite(STDERR, "ERROR: Cleanup query failed ({$cleanupSql}): {$db->errorString()}\n");
        exit(1);
    }
}

if (in_array('--cleanup', $argv ?? [], true)) {
    echo json_encode(['cleaned' => true]) . "\n";
    exit(0);
}

$ownerRows = $db->query('SELECT id FROM users WHERE username = ?', [SEED_OWNER_USERNAME])->results(true);
if ($db->error()) {
    fwrite(STDERR, "ERROR: Owner lookup failed: {$db->errorString()}\n");
    exit(1);
}
if ($ownerRows === []) {
    fwrite(STDERR, "ERROR: User '" . SEED_OWNER_USERNAME . "' not found. The local database needs this user.\n");
    exit(1);
}
$ownerId = (int) $ownerRows[0]['id'];

$now = date('Y-m-d H:i:s');

$carRepository = new CarRepository($db);
$carInserted = $carRepository->insertCar([
    'user_id'            => $ownerId,
    'year'               => 1968,
    'model'              => SEED_CAR_MODEL,
    'series'             => 'S4',
    'variant'            => 'SE',
    'type'               => 'FHC',
    'chassis'            => SEED_CHASSIS,
    'comments'           => SEED_COMMENTS,
    'ctime'              => $now,
    'owner_last_updated' => $now,
]);
if (!$carInserted) {
    fwrite(STDERR, "ERROR: Failed to insert seed car: {$carRepository->errorString()}\n");
    exit(1);
}

$carId = (int) $carRepository->lastId();
if ($carId <= 0) {
    fwrite(STDERR, "ERROR: Seed car insert returned no car ID.\n");
    exit(1);
}

echo json_encode(['carId' => $carId, 'comments' => SEED_COMMENTS], JSON_UNESCAPED_UNICODE) . "\n";
