<?php
declare(strict_types=1);

use ElanRegistry\Car\CarRepository;

/**
 * Standalone fixture script for tests/playwright/admin-user-view-verification.spec.js
 * (issue #1924).
 *
 * Seeds two owners:
 *  - one with one car with `email_bounced = 1` (and a bounced address), so
 *    the spec can log in as admin, navigate to
 *    `users/admin.php?view=user&id=<userId>`, and assert the "Verification &
 *    Email" card renders the bounced state.
 *  - a second, unrelated owner with one clean car (no bounce/suppression
 *    flags set), so the spec can also assert the card's "No delivery
 *    problems recorded" empty state — proving the block doesn't just always
 *    render the warning path.
 *
 * Prints a single JSON line to stdout:
 * `{"bouncedCarId": <id>, "bouncedUserId": <id>, "cleanCarId": <id>, "cleanUserId": <id>}`
 * so the spec can read the seeded ids back after running this script via
 * execSync. (Older shape was `{"carId": <id>, "userId": <id>}` for the
 * bounced pair only — nothing else in the repo reads this script's output,
 * so no backward-compat aliasing is needed.)
 *
 * Unlike generate-button-row.php (which deliberately avoids DB access), this
 * fixture's entire job is a DB write, so it boots enough of the real
 * application to get a working DatabaseInterface + CarRepository:
 * users/init.php itself (not a hand-rolled PDO connection) is required so the
 * exact same DB::getInstance() singleton production code uses is exercised —
 * matching the pattern tests/bootstrap-integration.php already established
 * for getting a real DB connection from a CLI/non-web PHP process. init.php
 * also does session/cookie/user-login bookkeeping that has no meaning outside
 * a web request and can throw in a bare CLI context (e.g. helpers that assume
 * $_SERVER keys a browser request would set); those failures are caught and
 * ignored here for the same reason bootstrap-integration.php treats them as
 * non-fatal — the DB singleton it builds is unaffected by them.
 *
 * GUARD: this must NEVER run against a deployed environment (test./prod).
 * US_ENVIRONMENT=development is set only in the local, git-ignored .env /
 * .env.local — see docs/development/ENVIRONMENT.md's "Local Development
 * Environment Flag" section, which documents the same flag and states
 * "Never set this in a deployed .env." This script refuses to run unless
 * that flag is present, so it cannot seed fixture data into
 * test.elanregistry.org or elanregistry.org even if pointed at one by
 * mistake.
 */

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
    fwrite(STDERR, "ERROR: seed-bounced-car.php refused to run — US_ENVIRONMENT is not 'development'.\n");
    fwrite(STDERR, "This fixture writes directly to the configured database and must only run against\n");
    fwrite(STDERR, "a local dev database (MAMP), never test.elanregistry.org or elanregistry.org.\n");
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

/**
 * Fixed test user id marker. Not a real member — a distinctive, unmistakable
 * username/email pair (matched below for idempotent delete-then-insert) so
 * this fixture can never collide with or be confused for real member data.
 */
const SEED_USERNAME = '__playwright_seed_bounced_car_owner__';
const SEED_EMAIL = 'playwright-seed-bounced-car-owner@example.invalid';

/**
 * Distinctive marker written into the seeded car's `model` column.
 * Kept to 23 chars — `cars.model` is `varchar(30)`, and a marker any longer
 * gets silently truncated by MySQL, breaking the exact-match delete below.
 */
const SEED_CAR_MODEL = '__PW_SEED_BOUNCED_CAR__';

/** Bounced address recorded against the seeded car. */
const SEED_BOUNCED_ADDRESS = 'bounced@example.invalid';

/**
 * Second owner/car pair: a clean car with no bounce/suppression flags, used
 * to exercise the "No delivery problems recorded" empty state. Same marker
 * pattern as the bounced pair above, kept distinct so both can coexist and
 * be deleted independently.
 */
const SEED_CLEAN_USERNAME = '__playwright_seed_clean_car_owner__';
const SEED_CLEAN_EMAIL = 'playwright-seed-clean-car-owner@example.invalid';

/** Kept to 23 chars for the same `cars.model varchar(30)` reason as above. */
const SEED_CLEAN_CAR_MODEL = '__PW_SEED_CLEAN_CAR__';

// dbi() (usersc/includes/custom_functions.php) wraps DB::getInstance() in the
// DbAdapter that satisfies DatabaseInterface — the same production idiom
// every other DatabaseInterface-typed collaborator uses (e.g.
// app/owner/cars/index.php's `new CarRepository(dbi())`). \DB itself does not
// implement DatabaseInterface, so CarRepository cannot take it directly.
$db = dbi();

// Delete ALL prior rows matching each marker first, so re-runs are idempotent
// with no separate teardown step — deliberately not scoped to a single
// ->first() row, since more than one marker row can accumulate (e.g. two
// runs racing, or a previous run failing after insert but before a clean
// exit) and a single-row delete would silently leave orphans behind.
$db->query('DELETE FROM cars WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [SEED_USERNAME]);
$db->query('DELETE FROM users WHERE username = ?', [SEED_USERNAME]);
// Belt-and-suspenders: also remove any car left behind by model marker alone,
// in case a previous run's owner row was already removed by other means.
$db->query('DELETE FROM cars WHERE model = ?', [SEED_CAR_MODEL]);

$db->query('DELETE FROM cars WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [SEED_CLEAN_USERNAME]);
$db->query('DELETE FROM users WHERE username = ?', [SEED_CLEAN_USERNAME]);
$db->query('DELETE FROM cars WHERE model = ?', [SEED_CLEAN_CAR_MODEL]);

$userInserted = $db->insert('users', [
    'username'   => SEED_USERNAME,
    'password'   => password_hash('not-a-real-password-' . bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
    'email'      => SEED_EMAIL,
    'fname'      => 'Playwright',
    'lname'      => 'Seed Owner',
    'active'     => 1,
    'join_date'  => date('Y-m-d H:i:s'),
]);
if (!$userInserted) {
    fwrite(STDERR, "ERROR: Failed to insert seed owner user: {$db->errorString()}\n");
    exit(1);
}
$userId = (int) $db->lastId();

$carRepository = new CarRepository($db);

$carInserted = $carRepository->insertCar([
    'user_id'                => $userId,
    'year'                   => 1968,
    'model'                  => SEED_CAR_MODEL,
    'series'                 => 'S4',
    'variant'                => 'SE',
    'type'                   => 'FHC',
    'chassis'                => 'SEED' . substr((string) $userId, -6),
    'color'                  => 'Red',
    'ctime'                  => date('Y-m-d H:i:s'),
    'owner_last_updated'     => date('Y-m-d H:i:s'),
    'email'                  => SEED_EMAIL,
    'email_bounced'          => 1,
    'email_bounced_address'  => SEED_BOUNCED_ADDRESS,
]);
if (!$carInserted) {
    fwrite(STDERR, "ERROR: Failed to insert seed bounced car: {$carRepository->errorString()}\n");
    exit(1);
}
$carId = $carRepository->lastId();

$cleanUserInserted = $db->insert('users', [
    'username'   => SEED_CLEAN_USERNAME,
    'password'   => password_hash('not-a-real-password-' . bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
    'email'      => SEED_CLEAN_EMAIL,
    'fname'      => 'Playwright',
    'lname'      => 'Seed Clean Owner',
    'active'     => 1,
    'join_date'  => date('Y-m-d H:i:s'),
]);
if (!$cleanUserInserted) {
    fwrite(STDERR, "ERROR: Failed to insert seed clean owner user: {$db->errorString()}\n");
    exit(1);
}
$cleanUserId = (int) $db->lastId();

$cleanCarInserted = $carRepository->insertCar([
    'user_id'                => $cleanUserId,
    'year'                   => 1968,
    'model'                  => SEED_CLEAN_CAR_MODEL,
    'series'                 => 'S4',
    'variant'                => 'SE',
    'type'                   => 'FHC',
    'chassis'                => 'SEEDCLEAN' . substr((string) $cleanUserId, -6),
    'color'                  => 'Blue',
    'ctime'                  => date('Y-m-d H:i:s'),
    'owner_last_updated'     => date('Y-m-d H:i:s'),
    'email'                  => SEED_CLEAN_EMAIL,
]);
if (!$cleanCarInserted) {
    fwrite(STDERR, "ERROR: Failed to insert seed clean car: {$carRepository->errorString()}\n");
    exit(1);
}
$cleanCarId = $carRepository->lastId();

echo json_encode([
    'bouncedCarId'  => $carId,
    'bouncedUserId' => $userId,
    'cleanCarId'    => $cleanCarId,
    'cleanUserId'   => $cleanUserId,
]) . "\n";
