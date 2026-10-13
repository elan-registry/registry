<?php
declare(strict_types=1);

use ElanRegistry\Car\CarRepository;

/**
 * Standalone fixture script for tests/playwright/account-email-paused-notice.spec.js
 * (issue #1899, the email-paused notice on usersc/account.php).
 *
 * Seeds loginable owners, one car each. The deliverability flags are on the
 * owner profile (#2101). A flag shows only when its `_address` matches
 * users.email (#2356), so each seeded address is the user's own email:
 *
 *  - optout:      email_opted_out = 1 with email_opted_out_at.
 *  - spam:        email_blocked = 1, cause spam.
 *  - unsub:       email_blocked = 1, cause unsubscribed.
 *  - blocklist:   email_blocked = 1, cause blocklist (an admin block).
 *  - nocause:     email_blocked = 1 with a NULL cause (#2318).
 *  - bounced:     email_bounced = 1 with email_bounced_at.
 *  - blockedopt:  email_blocked = 1 (spam) and email_opted_out = 1.
 *  - clean:       a profile with no flags. The notice must be absent.
 *
 * Usage:
 *   php seed-email-paused-notice.php            seed (deletes earlier marker rows first)
 *   php seed-email-paused-notice.php --cleanup  delete the marker rows only
 *   php seed-email-paused-notice.php --set=<key>:<preset>
 *       change the flags of a seeded owner. Presets: bounced, blocked_spam,
 *       opted_out, clear. Prints {"set": true}. Used to test the re-show of
 *       a dismissed notice.
 *
 * Prints one JSON line to stdout (seed mode only), one entry per owner key
 * above: {"<key>": {"userId", "username", "password", "carId", "email"}, ...}.
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

/** @var array<string, array{username: string}> $owners */
$owners = [
    'optout' => ['username' => '__playwright_seed_email_notice_optout__'],
    'spam' => ['username' => '__playwright_seed_email_notice_spam__'],
    'unsub' => ['username' => '__playwright_seed_email_notice_unsub__'],
    'blocklist' => ['username' => '__playwright_seed_email_notice_blocklist__'],
    'nocause' => ['username' => '__playwright_seed_email_notice_nocause__'],
    'bounced' => ['username' => '__playwright_seed_email_notice_bounced__'],
    'blockedopt' => ['username' => '__playwright_seed_email_notice_blockedopt__'],
    'clean' => ['username' => '__playwright_seed_email_notice_clean__'],
];

$db = dbi();

// --set=<key>:<preset>: change one seeded owner's flags and stop.
foreach ($argv ?? [] as $arg) {
    if (!str_starts_with($arg, '--set=')) {
        continue;
    }
    [$setKey, $setPreset] = array_pad(explode(':', substr($arg, 6), 2), 2, '');
    if (!isset($owners[$setKey])) {
        fwrite(STDERR, "ERROR: Unknown owner key '{$setKey}'.\n");
        exit(1);
    }
    $setUserId = (int) ($db->query('SELECT id FROM users WHERE username = ?', [$owners[$setKey]['username']])->first()->id ?? 0);
    if ($setUserId <= 0) {
        fwrite(STDERR, "ERROR: Seeded owner '{$setKey}' not found. Run the seed first.\n");
        exit(1);
    }
    $setEmail = $owners[$setKey]['username'] . '@example.invalid';
    $cleared = [
        'email_opted_out' => 0, 'email_opted_out_at' => null,
        'email_blocked' => 0, 'email_blocked_address' => null, 'email_blocked_at' => null, 'email_blocked_cause' => null,
        'email_bounced' => 0, 'email_bounced_address' => null, 'email_bounced_at' => null,
    ];
    $presets = [
        'clear'        => [],
        'bounced'      => ['email_bounced' => 1, 'email_bounced_address' => $setEmail, 'email_bounced_at' => '2026-05-06 10:00:00'],
        'blocked_spam' => ['email_blocked' => 1, 'email_blocked_address' => $setEmail, 'email_blocked_at' => '2026-05-07 10:00:00', 'email_blocked_cause' => 'spam'],
        'opted_out'    => ['email_opted_out' => 1, 'email_opted_out_at' => '2026-05-08 10:00:00'],
    ];
    if (!isset($presets[$setPreset])) {
        fwrite(STDERR, "ERROR: Unknown preset '{$setPreset}'.\n");
        exit(1);
    }
    $fields = array_merge($cleared, $presets[$setPreset]);
    $assignments = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($fields)));
    $db->query("UPDATE profiles SET {$assignments} WHERE user_id = ?", array_merge(array_values($fields), [$setUserId]));
    if ($db->error()) {
        fwrite(STDERR, "ERROR: Failed to set flags: {$db->errorString()}\n");
        exit(1);
    }
    echo json_encode(['set' => true]) . "\n";
    exit(0);
}

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
 * @param array<string, int|string> $profileFlags Deliverability columns for the profile
 * @return array{0: int, 1: string, 2: int}
 */
function seedOwnerWithCar(\ElanRegistry\DatabaseInterface $db, CarRepository $carRepository, string $username, string $chassis, array $carFields, array $profileFlags = []): array
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

    seedProfile($db, $userId, $profileFlags);

    $defaults = [
        'user_id'            => $userId,
        'email'              => $username . '@example.invalid',
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

/**
 * Upsert the owner profile. profiles.user_id is UNIQUE.
 *
 * @param array<string, int|string> $flags Deliverability columns to set
 */
function seedProfile(\ElanRegistry\DatabaseInterface $db, int $userId, array $flags): void
{
    $columns = array_merge(['bio' => '', 'city' => '', 'state' => '', 'country' => ''], $flags);
    $names = implode(', ', array_keys($columns));
    $marks = implode(', ', array_fill(0, count($columns), '?'));
    $updates = implode(', ', array_map(static fn(string $c): string => "{$c} = VALUES({$c})", array_keys($columns)));
    $db->query(
        "INSERT INTO profiles (user_id, {$names}) VALUES (?, {$marks}) ON DUPLICATE KEY UPDATE {$updates}",
        array_merge([$userId], array_values($columns))
    );
    if ($db->error()) {
        fwrite(STDERR, "ERROR: Failed to upsert the profile for user {$userId}: {$db->errorString()}\n");
        exit(1);
    }
}

$output = [];
$e = static fn(string $username): string => $username . '@example.invalid';

[$userId, $password, $carId] = seedOwnerWithCar($db, $carRepository, $owners['optout']['username'], 'PWENOPT', [], ['email_opted_out' => 1, 'email_opted_out_at' => '2026-04-01 10:00:00']);
$output['optout'] = ['userId' => $userId, 'username' => $owners['optout']['username'], 'password' => $password, 'carId' => $carId, 'email' => $owners['optout']['username'] . '@example.invalid'];

[$userId, $password, $carId] = seedOwnerWithCar($db, $carRepository, $owners['spam']['username'], 'PWENSPAM', [], [
    'email_blocked'         => 1,
    'email_blocked_address' => $e($owners['spam']['username']),
    'email_blocked_at'      => '2026-04-02 11:00:00',
    'email_blocked_cause'   => 'spam',
]);
$output['spam'] = ['userId' => $userId, 'username' => $owners['spam']['username'], 'password' => $password, 'carId' => $carId, 'email' => $owners['spam']['username'] . '@example.invalid'];

[$userId, $password, $carId] = seedOwnerWithCar($db, $carRepository, $owners['unsub']['username'], 'PWENUNSUB', [], [
    'email_blocked'         => 1,
    'email_blocked_address' => $e($owners['unsub']['username']),
    'email_blocked_at'      => '2026-04-02 11:00:00',
    'email_blocked_cause'   => 'unsubscribed',
]);
$output['unsub'] = ['userId' => $userId, 'username' => $owners['unsub']['username'], 'password' => $password, 'carId' => $carId, 'email' => $owners['unsub']['username'] . '@example.invalid'];

[$userId, $password, $carId] = seedOwnerWithCar($db, $carRepository, $owners['blocklist']['username'], 'PWENBLOCK', [], [
    'email_blocked'         => 1,
    'email_blocked_address' => $e($owners['blocklist']['username']),
    'email_blocked_at'      => '2026-04-02 11:00:00',
    'email_blocked_cause'   => 'blocklist',
]);
$output['blocklist'] = ['userId' => $userId, 'username' => $owners['blocklist']['username'], 'password' => $password, 'carId' => $carId, 'email' => $owners['blocklist']['username'] . '@example.invalid'];

[$userId, $password, $carId] = seedOwnerWithCar($db, $carRepository, $owners['nocause']['username'], 'PWENNOCAU', [], [
    'email_blocked'         => 1,
    'email_blocked_address' => $e($owners['nocause']['username']),
    'email_blocked_at'      => '2026-04-02 11:00:00',
    // No email_blocked_cause key: the column stays NULL (no known cause).
]);
$output['nocause'] = ['userId' => $userId, 'username' => $owners['nocause']['username'], 'password' => $password, 'carId' => $carId, 'email' => $owners['nocause']['username'] . '@example.invalid'];

[$userId, $password, $carId] = seedOwnerWithCar($db, $carRepository, $owners['bounced']['username'], 'PWENBOUNC', [], [
    'email_bounced'         => 1,
    'email_bounced_address' => $e($owners['bounced']['username']),
    'email_bounced_at'      => '2026-04-03 10:00:00',
]);
$output['bounced'] = ['userId' => $userId, 'username' => $owners['bounced']['username'], 'password' => $password, 'carId' => $carId, 'email' => $owners['bounced']['username'] . '@example.invalid'];

[$userId, $password, $carId] = seedOwnerWithCar($db, $carRepository, $owners['blockedopt']['username'], 'PWENBLOPT', [], [
    'email_blocked'         => 1,
    'email_blocked_address' => $e($owners['blockedopt']['username']),
    'email_blocked_at'      => '2026-04-02 11:00:00',
    'email_blocked_cause'   => 'spam',
    'email_opted_out'       => 1,
    'email_opted_out_at'    => '2026-04-05 09:00:00',
]);
$output['blockedopt'] = ['userId' => $userId, 'username' => $owners['blockedopt']['username'], 'password' => $password, 'carId' => $carId, 'email' => $owners['blockedopt']['username'] . '@example.invalid'];

[$userId, $password, $carId] = seedOwnerWithCar($db, $carRepository, $owners['clean']['username'], 'PWENCLEAN', [], []);
$output['clean'] = ['userId' => $userId, 'username' => $owners['clean']['username'], 'password' => $password, 'carId' => $carId, 'email' => $owners['clean']['username'] . '@example.invalid'];

echo json_encode($output) . "\n";
