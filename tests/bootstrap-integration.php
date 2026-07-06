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

// Load autoloader for custom classes FIRST (before UserSpice)
$autoloaderPath = $projectRoot . '/usersc/classes/class.autoloader.php';
if (file_exists($autoloaderPath)) {
    require_once $autoloaderPath;
}

// Load UserSpice framework for real database testing and authentication
$initPath = $projectRoot . '/users/init.php';
if (!file_exists($initPath)) {
    fwrite(STDERR, "ERROR: UserSpice initialization file not found at: {$initPath}\n");
    fwrite(STDERR, "Integration tests require UserSpice framework to be installed.\n");
    fwrite(STDERR, "To skip integration tests, use: composer test:unit\n");
    exit(1);
}

// Load usersc/vendor autoload so Dotenv and other composer deps are available
// before users/init.php runs (init.php loads it via helpers.php, but we need
// Dotenv earlier to set env vars that init.php's own Dotenv call will read)
$userscAutoload = $projectRoot . '/usersc/vendor/autoload.php';
if (file_exists($userscAutoload)) {
    require_once $userscAutoload;
}

// Load test environment (.env.local overrides .env for local development)
// .env.local uses DB_* names directly (e.g. DB_HOST=127.0.0.1:8889 for MAMP)
// createMutable() allows init.php's createImmutable() to read our test values from $_ENV
$envLocal = $projectRoot . '/.env.local';
$envName  = file_exists($envLocal) ? '.env.local' : '.env';

try {
    \Dotenv\Dotenv::createMutable($projectRoot, $envName)->load();
    fwrite(STDERR, "NOTE: Loaded test environment from {$envName}\n");
} catch (\Dotenv\Exception\ExceptionInterface $e) {
    fwrite(STDERR, "WARNING: Could not load {$envName}: {$e->getMessage()}\n");
    fwrite(STDERR, "WARNING: All integration tests requiring a database will be skipped.\n");
    fwrite(STDERR, "WARNING: To enable them, copy .env.local.sample to .env.local and fill in credentials.\n");
}

// Suppress UserSpice initialization errors (especially database connection errors)
// Integration tests will handle the missing database gracefully via IntegrationTestCase
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    // Suppress all errors during bootstrap - integration tests will handle gracefully
    return true;
});

// Start output buffering to catch any die() output messages
ob_start();

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

// Ensure $user global is properly initialized for getSettings() calls
// If users/init.php didn't fully initialize $user, create a minimal User object
if (!isset($GLOBALS['user']) || $GLOBALS['user'] === null) {
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
        $reflectionClass = new ReflectionClass('DB');
        $instanceProperty = $reflectionClass->getProperty('_instance');
        $instanceProperty->setValue(null);
        fwrite(STDERR, "NOTE: Reset DB singleton cache for reinitialization\n");

        // Now create a fresh DB instance with the corrected configuration
        $testDb = DB::getInstance();
        $result = $testDb->query("SELECT 1");
        fwrite(STDERR, "NOTE: Database connection verified for integration tests\n");

        // Re-initialize the global $db after configuration fixes
        // This ensures $db in tests uses the corrected configuration
        $GLOBALS['db'] = $testDb;
        fwrite(STDERR, "NOTE: Re-initialized global \$db for integration tests\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, "NOTE: Database reconnection attempt failed: {$e->getMessage()}\n");
}

// ============================================================
// Auto-load Reference Data for Integration Tests
// ============================================================
// Integration tests require car_models table to be populated.
// Automatically load reference data if the table is empty.

try {
    if (class_exists('DB')) {
        $db = DB::getInstance();

        // Check if car_models table exists and is empty
        $count = $db->query("SELECT COUNT(*) as cnt FROM car_models")->first();

        if ($count && $count->cnt == 0) {
            fwrite(STDERR, "NOTE: car_models table is empty, loading reference data...\n");

            // Load reference data from SQL file
            $refDataPath = dirname(__DIR__) . '/database/2-reference-data.sql';

            if (file_exists($refDataPath)) {
                $refDataSql = file_get_contents($refDataPath);

                if ($refDataSql !== false) {
                    // Extract just the car_models INSERT statement
                    $carModelsPattern = '/INSERT IGNORE INTO `car_models`.*?VALUES\s*(.*?);/s';

                    if (preg_match($carModelsPattern, $refDataSql, $matches)) {
                        $carModelsInsert = "INSERT IGNORE INTO `car_models`
                          (`year_available_from`, `year_available_to`, `display_name`,
                           `human_readable_short`, `series`, `variant`, `type_code`, `model_value`)
                        VALUES " . $matches[1] . ";";

                        // Execute the INSERT
                        $db->query($carModelsInsert);

                        // Verify loaded
                        $newCount = $db->query("SELECT COUNT(*) as cnt FROM car_models")->first();
                        $loadedCount = $newCount ? $newCount->cnt : 0;

                        fwrite(STDERR, "NOTE: Loaded {$loadedCount} car_models records for integration tests\n");
                    } else {
                        fwrite(STDERR, "NOTE: Could not parse car_models INSERT from reference data file\n");
                    }
                } else {
                    fwrite(STDERR, "NOTE: Failed to read reference data file\n");
                }
            } else {
                fwrite(STDERR, "NOTE: Reference data file not found: {$refDataPath}\n");
            }
        } else {
            $recordCount = $count ? $count->cnt : 0;
            fwrite(STDERR, "NOTE: car_models table already populated with {$recordCount} records\n");
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "NOTE: Failed to load reference data: {$e->getMessage()}\n");
    // Non-fatal: tests requiring car_models will handle gracefully
}
