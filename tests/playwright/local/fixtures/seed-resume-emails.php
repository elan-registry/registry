<?php
declare(strict_types=1);

use ElanRegistry\Car\CarRepository;

/**
 * Standalone fixture script for tests/playwright/user-settings-resume-emails.spec.js
 * (issue #1895, the "Resume verification emails" control on Account Settings).
 *
 * Seeds three loginable owners. Each has a profiles row (usersc/user_settings.php
 * stops without one) and one car. The flags are on the profile (#2101):
 *
 *  - suppressed:   email_opted_out = 1. The page shows the #resume-emails
 *                  control, and Resume turns emails back on.
 *  - unsuppressed: no flags. The page does not show the control.
 *  - blocked:      email_blocked = 1 and email_opted_out = 1. The page shows
 *                  the control and the provider note. Resume removes the
 *                  opt-out only, so the message says emails stay paused.
 *
 * Usage:
 *   php seed-resume-emails.php            seed (deletes earlier marker rows first)
 *   php seed-resume-emails.php --cleanup  delete the marker rows only
 *
 * Prints one JSON line to stdout (seed mode only):
 * {"suppressed": {"userId", "username", "password", "carId"},
 *  "unsuppressed": {"userId", "username", "password", "carId"},
 *  "blocked": {"userId", "username", "password", "carId"}}
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
    fwrite(STDERR, "ERROR: seed-resume-emails.php refused to run — US_ENVIRONMENT is not 'development'.\n");
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
const SEED_CAR_MODEL = '__PW_SEED_RESUME_EMAILS__';

/** @var array<string, array{username: string, email: string, chassis: string, flags: array<string, int|string>}> $owners */
$owners = [
    'suppressed' => [
        'username'   => '__playwright_seed_resume_suppressed__',
        'email'      => 'playwright-seed-resume-suppressed@example.invalid',
        'chassis'    => 'PWRSMS',
        'flags'      => ['email_opted_out' => 1, 'email_opted_out_at' => '2026-04-01 10:00:00'],
    ],
    'unsuppressed' => [
        'username'   => '__playwright_seed_resume_clear__',
        'email'      => 'playwright-seed-resume-clear@example.invalid',
        'chassis'    => 'PWRSMC',
        'flags'      => [],
    ],
    'blocked' => [
        'username'   => '__playwright_seed_resume_blocked__',
        'email'      => 'playwright-seed-resume-blocked@example.invalid',
        'chassis'    => 'PWRSMB',
        'flags'      => [
            'email_opted_out'       => 1,
            'email_opted_out_at'    => '2026-04-01 10:00:00',
            'email_blocked'         => 1,
            'email_blocked_address' => 'playwright-seed-resume-blocked@example.invalid',
            'email_blocked_at'      => '2026-04-02 10:00:00',
            'email_blocked_cause'   => 'spam',
        ],
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
    $password = 'Pw-' . bin2hex(random_bytes(12));

    $userInserted = $db->insert('users', [
        'username'       => $owner['username'],
        'password'       => password_hash($password, PASSWORD_BCRYPT),
        'email'          => $owner['email'],
        'fname'          => 'Playwright',
        'lname'          => 'Resume Owner',
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

    // Permission 1 is the standard "User" permission, needed by securePage().
    if (!$db->insert('user_permission_matches', ['user_id' => $userId, 'permission_id' => 1])) {
        fwrite(STDERR, "ERROR: Failed to insert seed owner '{$key}' permission: {$db->errorString()}\n");
        exit(1);
    }

    // profiles.user_id is UNIQUE, so upsert.
    $profileColumns = array_merge([
        'bio'     => '',
        'city'    => 'Hethel',
        'state'   => 'Norfolk',
        'country' => 'United Kingdom',
    ], $owner['flags']);
    $profileNames = implode(', ', array_keys($profileColumns));
    $profileMarks = implode(', ', array_fill(0, count($profileColumns), '?'));
    $profileUpdates = implode(', ', array_map(
        static fn(string $column): string => "{$column} = VALUES({$column})",
        array_keys($profileColumns)
    ));
    $db->query(
        "INSERT INTO profiles (user_id, {$profileNames}) VALUES (?, {$profileMarks}) ON DUPLICATE KEY UPDATE {$profileUpdates}",
        array_merge([$userId], array_values($profileColumns))
    );
    $profileInserted = !$db->error();
    if (!$profileInserted) {
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

    $output[$key] = [
        'userId'   => $userId,
        'username' => $owner['username'],
        'password' => $password,
        'carId'    => $carRepository->lastId(),
    ];
}

echo json_encode($output) . "\n";
