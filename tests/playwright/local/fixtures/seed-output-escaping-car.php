<?php
declare(strict_types=1);

use ElanRegistry\Car\CarRepository;

/**
 * Standalone fixture script for
 * tests/playwright/car-details-output-escaping.spec.js (issue #840 escaping).
 *
 * Seeds one owner and one car whose color, engine, and chassis hold HTML
 * payloads. The spec opens the car details page and checks that each payload
 * shows as text and creates no element. Each payload fits its column width:
 * color is varchar(50), engine and chassis are varchar(15).
 *
 * The owner website holds a quote-break payload (website is varchar(100)).
 * It has an https scheme, so OwnerView::websiteUrl() keeps it and the Owner
 * Information card renders it as a link. Thus only the href escaping stops
 * the attribute breakout. A javascript: payload would not test the escaping,
 * because the scheme filter removes the link before it renders.
 *
 * Usage:
 *   php seed-output-escaping-car.php            seed (deletes earlier marker rows first)
 *   php seed-output-escaping-car.php --cleanup  delete the marker rows only
 *
 * Prints one JSON line to stdout: in seed mode {"userId", "carId"}, with
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
    fwrite(STDERR, "ERROR: seed-output-escaping-car.php refused to run — US_ENVIRONMENT is not 'development'.\n");
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
const SEED_CAR_MODEL = '__PW_SEED_ESC__';
const SEED_USERNAME = '__playwright_seed_output_escaping__';
const SEED_EMAIL = 'playwright-seed-output-escaping@example.invalid';

// The spec asserts these exact strings, so keep the two lists the same.
const SEED_COLOR = '<script>alert(1)</script>';
const SEED_ENGINE = '<img onerror=1>';
const SEED_CHASSIS = '<i onerror=1>';
const SEED_WEBSITE = 'https://x.test/"onmouseover="alert(1)';

$db = dbi();

// Delete all earlier marker rows, so a re-run is idempotent and --cleanup works.
// The users AFTER DELETE trigger leaves a holding row per deleted user (#2419).
// Capture the ids first: the username lookup finds nothing after the delete.
$seedUserIds = array_map(
    'intval',
    array_column($db->query('SELECT id FROM users WHERE username = ?', [SEED_USERNAME])->results(true), 'id')
);
if ($db->error()) {
    fwrite(STDERR, "ERROR: Seed user lookup failed: {$db->errorString()}\n");
    exit(1);
}
$cleanupQueries = [
    ['DELETE FROM cars WHERE model = ?', [SEED_CAR_MODEL]],
    ['DELETE FROM cars WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [SEED_USERNAME]],
    ['DELETE FROM profiles WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [SEED_USERNAME]],
    ['DELETE FROM user_permission_matches WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [SEED_USERNAME]],
    ['DELETE FROM users WHERE username = ?', [SEED_USERNAME]],
    // Last, and by model, not car_id: the cars_delete trigger writes a history
    // row for each delete above.
    ['DELETE FROM cars_hist WHERE model = ?', [SEED_CAR_MODEL]],
];
foreach ($cleanupQueries as [$cleanupSql, $cleanupParams]) {
    $db->query($cleanupSql, $cleanupParams);
    if ($db->error()) {
        fwrite(STDERR, "ERROR: Cleanup query failed ({$cleanupSql}): {$db->errorString()}\n");
        exit(1);
    }
}
if ($seedUserIds !== []) {
    $holdingTable = $db->query(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        ['er_deleted_user_addresses']
    );
    if ($db->error()) {
        fwrite(STDERR, "ERROR: Lookup of er_deleted_user_addresses failed: {$db->errorString()}\n");
        exit(1);
    }
    if ($holdingTable->count() > 0) {
        $db->query(
            'DELETE FROM er_deleted_user_addresses WHERE user_id IN (' . implode(',', array_fill(0, count($seedUserIds), '?')) . ')',
            $seedUserIds
        );
        if ($db->error()) {
            fwrite(STDERR, "ERROR: Cleanup of er_deleted_user_addresses failed: {$db->errorString()}\n");
            exit(1);
        }
    }
}

if (in_array('--cleanup', $argv ?? [], true)) {
    echo json_encode(['cleaned' => true]) . "\n";
    exit(0);
}

$now = date('Y-m-d H:i:s');

$userInserted = $db->insert('users', [
    'username'       => SEED_USERNAME,
    // Nobody logs in as this owner, so the password is random and unused.
    'password'       => password_hash('Pw-' . bin2hex(random_bytes(12)), PASSWORD_BCRYPT),
    'email'          => SEED_EMAIL,
    'fname'          => 'Escaping',
    'lname'          => 'Seed',
    'active'         => 1,
    'email_verified' => 1,
    // Use 1 for a normal active user. In UserSpice, 0 means banned.
    'permissions'    => 1,
    'join_date'      => $now,
]);
if (!$userInserted) {
    fwrite(STDERR, "ERROR: Failed to insert seed owner: {$db->errorString()}\n");
    exit(1);
}
$userId = (int) $db->lastId();
if ($userId <= 0) {
    fwrite(STDERR, "ERROR: Seed owner insert returned no user ID.\n");
    exit(1);
}

if (!$db->insert('user_permission_matches', ['user_id' => $userId, 'permission_id' => 1])) {
    fwrite(STDERR, "ERROR: Failed to insert seed owner permission: {$db->errorString()}\n");
    exit(1);
}

// profiles.user_id is UNIQUE, so upsert. The website is stored raw, as the
// app stores it. Escaping occurs only at render.
$db->query(
    'INSERT INTO profiles (user_id, bio, city, state, country, website) VALUES (?, ?, ?, ?, ?, ?) '
    . 'ON DUPLICATE KEY UPDATE bio = VALUES(bio), city = VALUES(city), state = VALUES(state), '
    . 'country = VALUES(country), website = VALUES(website)',
    [$userId, '', 'Hethel', 'Norfolk', 'United Kingdom', SEED_WEBSITE]
);
if ($db->error()) {
    fwrite(STDERR, "ERROR: Failed to insert seed owner profile: {$db->errorString()}\n");
    exit(1);
}

$carRepository = new CarRepository($db);
$carInserted = $carRepository->insertCar([
    'user_id'            => $userId,
    'year'               => 1968,
    'model'              => SEED_CAR_MODEL,
    'series'             => 'S4',
    'variant'            => 'SE',
    'type'               => 'FHC',
    'chassis'            => SEED_CHASSIS,
    'color'              => SEED_COLOR,
    'engine'             => SEED_ENGINE,
    'ctime'              => $now,
    'owner_last_updated' => $now,
    'email'              => SEED_EMAIL,
    // The details page reads the website from the car row, not from profiles.
    // Owner::syncOwnerFieldsToCars() keeps the two the same in the app.
    'website'            => SEED_WEBSITE,
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

echo json_encode(['userId' => $userId, 'carId' => $carId]) . "\n";
