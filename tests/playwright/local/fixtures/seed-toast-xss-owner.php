<?php
declare(strict_types=1);

use ElanRegistry\Car\CarRepository;

/**
 * Standalone fixture script for tests/playwright/security/toast-xss.spec.js
 * (issue #2439, HTML injection through a double entity decode in toasts).
 *
 * Seeds two owners. Each has a profiles row and one car. The last name holds
 * text that looks like HTML entities. Admin contact (process-admin-contact.php)
 * puts the owner name in a success toast, so the toast renders the name.
 *
 *  - a: lname is `&lt;img id=xss2439d src=x&gt;` (one level of entities)
 *  - b: lname is `&amp;lt;img id=xss2439e src=x&amp;gt;` (two levels)
 *
 * Usage:
 *   php seed-toast-xss-owner.php            seed (deletes earlier marker rows first)
 *   php seed-toast-xss-owner.php --cleanup  delete the marker rows only
 *
 * Prints one JSON line to stdout: in seed mode
 * {"a": {"userId", "carId"}, "b": {"userId", "carId"}}, with --cleanup
 * {"cleaned": true}
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
    fwrite(STDERR, "ERROR: seed-toast-xss-owner.php refused to run — US_ENVIRONMENT is not 'development'.\n");
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

/** `cars.model` is varchar(30). `cars.chassis` is varchar(15). Keep markers short. */
const SEED_CAR_MODEL = '__PW_SEED_TOAST_XSS__';

/** @var array<string, array{username: string, email: string, chassis: string, lname: string}> $owners */
$owners = [
    'a' => [
        'username' => '__playwright_seed_toast_xss_a__',
        'email'    => 'playwright-seed-toast-xss-a@example.invalid',
        'chassis'  => 'PWTXSA',
        'lname'    => '&lt;img id=xss2439d src=x&gt;',
    ],
    'b' => [
        'username' => '__playwright_seed_toast_xss_b__',
        'email'    => 'playwright-seed-toast-xss-b@example.invalid',
        'chassis'  => 'PWTXSB',
        'lname'    => '&amp;lt;img id=xss2439e src=x&amp;gt;',
    ],
];

$db = dbi();

// Delete all earlier marker rows, so a re-run is idempotent and --cleanup works.
$cleanupQueries = [['DELETE FROM cars WHERE model = ?', [SEED_CAR_MODEL]]];
foreach ($owners as $owner) {
    $cleanupQueries[] = ['DELETE FROM cars WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [$owner['username']]];
    $cleanupQueries[] = ['DELETE FROM profiles WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [$owner['username']]];
    $cleanupQueries[] = ['DELETE FROM user_permission_matches WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [$owner['username']]];
    $cleanupQueries[] = ['DELETE FROM users WHERE username = ?', [$owner['username']]];
}
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

$carRepository = new CarRepository($db);
$now = date('Y-m-d H:i:s');
$output = [];

foreach ($owners as $key => $owner) {
    $userInserted = $db->insert('users', [
        'username'       => $owner['username'],
        // Nobody logs in as this owner, so the password is random and unused.
        'password'       => password_hash('Pw-' . bin2hex(random_bytes(12)), PASSWORD_BCRYPT),
        'email'          => $owner['email'],
        'fname'          => 'Toast',
        'lname'          => $owner['lname'],
        'active'         => 1,
        'email_verified' => 1,
        // 0 means banned in UserSpice.
        'permissions'    => 1,
        'join_date'      => $now,
    ]);
    if (!$userInserted) {
        fwrite(STDERR, "ERROR: Failed to insert seed owner '{$key}': {$db->errorString()}\n");
        exit(1);
    }
    $userId = (int) $db->lastId();
    if ($userId <= 0) {
        fwrite(STDERR, "ERROR: Seed owner '{$key}' insert returned no user ID.\n");
        exit(1);
    }

    if (!$db->insert('user_permission_matches', ['user_id' => $userId, 'permission_id' => 1])) {
        fwrite(STDERR, "ERROR: Failed to insert seed owner '{$key}' permission: {$db->errorString()}\n");
        exit(1);
    }

    // profiles.user_id is UNIQUE, so upsert.
    $db->query(
        'INSERT INTO profiles (user_id, bio, city, state, country) VALUES (?, ?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE bio = VALUES(bio), city = VALUES(city), state = VALUES(state), country = VALUES(country)',
        [$userId, '', 'Hethel', 'Norfolk', 'United Kingdom']
    );
    if ($db->error()) {
        fwrite(STDERR, "ERROR: Failed to insert seed owner '{$key}' profile: {$db->errorString()}\n");
        exit(1);
    }

    $carInserted = $carRepository->insertCar([
        'user_id'            => $userId,
        'year'               => 1968,
        'model'              => SEED_CAR_MODEL,
        'series'             => 'S4',
        'variant'            => 'SE',
        'type'               => 'FHC',
        'chassis'            => $owner['chassis'],
        'color'              => 'Red',
        'ctime'              => $now,
        'owner_last_updated' => $now,
        'email'              => $owner['email'],
    ]);
    if (!$carInserted) {
        fwrite(STDERR, "ERROR: Failed to insert seed car for '{$key}': {$carRepository->errorString()}\n");
        exit(1);
    }

    $carId = (int) $carRepository->lastId();
    if ($carId <= 0) {
        fwrite(STDERR, "ERROR: No car id returned for '{$key}'\n");
        exit(1);
    }

    $output[$key] = [
        'userId' => $userId,
        'carId'  => $carId,
    ];
}

echo json_encode($output) . "\n";
