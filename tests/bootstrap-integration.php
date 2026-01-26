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

// Load environment variables BEFORE calling users/init.php
// Use SecureEnvPHP if available to parse encrypted .env.enc
// Otherwise fall back to plaintext .env.local parsing

// First, try to load Composer autoloader (which includes SecureEnvPHP)
$composerAutoloadPath = $projectRoot . '/usersc/vendor/autoload.php';
if (file_exists($composerAutoloadPath)) {
    require_once $composerAutoloadPath;

    // Use SecureEnvPHP to parse encrypted environment file
    $envEncPath = $projectRoot . '/.env.enc';
    $envKeyPath = $projectRoot . '/.env.key';

    if (file_exists($envEncPath) && file_exists($envKeyPath)) {
        try {
            $secureEnv = new \SecureEnvPHP\SecureEnvPHP();
            $secureEnv->parse($envEncPath, $envKeyPath);
            fwrite(STDERR, "NOTE: Loaded encrypted environment from .env.enc\n");
        } catch (Throwable $e) {
            fwrite(STDERR, "NOTE: Failed to parse .env.enc: {$e->getMessage()}\n");
        }
    }
}

// Fallback: Try to load environment variables from .env.local
// This helps when encrypted file isn't available or fails to parse
$envLocalPath = $projectRoot . '/.env.local';
$dbHost = null;
$dbPort = null;

if (file_exists($envLocalPath)) {
    $envContent = file_get_contents($envLocalPath);
    // Parse basic environment variables from .env.local
    foreach (explode("\n", $envContent) as $line) {
        if (empty(trim($line)) || strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Capture host and port separately for later combination
            if ($key === 'DEV_DB_HOST') {
                $dbHost = $value;
            } elseif ($key === 'DEV_DB_PORT') {
                $dbPort = $value;
            }

            // Map dev environment variables to standard DB variables if needed
            if (strpos($key, 'DEV_DB_') === 0) {
                $standardKey = str_replace('DEV_DB_', 'DB_', $key);
                putenv("{$standardKey}={$value}");
            } elseif (!getenv($key)) {
                // Only set if not already set (encrypted file takes precedence)
                putenv("{$key}={$value}");
            }
        }
    }
    fwrite(STDERR, "NOTE: Loaded plaintext environment from .env.local\n");
}

// Combine host and port for MySQL connection
// The DB class DSN only uses 'host', so we need to combine port into it if needed
if ($dbHost && $dbPort) {
    // Override DB_HOST to include port
    putenv("DB_HOST={$dbHost}:{$dbPort}");
    fwrite(STDERR, "NOTE: Combined DB_HOST with port: {$dbHost}:{$dbPort}\n");
}

// Special handling for MAMP MySQL socket on macOS
// MAMP uses Unix socket instead of TCP on localhost:8889
$mampSocket = '/Applications/MAMP/tmp/mysql/mysql.sock';
if (file_exists($mampSocket) && !getenv('DB_SOCKET')) {
    putenv("DB_SOCKET={$mampSocket}");
    fwrite(STDERR, "NOTE: Set MAMP MySQL socket: {$mampSocket}\n");
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

// Fix up configuration if SecureEnvPHP overwrote our port settings
// The encrypted .env.enc likely has DB_HOST as just 'localhost' or 'localhost:8889'
// We need to ensure the port is included for MAMP connections
if (isset($GLOBALS['config']) && isset($GLOBALS['config']['mysql']) && isset($GLOBALS['config']['mysql']['host'])) {
    $currentHost = $GLOBALS['config']['mysql']['host'];
    // If host doesn't include port and we have a separate port, combine them
    if (strpos($currentHost, ':') === false && $dbPort) {
        $GLOBALS['config']['mysql']['host'] = $currentHost . ':' . $dbPort;
        fwrite(STDERR, "NOTE: Updated config host with port: " . $GLOBALS['config']['mysql']['host'] . "\n");
    }
}

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

// Try to verify database connection, but don't fail if it doesn't work
try {
    if (class_exists('DB')) {
        $testDb = DB::getInstance();
        $result = $testDb->query("SELECT 1");
        fwrite(STDERR, "NOTE: Database connection verified for integration tests\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, "NOTE: Database not available - integration tests will skip gracefully\n");
}
