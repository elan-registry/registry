<?php
declare(strict_types=1);

use ElanRegistry\Car\CarRepository;

/**
 * Standalone fixture script for tests/playwright/car-badges.spec.js (issue #1900).
 *
 * Seeds one loginable owner with four cars. Each car shows a different mix of
 * status badges (CarBadges). The chassis marker starts with PWBDG, so the spec
 * can find the rows with the list search box.
 *
 *  - sold:     solddate set, stale data (owner_last_updated 2 years ago),
 *              old ctime. Shows SOLD only.
 *  - fresh:    no solddate, fresh data, old ctime. Not NEW, so it shows
 *              VERIFIED only. Its ctime is 2 years old, so it is neither
 *              inside the 90-day window nor one of the 5 newest cars
 *              (CarShowcaseService::getNewCarIds()).
 *  - new:      no solddate, fresh data, ctime now. Shows NEW only (NEW hides
 *              VERIFIED).
 *  - soldnew:  solddate set, fresh data, ctime now. Shows NEW and SOLD. On
 *              the account page (no NEW badge) it shows SOLD only, because
 *              Sold wins over Verified.
 *  - filler:   8 more sold cars (year 1967, chassis PWBDG + number). With the
 *              four cars above, a search for PWBDG gives 12 rows. The list has
 *              a minimum page length of 10, so the spec can open page 2.
 *
 * With --verified-row the script seeds a second, separate set for
 * tests/playwright/car-verified-row.spec.js (issue #1897). That set has its
 * own owner, model marker, and chassis marker (PWVRF), so the two specs can
 * seed and clean up in parallel without deleting each other's rows. All five
 * cars are not sold and have an old ctime. Each has a purchase date, so the
 * Ownership & History section does not depend on the Verified row:
 *
 *  - confirmed:  last_verified 30 days ago, owner_last_updated 2 years ago.
 *                The row says "Last confirmed <date>". It also has a vericode,
 *                for the vericode landing page.
 *  - current:    last_verified NULL, owner_last_updated 45 days ago. The row
 *                says "Current since <date>".
 *  - stale:      both dates 2 years ago. The row says "Not specified".
 *  - bounced:    fresh, email_bounced = 1 with a bounced address.
 *  - suppressed: fresh, email_suppressed = 1. It also has a vericode.
 *
 * Usage:
 *   php seed-car-badges.php                         seed (deletes earlier marker rows first)
 *   php seed-car-badges.php --cleanup               delete the marker rows only
 *   php seed-car-badges.php --verified-row          seed the Verified row set
 *   php seed-car-badges.php --verified-row --cleanup  delete the Verified row marker rows only
 *
 * Prints one JSON line to stdout (seed mode only):
 * {"userId", "username", "password", "cars": {"sold": id, "fresh": id, "new": id, "soldnew": id}, "fillerCount": 8}
 * With --verified-row: {"userId", "username", "password", "cars": {confirmed, current, stale, bounced, suppressed},
 * "dates": {"confirmed": "Y-m-d", "current": "Y-m-d"}, "vericodes": {"confirmed": code, "suppressed": code},
 * "bouncedAddress": address}
 * The owner has a random password that exists only in this output, so the
 * spec can log in as the owner with login() from auth-helper.js.
 *
 * Run it where the app can reach the database. Local Docker:
 * `docker compose exec -T -u www-data app php tests/playwright/local/fixtures/seed-car-badges.php`
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

// Set up the minimal $_SERVER state users/init.php needs to locate
// z_us_root.php and resolve $abs_us_root / $us_url_root, mirroring
// tests/bootstrap-integration.php's approach for the same problem.
$projectRoot = dirname(__DIR__, 4);
$_SERVER['DOCUMENT_ROOT'] = $projectRoot;
$_SERVER['PHP_SELF'] = '/tests/';

require_once $projectRoot . '/vendor/autoload.php';

// Load .env the same way users/init.php does, purely to check the
// environment guard BEFORE booting anything that could touch a database.
\Dotenv\Dotenv::createImmutable($projectRoot)->safeLoad();

if (($_ENV['US_ENVIRONMENT'] ?? getenv('US_ENVIRONMENT') ?: '') !== 'development') {
    fwrite(STDERR, "ERROR: seed-car-badges.php refused to run — US_ENVIRONMENT is not 'development'.\n");
    fwrite(STDERR, "This fixture writes directly to the configured database and must only run against\n");
    fwrite(STDERR, "the local Docker dev database, never test.elanregistry.org or elanregistry.org.\n");
    fwrite(STDERR, "Set US_ENVIRONMENT=development in your local .env/.env.local — see docs/development/ENVIRONMENT.md.\n");
    exit(1);
}

// Suppress non-fatal init.php errors (session/cookie/user bookkeeping that
// assumes a real web request) — see file header. DB::getInstance() below is
// unaffected: it is a self-contained singleton built from the 'mysql' config
// block init.php sets up before any of that other code runs.
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


$verifiedRowMode = in_array('--verified-row', $argv ?? [], true);

// Each mode has its own owner and markers, so cleanup in one mode never
// deletes rows of the other mode.
define('SEED_USERNAME', $verifiedRowMode ? '__playwright_seed_verified_row_owner__' : '__playwright_seed_car_badges_owner__');
define('SEED_EMAIL', $verifiedRowMode
    ? 'playwright-seed-verified-row-owner@example.invalid'
    : 'playwright-seed-car-badges-owner@example.invalid');

/** `cars.model` is varchar(30). `cars.chassis` is varchar(15). Keep markers short. */
define('SEED_CAR_MODEL', $verifiedRowMode ? '__PW_SEED_VERIFIED_ROW__' : '__PW_SEED_BADGES__');
define('SEED_CHASSIS_PREFIX', $verifiedRowMode ? 'PWVRF' : 'PWBDG');
const FILLER_COUNT = 8;
const SEED_BOUNCED_ADDRESS = 'pw-verified-row-bounced@example.invalid';

$db = dbi();

// Delete all earlier marker rows, so a re-run is idempotent and --cleanup works.
$cleanupQueries = [
    ['DELETE FROM cars WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [SEED_USERNAME]],
    ['DELETE FROM cars WHERE model = ?', [SEED_CAR_MODEL]],
    ['DELETE FROM user_permission_matches WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [SEED_USERNAME]],
    ['DELETE FROM users WHERE username = ?', [SEED_USERNAME]],
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

$password = 'Pw-' . bin2hex(random_bytes(12));

$userInserted = $db->insert('users', [
    'username'       => SEED_USERNAME,
    'password'       => password_hash($password, PASSWORD_BCRYPT),
    'email'          => SEED_EMAIL,
    'fname'          => 'Playwright',
    'lname'          => 'Badge Owner',
    'active'         => 1,
    'email_verified' => 1,
    // 0 means banned in UserSpice.
    'permissions'    => 1,
    'join_date'      => date('Y-m-d H:i:s'),
]);
if (!$userInserted) {
    fwrite(STDERR, "ERROR: Failed to insert seed owner user: {$db->errorString()}\n");
    exit(1);
}
$userId = (int) $db->lastId();
if ($userId <= 0) {
    fwrite(STDERR, "ERROR: Seed owner insert returned no user ID.\n");
    exit(1);
}

// Permission 1 is the standard "User" permission, needed to open account.php.
$permInserted = $db->insert('user_permission_matches', ['user_id' => $userId, 'permission_id' => 1]);
if (!$permInserted) {
    fwrite(STDERR, "ERROR: Failed to insert seed owner permission: {$db->errorString()}\n");
    exit(1);
}

$carRepository = new CarRepository($db);

$now = date('Y-m-d H:i:s');
$twoYearsAgo = date('Y-m-d H:i:s', strtotime('-2 years'));

$verificationManager = new \ElanRegistry\Car\CarVerificationManager($carRepository);
$vericodes = [];

if ($verifiedRowMode) {
    // Midday times keep the calendar date stable across a PHP/DB timezone offset.
    $thirtyDaysAgo = date('Y-m-d 12:00:00', strtotime('-30 days'));
    $fortyFiveDaysAgo = date('Y-m-d 12:00:00', strtotime('-45 days'));
    $vericodeSentAt = $now;

    /** @var array<string, array<string, mixed>> $specs */
    $specs = [
        'confirmed' => [
            'year' => 1970, 'chassis' => SEED_CHASSIS_PREFIX . 'C',
            'ctime' => $twoYearsAgo, 'purchasedate' => '1990-05-01',
            'last_verified' => $thirtyDaysAgo, 'owner_last_updated' => $twoYearsAgo,
        ],
        'current' => [
            'year' => 1971, 'chassis' => SEED_CHASSIS_PREFIX . 'U',
            'ctime' => $twoYearsAgo, 'purchasedate' => '1990-05-01',
            'last_verified' => null, 'owner_last_updated' => $fortyFiveDaysAgo,
        ],
        'stale' => [
            'year' => 1972, 'chassis' => SEED_CHASSIS_PREFIX . 'T',
            'ctime' => $twoYearsAgo, 'purchasedate' => '1990-05-01',
            'last_verified' => $twoYearsAgo, 'owner_last_updated' => $twoYearsAgo,
        ],
        'bounced' => [
            'year' => 1973, 'chassis' => SEED_CHASSIS_PREFIX . 'B',
            'ctime' => $twoYearsAgo, 'purchasedate' => '1990-05-01',
            'owner_last_updated' => $now,
            'email_bounced' => 1, 'email_bounced_address' => SEED_BOUNCED_ADDRESS,
        ],
        'suppressed' => [
            'year' => 1974, 'chassis' => SEED_CHASSIS_PREFIX . 'P',
            'ctime' => $twoYearsAgo, 'purchasedate' => '1990-05-01',
            'owner_last_updated' => $now,
            'email_suppressed' => 1,
        ],
    ];
} else {
    /** @var array<string, array<string, mixed>> $specs */
    $specs = [
        'sold' => [
            'year' => 1963, 'chassis' => SEED_CHASSIS_PREFIX . 'S',
            'ctime' => $twoYearsAgo, 'owner_last_updated' => $twoYearsAgo,
            'solddate' => date('Y-m-d', strtotime('-6 months')),
        ],
        'fresh' => [
            'year' => 1964, 'chassis' => SEED_CHASSIS_PREFIX . 'F',
            'ctime' => $twoYearsAgo, 'owner_last_updated' => $now,
            // The card shows its Ownership & History section for every car that is
            // not sold, because of the Verified row. This purchase date is not what
            // makes the section render. It gives the "Sold row is absent" test a
            // Purchase Date row to assert, so the test does not rely on the Verified row.
            'purchasedate' => '1990-05-01',
        ],
        'new' => [
            'year' => 1965, 'chassis' => SEED_CHASSIS_PREFIX . 'N',
            'ctime' => $now, 'owner_last_updated' => $now,
        ],
        'soldnew' => [
            'year' => 1966, 'chassis' => SEED_CHASSIS_PREFIX . 'SN',
            'ctime' => $now, 'owner_last_updated' => $now,
            'solddate' => date('Y-m-d', strtotime('-1 month')),
        ],
    ];

    for ($i = 1; $i <= FILLER_COUNT; $i++) {
        $specs['filler' . $i] = [
            'year' => 1967, 'chassis' => SEED_CHASSIS_PREFIX . sprintf('%02d', $i),
            'ctime' => $twoYearsAgo, 'owner_last_updated' => $twoYearsAgo,
            'solddate' => date('Y-m-d', strtotime('-6 months')),
        ];
    }
}

$carIds = [];
foreach ($specs as $name => $spec) {
    $inserted = $carRepository->insertCar(array_merge([
        'user_id' => $userId,
        'model'   => SEED_CAR_MODEL,
        'series'  => 'S3',
        'variant' => 'SE',
        'type'    => 'FHC',
        'color'   => 'Red',
        'email'   => SEED_EMAIL,
    ], $spec));
    if (!$inserted) {
        fwrite(STDERR, "ERROR: Failed to insert seed car '{$name}': {$carRepository->errorString()}\n");
        exit(1);
    }
    $carId = $carRepository->lastId();
    if ($carId <= 0) {
        fwrite(STDERR, "ERROR: Seed car '{$name}' insert returned no car ID.\n");
        exit(1);
    }
    $carIds[$name] = $carId;

    // The landing page finds a car by the hash of its vericode. The fixture
    // keeps the plaintext so the spec can open the link.
    if ($verifiedRowMode && in_array($name, ['confirmed', 'suppressed'], true)) {
        $plainCode = $verificationManager->generateVerificationCode();
        $carRepository->updateCar($carId, [
            'vericode'         => hashVericode($plainCode),
            'vericode_sent_at' => $vericodeSentAt,
        ]);
        $vericodes[$name] = $plainCode;
    }
}

if ($verifiedRowMode) {
    echo json_encode([
        'userId'         => $userId,
        'username'       => SEED_USERNAME,
        'password'       => $password,
        'cars'           => $carIds,
        'dates'          => [
            'confirmed' => substr($thirtyDaysAgo, 0, 10),
            'current'   => substr($fortyFiveDaysAgo, 0, 10),
        ],
        'vericodes'      => $vericodes,
        'bouncedAddress' => SEED_BOUNCED_ADDRESS,
    ]) . "\n";
    exit(0);
}

echo json_encode([
    'userId'   => $userId,
    'username' => SEED_USERNAME,
    'password' => $password,
    'cars'     => array_intersect_key($carIds, array_flip(['sold', 'fresh', 'new', 'soldnew'])),
    'fillerCount' => FILLER_COUNT,
]) . "\n";
