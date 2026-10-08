<?php
declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;

/**
 * Standalone fixture script for tests/playwright/admin-verification-dashboard.spec.js
 * (issue #1896).
 *
 * Modeled directly on seed-verification-send-tool.php (#1884) — same
 * CLI-bootstrap approach, same idempotent delete-then-insert pattern, same
 * US_ENVIRONMENT=development guard so this can never run against a deployed
 * database.
 *
 * Seeds, each under its own fixed username/model marker for idempotent
 * delete-then-insert:
 *
 *  - PENDING_USERNAME / PENDING car: vericode set, vericode_sent_at recent
 *    (inside CarVerificationEmailComposer::LINK_TTL_DAYS), last_verified
 *    NULL — lands in the Pending pill (see verificationPendingWhereSql()).
 *  - BOUNCED_USERNAME / BOUNCED car: email_bounced = 1, otherwise pending —
 *    lands in the Bounced pill.
 *  - SUPPRESSED_USERNAME / SUPPRESSED car: email_suppressed = 1 — lands in
 *    the Suppressed pill.
 *  - SOFT_BOUNCE_USERNAME / SOFT_BOUNCE car: pending (not email_bounced),
 *    with one `soft_bounce` er_email_events row after its `sent` row, so
 *    its Status chip reads "Soft bounce" and its Mark Bounced button must
 *    render `disabled` (one soft bounce does not confirm a dead address —
 *    see tab-verification.php's $queueAction logic).
 *  - PRECEDENCE_USERNAME / PRECEDENCE car: `sent`, then `delivered` at
 *    T+10m, then `hard_bounce` at T+0 (same cycle, earlier timestamp) — the
 *    exact case the integration test
 *    (tests/integration/database/CarRepositoryEmailEventPrecedenceTest.php
 *    ::testTerminalEventWinsOverALaterDeliveredInTheSameCycle) proves at
 *    the repository layer. The chassis carries a `<script>` marker so the
 *    Playwright spec can assert escaped rendering of this exact row via
 *    innerText(), closing DOCUMENTED GAP #3 in
 *    admin-verification-send-tool.spec.js — this car is email_bounced = 0
 *    (the Status chip, not email_bounced, drives "Bounced" here), so it
 *    renders in the "All" and "Pending" pills with a Bounced chip, which is
 *    exactly the case the plan's §8 callout documents ("a car's filter pill
 *    and status chip can disagree").
 *  - VERIFIED_USERNAME / VERIFIED car: one `VERIFIED` cars_hist row, for the
 *    Recent Activity list and the Verified pill/summary card.
 *  - SOLD_USERNAME / SOLD car: one `VERIFIED SOLD` cars_hist row, for the
 *    same two sections' Sold half.
 *
 * Every seeded car additionally satisfies the ELIGIBLE predicate is
 * deliberately NOT given to any of the above (each sets either
 * vericode_sent_at or a flag that would exclude it from
 * findVerificationEligible()) — this fixture is not about the Eligible
 * pill, which is already covered by seed-verification-send-tool.php. The
 * one exception is the precedence car, which is also stale/eligible by
 * construction; this is immaterial to what the precedence assertions check
 * (the Status chip, not pill membership).
 *
 * Prints a single JSON line to stdout with every seeded car/user id — see
 * the `echo json_encode(...)` call at the bottom for the exact shape.
 *
 * Supports one CLI argument: `--cleanup`, which deletes every row this
 * fixture seeds (and their er_email_events/cars_hist rows) and exits
 * without re-seeding or printing JSON. Used by the spec's `afterAll` so the
 * dashboard's summary counts are not left permanently inflated for other
 * suites/humans reading the same local dev database.
 */

$projectRoot = dirname(__DIR__, 4);
$_SERVER['DOCUMENT_ROOT'] = $projectRoot;
$_SERVER['PHP_SELF'] = '/tests/';

require_once $projectRoot . '/vendor/autoload.php';

\Dotenv\Dotenv::createImmutable($projectRoot)->safeLoad();

if (($_ENV['US_ENVIRONMENT'] ?? getenv('US_ENVIRONMENT') ?: '') !== 'development') {
    fwrite(STDERR, "ERROR: seed-verification-dashboard.php refused to run — US_ENVIRONMENT is not 'development'.\n");
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
// idempotent delete-then-insert and for --cleanup.
const SEED_PENDING_USERNAME = '__playwright_seed_vd_pending_owner__';
const SEED_PENDING_EMAIL = 'playwright-seed-vd-pending-owner@example.invalid';
const SEED_PENDING_CAR_MODEL = '__PW_SEED_VD_PENDING__';

const SEED_BOUNCED_USERNAME = '__playwright_seed_vd_bounced_owner__';
const SEED_BOUNCED_EMAIL = 'playwright-seed-vd-bounced-owner@example.invalid';
const SEED_BOUNCED_CAR_MODEL = '__PW_SEED_VD_BOUNCED__';
const SEED_BOUNCED_ADDRESS = 'bounced-vd@example.invalid';

const SEED_SUPPRESSED_USERNAME = '__playwright_seed_vd_suppressed_owner__';
const SEED_SUPPRESSED_EMAIL = 'playwright-seed-vd-suppressed-owner@example.invalid';
const SEED_SUPPRESSED_CAR_MODEL = '__PW_SEED_VD_SUPPRESSED__';

const SEED_SOFT_BOUNCE_USERNAME = '__playwright_seed_vd_softbounce_owner__';
const SEED_SOFT_BOUNCE_EMAIL = 'playwright-seed-vd-softbounce-owner@example.invalid';
const SEED_SOFT_BOUNCE_CAR_MODEL = '__PW_SEED_VD_SOFTBOUNCE__';

const SEED_PRECEDENCE_USERNAME = '__playwright_seed_vd_precedence_owner__';
const SEED_PRECEDENCE_EMAIL = 'playwright-seed-vd-precedence-owner@example.invalid';
const SEED_PRECEDENCE_CAR_MODEL = '__PW_SEED_VD_PRECEDENCE__';
/**
 * Chassis carries an angle-bracketed marker for the escaping assertion.
 *
 * NOT the full `SEEDVD<script>xss</script>` shape
 * seed-verification-send-tool.php uses: `cars.chassis` is `varchar(15)`
 * (database/migrations/20260709000000_add_elanregistry_baseline.php) and
 * MySQL under this project's non-strict-truncation connection (see
 * CarRepository::parseTimestamp()'s docblock on `sql_mode = ''`) silently
 * truncates an over-length INSERT rather than rejecting it — confirmed
 * empirically: the longer marker landed in the DB as `SEEDVD<script>x`,
 * which is not valid, parseable HTML and defeats the assertion it exists to
 * drive. This 15-character value is a complete, well-formed `<script>`
 * open tag plus one character of body and a truncated end-tag start — long
 * enough to prove the escaping question (does `<` reach the page as `<` or
 * as `&lt;`) without being silently clipped by the column itself.
 */
const SEED_PRECEDENCE_CHASSIS = '<script>x</scr>';

const SEED_VERIFIED_USERNAME = '__playwright_seed_vd_verified_owner__';
const SEED_VERIFIED_EMAIL = 'playwright-seed-vd-verified-owner@example.invalid';
const SEED_VERIFIED_CAR_MODEL = '__PW_SEED_VD_VERIFIED__';

const SEED_SOLD_USERNAME = '__playwright_seed_vd_sold_owner__';
const SEED_SOLD_EMAIL = 'playwright-seed-vd-sold-owner@example.invalid';
const SEED_SOLD_CAR_MODEL = '__PW_SEED_VD_SOLD__';

/** @var list<array{0: string, 1: string}> */
const SEED_USERNAME_MODEL_PAIRS = [
    [SEED_PENDING_USERNAME, SEED_PENDING_CAR_MODEL],
    [SEED_BOUNCED_USERNAME, SEED_BOUNCED_CAR_MODEL],
    [SEED_SUPPRESSED_USERNAME, SEED_SUPPRESSED_CAR_MODEL],
    [SEED_SOFT_BOUNCE_USERNAME, SEED_SOFT_BOUNCE_CAR_MODEL],
    [SEED_PRECEDENCE_USERNAME, SEED_PRECEDENCE_CAR_MODEL],
    [SEED_VERIFIED_USERNAME, SEED_VERIFIED_CAR_MODEL],
    [SEED_SOLD_USERNAME, SEED_SOLD_CAR_MODEL],
];

$db = dbi();

/**
 * Idempotent cleanup: delete every row this fixture seeds, including
 * dependent er_email_events/cars_hist rows (neither table has an FK/CASCADE
 * on cars.id — see DATABASE.md's "No Enforced Foreign Key Constraints" — so
 * each is deleted explicitly).
 */
function cleanupSeedRows(DatabaseInterface $db): void
{
    foreach (SEED_USERNAME_MODEL_PAIRS as [$username, $carModel]) {
        $db->query(
            'DELETE FROM er_email_events WHERE car_id IN (
                SELECT id FROM cars WHERE user_id IN (SELECT id FROM users WHERE username = ?) OR model = ?
            )',
            [$username, $carModel]
        );
        $db->query(
            'DELETE FROM cars_hist WHERE car_id IN (
                SELECT id FROM cars WHERE user_id IN (SELECT id FROM users WHERE username = ?) OR model = ?
            )',
            [$username, $carModel]
        );
        $db->query('DELETE FROM cars WHERE user_id IN (SELECT id FROM users WHERE username = ?)', [$username]);
        $db->query('DELETE FROM users WHERE username = ?', [$username]);
        $db->query('DELETE FROM cars WHERE model = ?', [$carModel]);
    }
}

if (in_array('--cleanup', array_slice($argv, 1), true)) {
    cleanupSeedRows($db);
    exit(0);
}

cleanupSeedRows($db);

$carRepository = new CarRepository($db);

/**
 * Insert one owner + one car, returning [userId, carId].
 *
 * @param array<string, mixed> $carOverrides
 * @return array{0: int, 1: int}
 */
function seedOwnerAndCar(
    DatabaseInterface $db,
    CarRepository $carRepository,
    string $username,
    string $email,
    array $carOverrides
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

/**
 * Insert one er_email_events row, mirroring
 * CarRepositoryEmailEventPrecedenceTest::insertRawEvent()'s raw INSERT —
 * CarRepository has no public helper that writes an arbitrary `event` (only
 * insertEmailEvent(), which upserts on a unique key keyed on
 * (car_id, email, event, brevo_message_id) — fine here since every row below
 * uses a distinct brevo_message_id per event anyway).
 */
function seedEmailEvent(
    DatabaseInterface $db,
    int $carId,
    string $email,
    string $event,
    string $brevoMessageId,
    string $occurredAt
): void {
    $db->query(
        'INSERT INTO er_email_events (car_id, email, event, reason, brevo_message_id, occurred_at)
         VALUES (?, ?, ?, NULL, ?, ?)',
        [$carId, $email, $event, $brevoMessageId, $occurredAt]
    );
    if ($db->error()) {
        fwrite(STDERR, "ERROR: Failed to seed er_email_events row (car={$carId} event={$event}): {$db->errorString()}\n");
        exit(1);
    }
}

/**
 * Insert one cars_hist row for the Recent Activity list, mirroring
 * app/admin/index.php's verifyHistoryFieldsForAdminAction() field shape
 * (same NOT NULL columns: operation, car_id, model, series, variant, type,
 * chassis — see the cars_hist CREATE TABLE in
 * database/migrations/20260709000000_add_elanregistry_baseline.php).
 */
function seedHistoryRow(
    CarRepository $carRepository,
    object $car,
    string $operation,
    string $timestamp
): void {
    $inserted = $carRepository->insertHistory([
        'operation'    => $operation,
        'car_id'       => (int) $car->id,
        'comments'     => 'Seeded by seed-verification-dashboard.php for Playwright',
        'ctime'        => $car->ctime ?? date('Y-m-d H:i:s'),
        'mtime'        => date('Y-m-d H:i:s'),
        'model'        => (string) $car->model,
        'series'       => (string) $car->series,
        'variant'      => (string) $car->variant,
        'year'         => $car->year ?? null,
        'type'         => (string) $car->type,
        'chassis'      => (string) $car->chassis,
        'color'        => $car->color ?? '',
        'user_id'      => (int) $car->user_id,
        'email'        => $car->email ?? '',
        'fname'        => 'Playwright',
        'lname'        => 'Seed Owner',
        'timestamp'    => $timestamp,
    ]);
    if (!$inserted) {
        fwrite(STDERR, "ERROR: Failed to seed cars_hist row (car={$car->id} operation={$operation}): {$carRepository->errorString()}\n");
        exit(1);
    }
}

$now = new \DateTimeImmutable();

// --- Pending: a live link, no response yet, not bounced/suppressed -------
[$pendingUserId, $pendingCarId] = seedOwnerAndCar(
    $db,
    $carRepository,
    SEED_PENDING_USERNAME,
    SEED_PENDING_EMAIL,
    [
        'model'              => SEED_PENDING_CAR_MODEL,
        'chassis'            => 'SEEDVDP' . bin2hex(random_bytes(3)),
        'vericode'           => hashVericode('seed-vd-pending-code'),
        'vericode_sent_at'   => $now->modify('-3 days')->format('Y-m-d H:i:s'),
        'last_verified'      => null,
        'owner_last_updated' => $now->modify('-3 days')->format('Y-m-d H:i:s'),
    ]
);

// --- Bounced ---------------------------------------------------------------
[$bouncedUserId, $bouncedCarId] = seedOwnerAndCar(
    $db,
    $carRepository,
    SEED_BOUNCED_USERNAME,
    SEED_BOUNCED_EMAIL,
    [
        'model'                  => SEED_BOUNCED_CAR_MODEL,
        'chassis'                => 'SEEDVDB' . bin2hex(random_bytes(3)),
        'vericode'               => hashVericode('seed-vd-bounced-code'),
        'vericode_sent_at'       => $now->modify('-3 days')->format('Y-m-d H:i:s'),
        'last_verified'          => null,
        'owner_last_updated'     => $now->modify('-3 days')->format('Y-m-d H:i:s'),
        'email_bounced'          => 1,
        'email_bounced_address'  => SEED_BOUNCED_ADDRESS,
    ]
);

// --- Suppressed --------------------------------------------------------------
[$suppressedUserId, $suppressedCarId] = seedOwnerAndCar(
    $db,
    $carRepository,
    SEED_SUPPRESSED_USERNAME,
    SEED_SUPPRESSED_EMAIL,
    [
        'model'              => SEED_SUPPRESSED_CAR_MODEL,
        'chassis'            => 'SEEDVDS' . bin2hex(random_bytes(3)),
        'vericode'           => hashVericode('seed-vd-suppressed-code'),
        'vericode_sent_at'   => $now->modify('-3 days')->format('Y-m-d H:i:s'),
        'last_verified'      => null,
        'owner_last_updated' => $now->modify('-3 days')->format('Y-m-d H:i:s'),
        'email_suppressed'   => 1,
    ]
);

// --- Soft bounce: Pending pill, "Soft bounce" chip, Mark Bounced disabled --
[$softBounceUserId, $softBounceCarId] = seedOwnerAndCar(
    $db,
    $carRepository,
    SEED_SOFT_BOUNCE_USERNAME,
    SEED_SOFT_BOUNCE_EMAIL,
    [
        'model'              => SEED_SOFT_BOUNCE_CAR_MODEL,
        'chassis'            => 'SEEDVDSB' . bin2hex(random_bytes(3)),
        'vericode'           => hashVericode('seed-vd-softbounce-code'),
        'vericode_sent_at'   => $now->modify('-3 days')->format('Y-m-d H:i:s'),
        'last_verified'      => null,
        'owner_last_updated' => $now->modify('-3 days')->format('Y-m-d H:i:s'),
    ]
);
seedEmailEvent(
    $db,
    $softBounceCarId,
    SEED_SOFT_BOUNCE_EMAIL,
    'sent',
    'seed-vd-softbounce-sent',
    $now->modify('-3 days')->format('Y-m-d H:i:s')
);
seedEmailEvent(
    $db,
    $softBounceCarId,
    SEED_SOFT_BOUNCE_EMAIL,
    'soft_bounce',
    'seed-vd-softbounce-bounce',
    $now->modify('-3 days +5 minutes')->format('Y-m-d H:i:s')
);

// --- Precedence: delivered at T+10m, hard_bounce at T+0, same cycle -------
// Reproduces CarRepositoryEmailEventPrecedenceTest
// ::testTerminalEventWinsOverALaterDeliveredInTheSameCycle at the UI layer:
// the Status chip must show Bounced, never Delivered, for this car.
[$precedenceUserId, $precedenceCarId] = seedOwnerAndCar(
    $db,
    $carRepository,
    SEED_PRECEDENCE_USERNAME,
    SEED_PRECEDENCE_EMAIL,
    [
        'model'              => SEED_PRECEDENCE_CAR_MODEL,
        'chassis'            => SEED_PRECEDENCE_CHASSIS,
        'vericode'           => hashVericode('seed-vd-precedence-code'),
        'vericode_sent_at'   => $now->modify('-3 days')->format('Y-m-d H:i:s'),
        'last_verified'      => null,
        'owner_last_updated' => $now->modify('-3 days')->format('Y-m-d H:i:s'),
    ]
);
seedEmailEvent(
    $db,
    $precedenceCarId,
    SEED_PRECEDENCE_EMAIL,
    'sent',
    'seed-vd-precedence-sent',
    $now->modify('-3 days')->format('Y-m-d H:i:s')
);
seedEmailEvent(
    $db,
    $precedenceCarId,
    SEED_PRECEDENCE_EMAIL,
    'hard_bounce',
    'seed-vd-precedence-bounce',
    $now->modify('-3 days')->format('Y-m-d H:i:s')
);
seedEmailEvent(
    $db,
    $precedenceCarId,
    SEED_PRECEDENCE_EMAIL,
    'delivered',
    'seed-vd-precedence-delivered',
    $now->modify('-3 days +10 minutes')->format('Y-m-d H:i:s')
);

// --- Verified: one VERIFIED cars_hist row, for Recent Activity -----------
[$verifiedUserId, $verifiedCarId] = seedOwnerAndCar(
    $db,
    $carRepository,
    SEED_VERIFIED_USERNAME,
    SEED_VERIFIED_EMAIL,
    [
        'model'              => SEED_VERIFIED_CAR_MODEL,
        'chassis'            => 'SEEDVDV' . bin2hex(random_bytes(3)),
        'last_verified'      => $now->modify('-1 day')->format('Y-m-d H:i:s'),
        'owner_last_updated' => $now->modify('-1 day')->format('Y-m-d H:i:s'),
    ]
);
$verifiedCar = $carRepository->findById($verifiedCarId);
if ($verifiedCar === null) {
    fwrite(STDERR, "ERROR: Freshly-inserted verified car {$verifiedCarId} could not be re-read.\n");
    exit(1);
}
seedHistoryRow($carRepository, $verifiedCar, 'VERIFIED', $now->modify('-1 day')->format('Y-m-d H:i:s'));

// --- Sold: one VERIFIED SOLD cars_hist row, for Recent Activity ----------
[$soldUserId, $soldCarId] = seedOwnerAndCar(
    $db,
    $carRepository,
    SEED_SOLD_USERNAME,
    SEED_SOLD_EMAIL,
    [
        'model'              => SEED_SOLD_CAR_MODEL,
        'chassis'            => 'SEEDVDO' . bin2hex(random_bytes(3)),
        'solddate'           => $now->modify('-1 day')->format('Y-m-d'),
        'last_verified'      => $now->modify('-1 day')->format('Y-m-d H:i:s'),
        'owner_last_updated' => $now->modify('-1 day')->format('Y-m-d H:i:s'),
    ]
);
$soldCar = $carRepository->findById($soldCarId);
if ($soldCar === null) {
    fwrite(STDERR, "ERROR: Freshly-inserted sold car {$soldCarId} could not be re-read.\n");
    exit(1);
}
seedHistoryRow($carRepository, $soldCar, 'VERIFIED SOLD', $now->modify('-1 day')->format('Y-m-d H:i:s'));

echo json_encode([
    'pendingCarId'       => $pendingCarId,
    'pendingUserId'      => $pendingUserId,
    'bouncedCarId'       => $bouncedCarId,
    'bouncedUserId'      => $bouncedUserId,
    'suppressedCarId'    => $suppressedCarId,
    'suppressedUserId'   => $suppressedUserId,
    'softBounceCarId'    => $softBounceCarId,
    'softBounceUserId'   => $softBounceUserId,
    'precedenceCarId'    => $precedenceCarId,
    'precedenceUserId'   => $precedenceUserId,
    'precedenceChassis'  => SEED_PRECEDENCE_CHASSIS,
    'verifiedCarId'      => $verifiedCarId,
    'verifiedUserId'     => $verifiedUserId,
    'soldCarId'          => $soldCarId,
    'soldUserId'         => $soldUserId,
]) . "\n";
