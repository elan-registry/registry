<?php
declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;

/**
 * Standalone fixture script for tests/playwright/admin-verification-send-tool.spec.js
 * (issue #1884).
 *
 * Modeled directly on seed-bounced-car.php (#1924) — same CLI-bootstrap
 * approach (real users/init.php boot via CarRepository/dbi(), not a
 * hand-rolled PDO connection), same idempotent delete-then-insert pattern,
 * same US_ENVIRONMENT=development guard so this can never run against a
 * deployed database.
 *
 * Seeds three owners, each with one car:
 *  The deliverability flags are on the owner profile (#2101), and an eligible
 *  car needs an owner with a profiles row. Each owner below has one.
 *
 *  - one ELIGIBLE car: solddate IS NULL, a profile with no flags,
 *    a non-empty email, and STALE per CarRepository::freshnessSql() — i.e.
 *    `owner_last_updated` set more than a year in the past AND `last_verified`
 *    NULL. This is the exact predicate findVerificationEligible() applies
 *    (usersc/classes/Car/CarRepository.php), reproduced here rather than
 *    re-derived, so the seeded row lands in the eligible preview/batch.
 *    Its chassis value embeds a `<script>` marker so the spec can assert
 *    HTML-escaping in the admin tab's preview table and batch report.
 *  - one BOUNCED car (profile email_bounced = 1): eligible on every other predicate,
 *    used to prove eligibilitySkipReason()/findVerificationEligible() exclude
 *    it, and to exercise the Mark Bounced/Clear Bounced buttons' `form=`
 *    wiring against a row that already carries a bounce.
 *  - one SUPPRESSED car (profile email_opted_out = 1): same purpose as the bounced
 *    car, but for the Clear Suppression action.
 *
 * Prints a single JSON line to stdout:
 * `{"eligibleCarId": <id>, "eligibleUserId": <id>, "eligibleChassis": <string>,
 *   "bouncedCarId": <id>, "bouncedUserId": <id>,
 *   "suppressedCarId": <id>, "suppressedUserId": <id>}`
 */

$projectRoot = dirname(__DIR__, 4);
$_SERVER['DOCUMENT_ROOT'] = $projectRoot;
$_SERVER['PHP_SELF'] = '/tests/';

require_once $projectRoot . '/vendor/autoload.php';

\Dotenv\Dotenv::createImmutable($projectRoot)->safeLoad();

if (($_ENV['US_ENVIRONMENT'] ?? getenv('US_ENVIRONMENT') ?: '') !== 'development') {
    fwrite(STDERR, "ERROR: seed-verification-send-tool.php refused to run — US_ENVIRONMENT is not 'development'.\n");
    fwrite(STDERR, "This fixture writes directly to the configured database and must only run against\n");
    fwrite(STDERR, "the local Docker dev database, never test.elanregistry.org or elanregistry.org.\n");
    fwrite(STDERR, "Set US_ENVIRONMENT=development in your local .env/.env.local — see docs/development/ENVIRONMENT.md.\n");
    exit(1);
}

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

// Fixed, unmistakable markers — never real member data, matched below for
// idempotent delete-then-insert.
const SEED_ELIGIBLE_USERNAME = '__playwright_seed_vst_eligible_owner__';
const SEED_ELIGIBLE_EMAIL = 'playwright-seed-vst-eligible-owner@example.invalid';
/** Kept to 23 chars — cars.model is varchar(30); longer is silently truncated. */
const SEED_ELIGIBLE_CAR_MODEL = '__PW_SEED_VST_ELIGIBLE__';
/**
 * Deliberately contains an angle-bracketed marker to regression-test escaping
 * in the eligible-preview table / batch report. chassis is varchar-typed with
 * plenty of headroom (see CarRepositoryTest fixtures using long chassis
 * strings) — not truncated at this length.
 */
const SEED_ELIGIBLE_CHASSIS = 'SEEDVST<script>xss</script>';

const SEED_BOUNCED_USERNAME = '__playwright_seed_vst_bounced_owner__';
const SEED_BOUNCED_EMAIL = 'playwright-seed-vst-bounced-owner@example.invalid';
const SEED_BOUNCED_CAR_MODEL = '__PW_SEED_VST_BOUNCED__';
const SEED_BOUNCED_ADDRESS = 'bounced-vst@example.invalid';

const SEED_SUPPRESSED_USERNAME = '__playwright_seed_vst_suppressed_owner__';
const SEED_SUPPRESSED_EMAIL = 'playwright-seed-vst-suppressed-owner@example.invalid';
const SEED_SUPPRESSED_CAR_MODEL = '__PW_SEED_VST_SUPPRESSED__';

$db = dbi();

// The fixture does not write batch_size. The preview uses the stored value.

// Idempotent cleanup, mirroring seed-bounced-car.php's pattern exactly.
foreach ([
    [SEED_ELIGIBLE_USERNAME, SEED_ELIGIBLE_CAR_MODEL],
    [SEED_BOUNCED_USERNAME, SEED_BOUNCED_CAR_MODEL],
    [SEED_SUPPRESSED_USERNAME, SEED_SUPPRESSED_CAR_MODEL],
] as [$username, $carModel]) {
    $db->query('DELETE FROM cars WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [$username]);
    $db->query('DELETE FROM profiles WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [$username]);
    $db->query('DELETE FROM users WHERE username = ?', [$username]);
    $db->query('DELETE FROM cars WHERE model = ?', [$carModel]);
}

$carRepository = new CarRepository($db);

/**
 * Insert one owner + one car, returning [userId, carId].
 *
 * @param array<string, mixed> $carOverrides
 * @param array<string, int|string> $profileFlags Deliverability columns for the owner profile
 * @return array{0: int, 1: int}
 */
function seedOwnerAndCar(
    DatabaseInterface $db,
    CarRepository $carRepository,
    string $username,
    string $email,
    array $carOverrides,
    array $profileFlags = []
): array {
    $userInserted = $db->insert('users', [
        'username'   => $username,
        'password'   => password_hash('not-a-real-password-' . bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'email'      => $email,
        'fname'      => 'Playwright',
        'lname'      => 'Seed Owner',
        'active'     => 1,
        'join_date'  => date('Y-m-d H:i:s'),
    ]);
    if (!$userInserted) {
        fwrite(STDERR, "ERROR: Failed to insert seed owner user ({$username}): {$db->errorString()}\n");
        exit(1);
    }
    $userId = (int) $db->lastId();

    // profiles.user_id is UNIQUE, so upsert.
    $columns = array_merge(['bio' => '', 'city' => '', 'state' => '', 'country' => ''], $profileFlags);
    $names = implode(', ', array_keys($columns));
    $marks = implode(', ', array_fill(0, count($columns), '?'));
    $updates = implode(', ', array_map(static fn(string $c): string => "{$c} = VALUES({$c})", array_keys($columns)));
    $db->query(
        "INSERT INTO profiles (user_id, {$names}) VALUES (?, {$marks}) ON DUPLICATE KEY UPDATE {$updates}",
        array_merge([$userId], array_values($columns))
    );
    if ($db->error()) {
        fwrite(STDERR, "ERROR: Failed to seed the profile for {$username}: {$db->errorString()}\n");
        exit(1);
    }

    $carFields = array_merge([
        'user_id'                => $userId,
        'year'                   => 1968,
        'series'                 => 'S4',
        'variant'                => 'SE',
        'type'                   => 'FHC',
        'color'                  => 'Red',
        'ctime'                  => date('Y-m-d H:i:s'),
        'email'                  => $email,
    ], $carOverrides);

    $carInserted = $carRepository->insertCar($carFields);
    if (!$carInserted) {
        fwrite(STDERR, "ERROR: Failed to insert seed car for {$username}: {$carRepository->errorString()}\n");
        exit(1);
    }

    return [$userId, $carRepository->lastId()];
}

// Eligible car: owner_last_updated > 1 year ago, last_verified NULL, no
// bounce/suppression, non-empty email, not sold. Matches
// CarRepository::freshnessSql()'s negation exactly (see that method's
// docblock for why COALESCE/GREATEST would be wrong here).
[$eligibleUserId, $eligibleCarId] = seedOwnerAndCar(
    $db,
    $carRepository,
    SEED_ELIGIBLE_USERNAME,
    SEED_ELIGIBLE_EMAIL,
    [
        'model'              => SEED_ELIGIBLE_CAR_MODEL,
        'chassis'            => SEED_ELIGIBLE_CHASSIS,
        'owner_last_updated' => date('Y-m-d H:i:s', strtotime('-2 years')),
        'last_verified'      => null,
    ]
);

// Bounced car: otherwise-eligible, but the profile has email_bounced = 1 — must be excluded
// from findVerificationEligible() and reported as skipped if force-submitted.
[$bouncedUserId, $bouncedCarId] = seedOwnerAndCar(
    $db,
    $carRepository,
    SEED_BOUNCED_USERNAME,
    SEED_BOUNCED_EMAIL,
    [
        'model'                  => SEED_BOUNCED_CAR_MODEL,
        'chassis'                => 'SEEDVSTB' . bin2hex(random_bytes(3)),
        'owner_last_updated'     => date('Y-m-d H:i:s', strtotime('-2 years')),
        'last_verified'          => null,
    ],
    [
        'email_bounced'         => 1,
        'email_bounced_address' => SEED_BOUNCED_ADDRESS,
        'email_bounced_at'      => date('Y-m-d H:i:s', strtotime('-1 day')),
    ]
);

// Suppressed car: otherwise-eligible, but the profile has email_opted_out = 1.
[$suppressedUserId, $suppressedCarId] = seedOwnerAndCar(
    $db,
    $carRepository,
    SEED_SUPPRESSED_USERNAME,
    SEED_SUPPRESSED_EMAIL,
    [
        'model'              => SEED_SUPPRESSED_CAR_MODEL,
        'chassis'            => 'SEEDVSTS' . bin2hex(random_bytes(3)),
        'owner_last_updated' => date('Y-m-d H:i:s', strtotime('-2 years')),
        'last_verified'      => null,
    ],
    [
        'email_opted_out'    => 1,
        'email_opted_out_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
    ]
);

echo json_encode([
    'eligibleCarId'    => $eligibleCarId,
    'eligibleUserId'   => $eligibleUserId,
    'eligibleChassis'  => SEED_ELIGIBLE_CHASSIS,
    'bouncedCarId'     => $bouncedCarId,
    'bouncedUserId'    => $bouncedUserId,
    'suppressedCarId'  => $suppressedCarId,
    'suppressedUserId' => $suppressedUserId,
]) . "\n";
