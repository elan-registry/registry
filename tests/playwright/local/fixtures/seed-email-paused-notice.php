<?php
declare(strict_types=1);

use ElanRegistry\Car\CarRepository;

/**
 * Standalone fixture script for tests/playwright/account-email-paused-notice.spec.js
 * (issue #1899, the email-paused notice on usersc/account.php).
 *
 * Seeds six loginable owners, one car each (except the overflow owner, which
 * gets four), matching EmailNoticeBuilder's real cause/date sources
 * (confirmed against usersc/classes/Car/EmailNoticeBuilder.php, not the
 * original issue plan):
 *
 *  - optout:    cars.email_suppressed = 1, a real `cars_hist` 'EMAIL
 *               SUPPRESSED' row, and NO `er_email_events` row — the owner's
 *               own opt-out click (verify_car.php never writes an event row).
 *  - spam:      cars.email_suppressed = 1, a real `er_email_events` 'spam'
 *               row, and NO matching `cars_hist` row — a Brevo complaint (the
 *               webhook path never writes an 'EMAIL SUPPRESSED' history row).
 *  - bounced:   cars.email_bounced = 1 with a bounced address, following the
 *               same shape as seed-bounced-car.php's SEED_BOUNCED_ADDRESS
 *               pattern (own car, own address, no profile-level flag).
 *  - both:      one car, one address, BOTH email_suppressed = 1 (opt-out,
 *               with its cars_hist row) and email_bounced = 1 — the "same
 *               address, both problems, one merged block" case.
 *  - overflow:  four cars, four distinct suppressed addresses — triggers the
 *               MAX_ADDRESSES=3 cap and "and 1 more" overflow line.
 *  - clean:     one car, no flags — the notice must be entirely absent.
 *
 * Usage:
 *   php seed-email-paused-notice.php            seed (deletes earlier marker rows first)
 *   php seed-email-paused-notice.php --cleanup  delete the marker rows only
 *
 * Prints one JSON line to stdout (seed mode only), one entry per owner key
 * above: {"<key>": {"userId", "username", "password", "carId"}, ...}
 * (the "overflow" entry additionally carries "carIds": [...] for all four).
 * Each owner has a random password that exists only in this output, so the
 * spec can log in with login() from auth-helper.js.
 *
 * Run it where the app can reach the database. The spec runs it through
 * tests/playwright/fixture-runner.js (Docker app container).
 *
 * GUARD: this must NEVER run against a deployed environment. The script
 * refuses to run unless US_ENVIRONMENT=development (see seed-bounced-car.php
 * and docs/development/ENVIRONMENT.md).
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
    fwrite(STDERR, "ERROR: seed-email-paused-notice.php refused to run — US_ENVIRONMENT is not 'development'.\n");
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
const SEED_CAR_MODEL = '__PW_SEED_EMAIL_NOTICE__';

/** @var array<string, array{username: string, email: string}> $owners */
$owners = [
    'optout'   => ['username' => '__playwright_seed_email_notice_optout__', 'email' => 'playwright-seed-email-notice-optout@example.invalid'],
    'spam'     => ['username' => '__playwright_seed_email_notice_spam__', 'email' => 'playwright-seed-email-notice-spam@example.invalid'],
    'bounced'  => ['username' => '__playwright_seed_email_notice_bounced__', 'email' => 'playwright-seed-email-notice-bounced@example.invalid'],
    'both'     => ['username' => '__playwright_seed_email_notice_both__', 'email' => 'playwright-seed-email-notice-both@example.invalid'],
    'overflow' => ['username' => '__playwright_seed_email_notice_overflow__', 'email' => null],
    'clean'    => ['username' => '__playwright_seed_email_notice_clean__', 'email' => 'playwright-seed-email-notice-clean@example.invalid'],
];

$db = dbi();

// Delete all earlier marker rows, so a re-run is idempotent and --cleanup works.
$cleanupQueries = [];
foreach ($owners as $owner) {
    $cleanupQueries[] = ['DELETE FROM er_email_events WHERE car_id IN (SELECT id FROM cars WHERE user_id IN (SELECT id FROM users WHERE username = ?))', [$owner['username']]];
    $cleanupQueries[] = ['DELETE FROM cars_hist WHERE car_id IN (SELECT id FROM cars WHERE user_id IN (SELECT id FROM users WHERE username = ?))', [$owner['username']]];
    $cleanupQueries[] = ['DELETE FROM cars WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [$owner['username']]];
    $cleanupQueries[] = ['DELETE FROM profiles WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [$owner['username']]];
    $cleanupQueries[] = ['DELETE FROM user_permission_matches WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [$owner['username']]];
    $cleanupQueries[] = ['DELETE FROM users WHERE username = ?', [$owner['username']]];
}
$cleanupQueries[] = ['DELETE FROM cars_hist WHERE car_id IN (SELECT id FROM cars WHERE model = ?)', [SEED_CAR_MODEL]];
$cleanupQueries[] = ['DELETE FROM er_email_events WHERE car_id IN (SELECT id FROM cars WHERE model = ?)', [SEED_CAR_MODEL]];
$cleanupQueries[] = ['DELETE FROM cars WHERE model = ?', [SEED_CAR_MODEL]];
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

/**
 * Insert one user + profile + car. Returns [userId, password, carId].
 *
 * @param array<string, mixed> $carFields
 * @return array{0: int, 1: string, 2: int}
 */
function seedOwnerWithCar(\ElanRegistry\DatabaseInterface $db, CarRepository $carRepository, string $username, string $chassis, array $carFields): array
{
    $password = 'Pw-' . bin2hex(random_bytes(12));

    $userInserted = $db->insert('users', [
        'username'       => $username,
        'password'       => password_hash($password, PASSWORD_BCRYPT),
        'email'          => $username . '@example.invalid',
        'fname'          => 'Playwright',
        'lname'          => 'Email Notice Owner',
        'active'         => 1,
        'email_verified' => 1,
        // 0 means banned in UserSpice.
        'permissions'    => 1,
        'join_date'      => date('Y-m-d H:i:s'),
    ]);
    if (!$userInserted) {
        fwrite(STDERR, "ERROR: Failed to insert seed owner '{$username}': {$db->errorString()}\n");
        exit(1);
    }
    $userId = (int) $db->lastId();
    if ($userId <= 0) {
        fwrite(STDERR, "ERROR: Seed owner '{$username}' insert returned no user ID.\n");
        exit(1);
    }

    if (!$db->insert('user_permission_matches', ['user_id' => $userId, 'permission_id' => 1])) {
        fwrite(STDERR, "ERROR: Failed to insert seed owner '{$username}' permission: {$db->errorString()}\n");
        exit(1);
    }

    if (!$db->insert('profiles', ['user_id' => $userId, 'bio' => '', 'city' => '', 'state' => '', 'country' => ''])) {
        fwrite(STDERR, "ERROR: Failed to insert seed owner '{$username}' profile: {$db->errorString()}\n");
        exit(1);
    }

    $defaults = [
        'user_id'            => $userId,
        'year'               => 1968,
        'model'              => SEED_CAR_MODEL,
        'series'             => 'S4',
        'variant'            => 'SE',
        'type'               => 'FHC',
        'chassis'            => $chassis,
        'color'              => 'Red',
        'ctime'              => date('Y-m-d H:i:s'),
        'owner_last_updated' => date('Y-m-d H:i:s'),
    ];
    $carInserted = $carRepository->insertCar(array_merge($defaults, $carFields));
    if (!$carInserted) {
        fwrite(STDERR, "ERROR: Failed to insert seed car for '{$username}': {$carRepository->errorString()}\n");
        exit(1);
    }
    $carId = $carRepository->lastId();

    return [$userId, $password, $carId];
}

/** Minimal valid cars_hist row (NOT NULL columns per schema have no default). */
function seedHistRow(\ElanRegistry\DatabaseInterface $db, int $carId, string $operation, string $timestamp): void
{
    $inserted = $db->insert('cars_hist', [
        'operation' => $operation,
        'car_id'    => $carId,
        'model'     => SEED_CAR_MODEL,
        'series'    => 'S4',
        'variant'   => 'SE',
        'type'      => 'FHC',
        'chassis'   => 'SEEDHIST',
        'timestamp' => $timestamp,
    ]);
    if (!$inserted) {
        fwrite(STDERR, "ERROR: Failed to seed cars_hist row for car {$carId}: {$db->errorString()}\n");
        exit(1);
    }
}

$output = [];

// --- optout: cars.email_suppressed=1, a real EMAIL SUPPRESSED cars_hist row,
// no er_email_events row. --------------------------------------------------
[$userId, $password, $carId] = seedOwnerWithCar($db, $carRepository, $owners['optout']['username'], 'PWENOPT', [
    'email'             => $owners['optout']['email'],
    'email_suppressed'  => 1,
]);
seedHistRow($db, $carId, 'EMAIL SUPPRESSED', '2026-04-01 10:00:00');
$output['optout'] = ['userId' => $userId, 'username' => $owners['optout']['username'], 'password' => $password, 'carId' => $carId];

// --- spam: cars.email_suppressed=1, a real er_email_events 'spam' row, no
// matching cars_hist row. ---------------------------------------------------
[$userId, $password, $carId] = seedOwnerWithCar($db, $carRepository, $owners['spam']['username'], 'PWENSPAM', [
    'email'             => $owners['spam']['email'],
    'email_suppressed'  => 1,
]);
$carRepository->insertEmailEvent($carId, $owners['spam']['email'], 'spam', null, 'pw-seed-spam-msg', '2026-04-02 11:00:00');
$output['spam'] = ['userId' => $userId, 'username' => $owners['spam']['username'], 'password' => $password, 'carId' => $carId];

// --- bounced: cars.email_bounced=1 with a bounced address (seed-bounced-car.php pattern). ---
[$userId, $password, $carId] = seedOwnerWithCar($db, $carRepository, $owners['bounced']['username'], 'PWENBOUNC', [
    'email'                 => $owners['bounced']['email'],
    'email_bounced'         => 1,
    'email_bounced_address' => $owners['bounced']['email'],
]);
$output['bounced'] = ['userId' => $userId, 'username' => $owners['bounced']['username'], 'password' => $password, 'carId' => $carId];

// --- both: one car, one address, both suppressed (opt-out, with cars_hist
// row) and bounced. ----------------------------------------------------------
[$userId, $password, $carId] = seedOwnerWithCar($db, $carRepository, $owners['both']['username'], 'PWENBOTH', [
    'email'                 => $owners['both']['email'],
    'email_suppressed'      => 1,
    'email_bounced'         => 1,
    'email_bounced_address' => $owners['both']['email'],
]);
seedHistRow($db, $carId, 'EMAIL SUPPRESSED', '2026-04-03 09:00:00');
$output['both'] = ['userId' => $userId, 'username' => $owners['both']['username'], 'password' => $password, 'carId' => $carId];

// --- overflow: four cars, four distinct suppressed addresses (MAX_ADDRESSES=3). ---
$overflowPassword = 'Pw-' . bin2hex(random_bytes(12));
$overflowUserInserted = $db->insert('users', [
    'username'       => $owners['overflow']['username'],
    'password'       => password_hash($overflowPassword, PASSWORD_BCRYPT),
    'email'          => $owners['overflow']['username'] . '@example.invalid',
    'fname'          => 'Playwright',
    'lname'          => 'Email Notice Overflow Owner',
    'active'         => 1,
    'email_verified' => 1,
    'permissions'    => 1,
    'join_date'      => $now,
]);
if (!$overflowUserInserted) {
    fwrite(STDERR, "ERROR: Failed to insert seed overflow owner: {$db->errorString()}\n");
    exit(1);
}
$overflowUserId = (int) $db->lastId();
if (!$db->insert('user_permission_matches', ['user_id' => $overflowUserId, 'permission_id' => 1])) {
    fwrite(STDERR, "ERROR: Failed to insert seed overflow owner permission: {$db->errorString()}\n");
    exit(1);
}
if (!$db->insert('profiles', ['user_id' => $overflowUserId, 'bio' => '', 'city' => '', 'state' => '', 'country' => ''])) {
    fwrite(STDERR, "ERROR: Failed to insert seed overflow owner profile: {$db->errorString()}\n");
    exit(1);
}
$overflowCarIds = [];
for ($i = 1; $i <= 4; $i++) {
    $overflowEmail = "playwright-seed-email-notice-overflow-{$i}@example.invalid";
    $overflowCarInserted = $carRepository->insertCar([
        'user_id'            => $overflowUserId,
        'year'               => 1968,
        'model'              => SEED_CAR_MODEL,
        'series'             => 'S4',
        'variant'            => 'SE',
        'type'               => 'FHC',
        'chassis'            => 'PWENOVFL' . $i,
        'color'              => 'Red',
        'ctime'              => $now,
        'owner_last_updated' => $now,
        'email'              => $overflowEmail,
        'email_suppressed'   => 1,
    ]);
    if (!$overflowCarInserted) {
        fwrite(STDERR, "ERROR: Failed to insert seed overflow car {$i}: {$carRepository->errorString()}\n");
        exit(1);
    }
    $overflowCarIds[] = $carRepository->lastId();
}
$output['overflow'] = [
    'userId'   => $overflowUserId,
    'username' => $owners['overflow']['username'],
    'password' => $overflowPassword,
    'carId'    => $overflowCarIds[0],
    'carIds'   => $overflowCarIds,
];

// --- clean: no flags. The notice must be entirely absent. -------------------
[$userId, $password, $carId] = seedOwnerWithCar($db, $carRepository, $owners['clean']['username'], 'PWENCLEAN', [
    'email' => $owners['clean']['email'],
]);
$output['clean'] = ['userId' => $userId, 'username' => $owners['clean']['username'], 'password' => $password, 'carId' => $carId];

echo json_encode($output) . "\n";
