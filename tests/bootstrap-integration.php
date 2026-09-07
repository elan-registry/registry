<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap File for Integration Tests
 *
 * Sets up the testing environment by loading the REAL UserSpice framework
 * and connecting to the actual database.
 * NO MOCKS are used (except when creating test fixtures).
 * Use this for: tests/integration/*
 *
 * For unit tests, use: tests/bootstrap-unit.php
 */

// Mark as integration test suite - prevents mock loading
define('INTEGRATION_TEST_SUITE', true);
define('TESTING', true);

// Set up basic paths
$projectRoot = dirname(__DIR__);
$_SERVER['DOCUMENT_ROOT'] = $projectRoot;
$_SERVER['PHP_SELF'] = '/tests/';

// Set up testing environment
define('TESTING_ROOT', $projectRoot);

// Load Composer autoloader for project classes FIRST (before UserSpice)
require_once $projectRoot . '/vendor/autoload.php';

// Load UserSpice framework for real database testing and authentication
$initPath = $projectRoot . '/users/init.php';
if (!file_exists($initPath)) {
    fwrite(STDERR, "ERROR: UserSpice initialization file not found at: {$initPath}\n");
    fwrite(STDERR, "Integration tests require UserSpice framework to be installed.\n");
    fwrite(STDERR, "To skip integration tests, use: composer test:unit\n");
    exit(1);
}

// Integration tests must run against a DEDICATED test schema, never the dev
// database. We require .env.test.local (pointing at that schema) and refuse to
// fall back to .env.local/.env, because a fallback risks running destructive
// integration tests against the live development database.
$envTestLocal = $projectRoot . '/.env.test.local';
if (!file_exists($envTestLocal)) {
    fwrite(STDERR, "ERROR: Integration test environment file not found at: {$envTestLocal}\n");
    fwrite(STDERR, "Integration tests require .env.test.local pointing at a dedicated test schema (e.g. elanregi_spice_test).\n");
    fwrite(STDERR, "This bootstrap intentionally does NOT fall back to .env.local or .env, because that risks\n");
    fwrite(STDERR, "running destructive integration tests against the development database.\n");
    fwrite(STDERR, "To set up: cp .env.test.local.sample .env.test.local, then fill in DB_PASS.\n");
    fwrite(STDERR, "See docs/development/ENVIRONMENT.md for details.\n");
    exit(1);
}

// The Dotenv class is available because vendor/autoload.php was loaded above.
// createMutable() allows init.php's createImmutable() to read our test values from $_ENV.
try {
    \Dotenv\Dotenv::createMutable($projectRoot, '.env.test.local')->load();
    fwrite(STDERR, "NOTE: Loaded test environment from .env.test.local\n");
} catch (\Dotenv\Exception\ExceptionInterface $e) {
    // A load failure here is fatal: we have no reliable way to know which
    // database we would connect to, so proceeding at all would be unsafe.
    fwrite(STDERR, "ERROR: Could not load .env.test.local: {$e->getMessage()}\n");
    fwrite(STDERR, "Integration tests cannot run without a valid test environment. Aborting.\n");
    exit(1);
}

// Defense-in-depth: refuse to proceed if the test environment points at the dev database.
// Case-folded and trimmed because MAMP's MySQL runs with lower_case_table_names=2 on
// macOS's case-insensitive filesystem, so ELANREGI_SPICE and elanregi_spice are the same
// physical database — a naive === comparison would miss a typo'd-case DB_NAME.
$configuredDbName = strtolower(trim($_ENV['DB_NAME'] ?? ''));
if ($configuredDbName === 'elanregi_spice') {
    fwrite(STDERR, "ERROR: .env.test.local is pointed at the development database (elanregi_spice).\n");
    fwrite(STDERR, "Integration tests must use a dedicated test schema (e.g. elanregi_spice_test).\n");
    fwrite(STDERR, "Update DB_NAME in .env.test.local before running integration tests. Aborting.\n");
    exit(1);
}

// Fail loudly, before anything else, if the test database is unreachable (#1591).
//
// Without this check, an unreachable DB is caught only inside the try/catch around
// users/init.php below — but the actual connection failure happens inside upstream
// users/classes/DB.php's constructor, which calls die() on a PDOException instead of
// throwing. die() is not a \Throwable, so it is never caught by that try/catch; it
// terminates the PHP process immediately, before the output buffer started below is
// ever flushed, and before PHPUnit's runner (or IntegrationTestCase::requireDatabase()'s
// own honest per-test skip) ever gets control. The net effect without this guard: the
// whole process silently exits 0 with no output — indistinguishable from "all tests
// passed." A short, bounded probe here, using the exact same DSN shape DB.php itself
// builds, catches that case before it can ever reach DB.php's die().
//
// Resolve host/user/pass/name via a throwaway Dotenv::createImmutable()->safeLoad()
// of the root .env first — mirroring exactly what users/init.php does at its own
// Dotenv call below — rather than reading raw $_ENV directly. Without this, any
// DB_* key that .env.test.local omits (relying on root .env to backfill it, same as
// init.php allows) would read as empty here, produce a bogus connection failure, and
// abort a configuration that init.php itself would have connected successfully.
// safeLoad() only fills in keys $_ENV doesn't already have, so this cannot override
// anything .env.test.local already set above.
\Dotenv\Dotenv::createImmutable($projectRoot)->safeLoad();
$probeHost = $_ENV['DB_HOST'] ?? '(not set)';
$probeName = $_ENV['DB_NAME'] ?? '(not set)';
try {
    new PDO(
        'mysql:host=' . ($_ENV['DB_HOST'] ?? '') . ';dbname=' . ($_ENV['DB_NAME'] ?? '') . ';charset=utf8mb4',
        $_ENV['DB_USER'] ?? '',
        $_ENV['DB_PASS'] ?? '',
        [PDO::ATTR_TIMEOUT => 3]
    );
} catch (\PDOException $e) {
    abortBootstrap(
        "ERROR: Could not connect to the test database at {$probeHost}"
            . " (database: {$probeName}).",
        "PDO error: {$e->getMessage()}",
        "Check that MAMP/MySQL is running and .env.test.local's DB_HOST/DB_USER/",
        "DB_PASS/DB_NAME are correct. Aborting rather than letting the connection",
        "attempt fall through to users/classes/DB.php's die(), which would exit 0",
        "with no output and look like a passing test run."
    );
}

// Suppress UserSpice initialization errors (especially database connection errors)
// Integration tests will handle the missing database gracefully via IntegrationTestCase
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    // Suppress all errors during bootstrap - integration tests will handle gracefully
    return true;
});

// Start output buffering to catch any die() output messages
ob_start();

// Intentionally non-fatal: users/init.php performs framework setup that can throw before
// the DB connection this bootstrap actually needs is established (e.g. plugin/session
// init that assumes a fully-configured environment). IntegrationTestCase::setUp() has its
// own DB connectivity check and skips gracefully via requireDatabase() if the database
// this init failure may have affected genuinely isn't reachable — that's the real signal,
// not this early framework noise. A hard abort here would be too coarse: it can't tell
// framework startup quirks apart from failures that actually block testing.
try {
    require_once $initPath;
} catch (Throwable $e) {
    // Capture UserSpice initialization errors
    fwrite(STDERR, "NOTE: UserSpice initialization error: {$e->getMessage()}\n");
}

// Get any output from die() and clear it
$output = ob_get_clean();
if (!empty(trim($output))) {
    fwrite(STDERR, "NOTE: {$output}\n");
}

restore_error_handler();

// If users/init.php threw before reaching usersc/includes/loader.php, the
// application constants (ELAN_IMAGE_DIR, BACKUP_*, ...) that Car and
// BackupManager read are still undefined. Load the real config.php rather than
// mirroring its values (as tests/bootstrap-unit.php must, having no framework),
// so there is nothing to drift. The guard is what emits the NOTE and skips the
// defaults below when init.php did load config.php; the require_once itself
// would already be a no-op in that case (same realpath). ELAN_IMAGE_DIR is the
// sentinel because it is the constant #1931 reported missing.
//
// init.php fails here on every run, not occasionally: users/helpers/us_helpers.php's
// ipCheckBan() reads `global $db`, but PHPUnit includes this bootstrap from a
// function scope, so the $db init.php just built is a local — ipCheckBan() gets
// null and throws, before loader.php.
if (!defined('ELAN_IMAGE_DIR')) {
    fwrite(STDERR, "NOTE: users/init.php did not reach usersc/includes/loader.php; loading config.php directly\n");
    // init.php sets these at its top, before anything that can fail, but
    // Server::get('DOCUMENT_ROOT', '') can also yield '' — hence the empty
    // check, not just ??=. They must be right, not merely set: config.php
    // builds the ASSET_VERSION path from them, and an empty root silently
    // resolves ASSET_VERSION to 'dev'. PHPUnit includes this bootstrap from a
    // function scope and config.php's require inherits it, so these are
    // visible without `global`.
    if (($abs_us_root ?? '') === '') {
        $abs_us_root = $projectRoot;
    }
    if (($us_url_root ?? '') === '') {
        $us_url_root = '/';
    }
    require_once $projectRoot . '/usersc/includes/config.php';

    // require_once no-ops if config.php was already included but died partway
    // (leaving it permanently half-defined), so confirm rather than assume.
    if (!defined('ELAN_IMAGE_DIR')) {
        abortBootstrap(
            'ERROR: usersc/includes/config.php did not define ELAN_IMAGE_DIR.',
            'It was likely already included by users/init.php and failed partway,',
            'which makes require_once a no-op here. Aborting.'
        );
    }
}

// Ensure $user global is properly initialized for getSettings() calls
// If users/init.php didn't fully initialize $user, create a minimal User object
//
// Intentionally non-fatal: a failure here means only that this fallback couldn't create
// an empty User() shell (e.g. the User class itself has a constructor issue) — most
// integration tests set up their own authenticated $user explicitly in setUp() and don't
// depend on this fallback existing at all. Aborting the whole run over an unused fallback
// would block tests that never needed it.
if (!isset($GLOBALS['user'])) {
    if (class_exists('User')) {
        try {
            $GLOBALS['user'] = new User();
            fwrite(STDERR, "NOTE: Initialized \$user global for integration tests\n");
        } catch (Throwable $e) {
            fwrite(STDERR, "NOTE: Could not initialize \$user global: {$e->getMessage()}\n");
        }
    }
}

// Try to verify database connection and reinitialize $db if configuration was fixed
try {
    if (class_exists('DB')) {
        // Reset the DB singleton cache to force reconnection with corrected config
        // The DB class caches the PDO connection, so we need to clear it to force a new one
        $reflectionClass = new ReflectionClass(DB::class);
        $instanceProperty = $reflectionClass->getProperty('_instance');
        $instanceProperty->setValue(null, null); // static property: first arg is object (null), second is new value
        fwrite(STDERR, "NOTE: Reset DB singleton cache for reinitialization\n");

        // Now create a fresh DB instance with the corrected configuration
        $testDb = DB::getInstance();
        $result = $testDb->query("SELECT 1");
        fwrite(STDERR, "NOTE: Database connection verified for integration tests\n");

        // Defense-in-depth: check the database ACTUALLY connected to, not just the
        // $_ENV value read earlier. If .env.test.local omits DB_NAME (or any other
        // DB_* key), users/init.php's own createImmutable()->safeLoad() of the root
        // .env backfills the missing key(s) from the dev config — the earlier $_ENV
        // guard can't see that, because it runs before init.php loads at all. This
        // check reflects the connection as it truly resolved, closing that gap.
        //
        // This has its own try/catch (rather than sharing the outer one, which
        // treats failure as a non-fatal "NOTE") because a failure HERE means we
        // couldn't confirm which database we're connected to — that must abort,
        // not be logged as a passive reconnection hiccup and continue anyway.
        try {
            // first() never returns null — its return type is array|object (empty array
            // for zero rows), and SELECT DATABASE() always returns exactly one row on a
            // valid connection — so a plain -> is correct here; ?? '' still covers a
            // theoretical empty-array result, where property access on an array yields null.
            $connectedDb = strtolower(trim((string)($testDb->query('SELECT DATABASE() AS name')->first()->name ?? '')));
        } catch (\Throwable $e) {
            fwrite(STDERR, "ERROR: Could not verify the connected database's identity: {$e->getMessage()}\n");
            fwrite(STDERR, "Refusing to proceed without confirming this is not the development database.\n");
            exit(1);
        }
        if ($connectedDb === 'elanregi_spice') {
            fwrite(STDERR, "ERROR: Integration tests connected to the development database (elanregi_spice).\n");
            fwrite(STDERR, "This likely means .env.test.local is missing one or more DB_* keys, which were\n");
            fwrite(STDERR, "backfilled from the root .env file. Ensure .env.test.local sets DB_HOST, DB_USER,\n");
            fwrite(STDERR, "DB_NAME, and DB_PASS explicitly. Aborting.\n");
            exit(1);
        }

        // Re-initialize the global $db after configuration fixes
        // This ensures $db in tests uses the corrected configuration
        $GLOBALS['db'] = $testDb;
        fwrite(STDERR, "NOTE: Re-initialized global \$db for integration tests\n");
    }
} catch (Throwable $e) {
    // Intentionally non-fatal (unlike the stricter inner catch above at the database-identity
    // check, which does abort): this outer catch covers the DB-singleton-cache reset and
    // reconnection attempt itself. If those steps fail, IntegrationTestCase::setUp()'s own
    // DB::getInstance() + requireDatabase() will independently detect the same unreachable
    // database and skip tests gracefully — that's the authoritative check, this is just an
    // earlier best-effort reconnection step whose failure the real check will catch anyway.
    fwrite(STDERR, "NOTE: Database reconnection attempt failed: {$e->getMessage()}\n");
}

/**
 * Write each message line to STDERR and exit(1).
 *
 * Called directly by the DB-connectivity probe above (#1591) and by the
 * reference-data verification block below (via abortMissingSeed()) to fail
 * loudly rather than let tests run against a silently broken environment.
 * Declared as a plain top-level function, so it is hoisted and callable from
 * the earlier probe despite being defined after it — do not move this
 * function inside a conditional or class scope without checking that call site.
 *
 * @param string ...$lines Message lines, printed in order (no trailing "\n" needed).
 */
function abortBootstrap(string ...$lines): never
{
    foreach ($lines as $line) {
        fwrite(STDERR, $line . "\n");
    }
    exit(1);
}

/**
 * abortBootstrap(), with the fix instructions shared by every check in the
 * reference-data verification block below appended automatically.
 *
 * @param string ...$reasonLines What's missing and why it matters, printed
 *                                before the shared "how to fix it" lines.
 */
function abortMissingSeed(string ...$reasonLines): never
{
    abortBootstrap(...[
        ...$reasonLines,
        "Run: ./scripts/provision-schema.sh (composer seed:run alone targets the",
        "dev database, not this test schema — phinx.php only reads .env).",
    ]);
}

// ============================================================
// Verify Reference Data for Integration Tests
// ============================================================
// car_models is seeded once via Phinx (database/seeds/CarModelsSeed.php), run
// through `composer seed:run` as part of provisioning
// (scripts/provision-schema.sh) — not on every test run. The noowner system
// account and permissions id=3 row are created once by the
// RegisterNoownerAccount and RegisterBaselinePermissions migrations. The
// settings row (id=1) is created by UserSpice's install wizard rather than a
// seed; its ElanRegistry default values are applied by the
// UpdateSettingsBaselineDefaults migration
// (database/migrations/20260817033111_update_settings_baseline_defaults.php).
// This block is a thin, fail-loud verifier: it confirms the reference data
// actually exists and tells you exactly what to run if it doesn't, rather
// than trying to fix it inline.

try {
    if (class_exists('DB')) {
        $db = DB::getInstance();

        $carModelsRow = $db->query("SELECT COUNT(*) as cnt FROM car_models")->first();
        $carModelsCount = $carModelsRow ? (int) $carModelsRow->cnt : 0;
        if ($carModelsCount === 0) {
            abortMissingSeed(
                "ERROR: car_models table is empty.",
                "Integration tests that depend on car_models cannot run. Aborting."
            );
        }

        $settingsExists = $db->query("SELECT 1 FROM settings WHERE id = 1")->first();
        if (!$settingsExists) {
            abortMissingSeed(
                "ERROR: settings row (id=1) is missing.",
                "Car::__construct() and other framework code silently misbehave without it. Aborting."
            );
        }

        $noownerRow = $db->query("SELECT password, protected FROM users WHERE username = 'noowner'")->first();
        if (!$noownerRow) {
            abortMissingSeed(
                "ERROR: the noowner system account is missing.",
                "GDPR account-deletion reassignment tests depend on it. Aborting."
            );
        }
        if ($noownerRow->password !== null || (int) $noownerRow->protected !== 1) {
            abortMissingSeed(
                "ERROR: the noowner account exists but violates ADR-010's invariants",
                "(password must be NULL, protected must be 1). Aborting."
            );
        }

        fwrite(STDERR, "NOTE: Reference data verified (car_models: {$carModelsCount} records, settings, noowner)\n");
    }
} catch (Throwable $e) {
    abortMissingSeed("ERROR: Failed to verify reference data: {$e->getMessage()}");
}
