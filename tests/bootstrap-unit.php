<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;

/**
 * PHPUnit Bootstrap File for Unit Tests
 *
 * Sets up the testing environment with MOCKS ONLY.
 * No UserSpice framework or database.
 * Use this for: tests/unit/* (including tests/unit/regression/*)
 *
 * For integration tests, use: tests/bootstrap-integration.php
 */

// Set up testing environment - MOCKS ONLY
define('TESTING', true);
define('TESTING_UNIT_ONLY', true);
define('UNIT_TEST_SUITE', true);

// Backup retention constants (normally from usersc/includes/config.php)
if (!defined('BACKUP_RETENTION_AUTOMATED')) {
    define('BACKUP_RETENTION_AUTOMATED', 7);
    define('BACKUP_RETENTION_MANUAL', 30);
    define('BACKUP_RETENTION_ROLLBACK', 30);
    define('BACKUP_WARNING_THRESHOLD_DAYS', 7);
    define('BACKUP_FAILURE_LOOKBACK_DAYS', 7);
}

// Media/image, transfer-expiry, and email-subject constants (normally from
// usersc/includes/config.php, #1067) — mirrors the real values exactly, not
// placeholder test values, since several tests assert against these
// verbatim (e.g. EmailTemplateTest's email-subject assertions).
if (!defined('ELAN_IMAGE_DIR')) {
    define('ELAN_IMAGE_DIR', 'userimages/');
    define('ELAN_IMAGE_MAX', 6);
    define('ELAN_IMAGE_UPLOAD_MAX_SIZE', 3.00);
    define('ELAN_IMAGE_DISPLAY_MAX_SIZE', 2048);
    define('ELAN_IMAGE_THUMBNAIL_SIZES', '100,300,768,1024,2048');
    define('TRANSFER_REQUEST_EXPIRY_DAYS', 30);
    define('EMAIL_SUBJECT_PREFIX', '[ELANREGISTRY]');
}

// Cron transport interval (normally from usersc/includes/config.php, #2001) —
// VerificationSettings::cronStaleAfterSeconds() derives from this.
if (!defined('CRON_TRANSPORT_INTERVAL_MINUTES')) {
    define('CRON_TRANSPORT_INTERVAL_MINUTES', 10);
}

// Prevent any integration test code from loading
if (defined('INTEGRATION_TEST_SUITE')) {
    die("ERROR: bootstrap-unit.php cannot be used with INTEGRATION_TEST_SUITE defined");
}

// Set up basic paths
$projectRoot = dirname(__DIR__);
$_SERVER['DOCUMENT_ROOT'] = $projectRoot;
$_SERVER['PHP_SELF'] = '/tests/';

// Skip UserSpice initialization for now - use mocks instead
// The real framework requires database connection which isn't needed for unit tests

// Mock session for testing
if (!isset($_SESSION)) {
    $_SESSION = [];
}

// These mocks are plain global classes (Token, Input). Composer autoloads only
// namespaced ElanRegistry\* classes, so it can never resolve these bare names —
// defining them here simply provides the only declaration the unit suite ever
// sees. Order relative to the vendor/autoload.php require below is immaterial.
//
// Why Token and Input are mocked rather than loaded for real: NOTHING under
// users/classes/ can ever be require_once'd from this bootstrap, because the whole
// users/ tree is .gitignore'd (`users/**`) — it is a manually installed upstream
// UserSpice checkout, absent from every CI checkout and from `composer install`.
// Requiring users/classes/Token.php works on a developer machine and fatals in CI.
// This constraint is about file availability, not runtime dependencies: Token and
// Input touch nothing but superglobals and would otherwise be perfectly loadable.
//
// Consequence: these mocks are stubs, not production behavior. Real CSRF crypto
// (hash_equals over the session token) and real htmlspecialchars() input sanitization
// are verified in tests/integration/TokenAndInputSecurityTest.php, the only tier where
// users/init.php actually loads the upstream classes. Unit tests here may only assert
// the stub's own contract.
if (!class_exists('Token')) {
    class Token {
        public static function generate(): string {
            return 'test_csrf_token_' . uniqid();
        }

        public static function check(mixed $token): bool {
            if ($token === null || $token === '') {
                return false;
            }
            return strpos($token, 'test_csrf_token_') === 0;
        }
    }
}

if (!class_exists('Input')) {
    /**
     * Stub of the upstream UserSpice Input class.
     *
     * Deliberately a raw passthrough: it does NOT apply the real class's
     * htmlspecialchars() encoding, so no unit test may assert sanitized output.
     */
    class Input {
        public static function get($key, $default = null): mixed {
            return $_POST[$key] ?? $_GET[$key] ?? $default;
        }

        public static function exists($method = 'post'): bool {
            return $method === 'post' ? !empty($_POST) : !empty($_GET);
        }
    }
}

// Define exception classes for testing
// Exception classes and LogCategories are now real classes loaded via autoloader
// No longer using mock implementations - allows tests to verify actual exception behavior

// Mock logger function — tracks calls in $mockLogEntries for test assertions
// Signature must match the real logger() (users/helpers/us_helpers.php) — the
// optional $metadata param is required here too. The real signature is invisible
// to PHPStan (users/ is excluded from its scan), so this stub is the only
// declaration it can resolve logger() calls against once tests/ is in its scan
// path — including calls in production files like usersc/join.php.
if (!function_exists('logger')) {
    function logger($userId, $category, $message, $metadata = null): void {
        global $mockLogEntries;
        if (!isset($mockLogEntries)) {
            $mockLogEntries = [];
        }
        $mockLogEntries[] = [
            'user_id' => $userId,
            'category' => $category,
            'message' => $message,
        ];
    }
}

// Mock email function — tracks sent emails in $mockSentEmails for test assertions.
// Return value can be overridden via $GLOBALS['mockEmailSendResult'] to simulate
// send failures (defaults to true).
if (!function_exists('email')) {
    function email($to, $subject, $body, $opts = [], $attachment = null): bool {
        global $mockSentEmails, $mockEmailSendResult;
        if (!isset($mockSentEmails)) {
            $mockSentEmails = [];
        }
        $mockSentEmails[] = [$to, $subject, $body];

        return $mockEmailSendResult ?? true;
    }
}

// Mock email_body function — returns a canned rendered body by default.
// Override via $GLOBALS['mockEmailBodyResult'] (e.g. '') to exercise the
// empty-body failure branch of callers.
if (!function_exists('email_body')) {
    function email_body($template, $options = [], $security_override = false): string {
        global $mockEmailBodyResult;
        return $mockEmailBodyResult ?? '<html>mock email body</html>';
    }
}

// Mock randomstring/hashVericode — the real implementations live in
// users/helpers/us_helpers.php (randomString(), case-insensitively callable as
// randomstring()) which is not loaded in the unit-test environment, and
// hashVericode() depends on getVericodeSecret()'s file-based secret key. Both
// are pure enough to stand in for here: a fixed-length test string and a
// deterministic (non-identity) transform so tests can assert hashing occurred.
if (!function_exists('randomstring')) {
    function randomstring(int $len): string {
        return str_repeat('x', $len);
    }
}

if (!function_exists('hashVericode')) {
    function hashVericode(string $vericode): string {
        return 'hashed_' . $vericode;
    }
}

// Load type helper functions (dbInt, currentUserId).
// custom_functions.php itself requires server_globals.php, which depends on
// the Server class and full framework initialization, so it can't be loaded
// standalone in the unit tier — these are stand-ins for the global function
// names. dbInt() delegates to ElanRegistry\TypeHelpers::toInt() (#1599), the
// same class the real dbInt() in custom_functions.php delegates to, so the
// conversion logic itself can never drift between tiers; currentUserId()
// below is still a hand-written duplicate (session-coupled — deliberately
// not extracted, see TypeHelpersTest.php's docblock and #1599). Definition
// order relative to the autoloader require below is immaterial here too —
// PHP resolves \ElanRegistry\TypeHelpers when the function body *runs*, not
// when it's defined, and this stub is never called before the require.
if (!function_exists('dbInt')) {
    function dbInt(mixed $value, string $property = 'id'): int
    {
        return \ElanRegistry\TypeHelpers::toInt($value, $property);
    }
}

if (!function_exists('currentUserId')) {
    function currentUserId(): int
    {
        global $user;
        if (!isset($user) || !$user->isLoggedIn()) {
            throw new RuntimeException('No user is currently logged in');
        }
        return (int) $user->data()->id;
    }
}

// Load Composer autoloader for all custom classes and exceptions
require_once $projectRoot . '/vendor/autoload.php';

// Mock securePage function - only for unit tests
if (!function_exists('securePage')) {
    function securePage($page): bool {
        return true; // Always allow access in tests
    }
}

// Mock getSettings function - only for unit tests
if (!function_exists('getSettings')) {
    /**
     * Mock getSettings function for testing.
     * elan_image_dir was removed from this mock in #1067 — image/expiry/
     * email settings are now ELAN_IMAGE_DIR etc. constants (defined above),
     * not $settings properties.
     */
    function getSettings($id = 1): object {
        return (object) [
            'id' => (string) $id,
        ];
    }
}

// Mock getBaseUrl function - needed by EmailTemplate
if (!function_exists('getBaseUrl')) {
    /**
     * Mock getBaseUrl function for testing
     * Returns a test base URL
     */
    function getBaseUrl(): string
    {
        return 'https://test.elanregistry.org';
    }
}

// Mock getFeedbackEmail function - needed by verify-new-email template
if (!function_exists('getFeedbackEmail')) {
    /**
     * Mock getFeedbackEmail function for testing
     * Returns 'registrar@elanregistry.org' — mirrors the production fallback in
     * custom_functions.php so that render assertions reflect real-world behavior.
     */
    function getFeedbackEmail(): string
    {
        return 'registrar@elanregistry.org';
    }
}

// Mock getAdminEmails function - needed by transfer email templates
if (!function_exists('getAdminEmails')) {
    /**
     * Mock getAdminEmails function for testing.
     * Set $GLOBALS['mockAdminEmails'] to override the default in a test.
     */
    function getAdminEmails(): string
    {
        global $mockAdminEmails;
        if (isset($mockAdminEmails)) {
            return $mockAdminEmails;
        }
        return 'admin@elanregistry.org';
    }
}

// Mock isRegistryAdmin function - needed by admin permission checks
if (!function_exists('isRegistryAdmin')) {
    /**
     * Mock isRegistryAdmin function for testing
     * Uses global $mockIsRegistryAdmin to control behavior
     *
     * Signature must match the real isRegistryAdmin() (usersc/includes/custom_functions.php)
     * — the optional, nullable $userId is required here too, otherwise PHPStan resolves
     * every isRegistryAdmin() call project-wide against this narrower stub once tests/ is
     * in its scan path.
     *
     * The no-argument path delegates to currentUserId(), which in the unit tier always
     * THROWS RuntimeException('No user is currently logged in'): since MockUser was
     * removed in #1554 nothing here sets an ambient $user, deliberately. A caller that
     * needs a bool must therefore either pass an explicit $userId or set the
     * $mockIsRegistryAdmin global. Throwing loudly is the intent — silently resolving a
     * missing user to "not admin" would be a quiet wrong answer.
     * dbInt() normalizes the string-ID case too (both real call sites pass $user->data()->id,
     * which is a string) — a bare `=== 1` would silently return false for '1' under strict
     * comparison, the same quiet-wrong-answer trap the null case was fixed to avoid.
     */
    function isRegistryAdmin(int|string|null $userId = null): bool
    {
        global $mockIsRegistryAdmin;

        // If explicitly set in test, use that value
        if (isset($mockIsRegistryAdmin)) {
            return (bool) $mockIsRegistryAdmin;
        }

        // Default: user ID 1 is admin
        return dbInt($userId ?? currentUserId()) === 1;
    }
}

// RegressionTestCase is not in the PSR-4 autoloaded path, so it must be
// explicitly required before PHPUnit loads test classes that extend it.
require_once __DIR__ . '/unit/regression/RegressionTestCase.php';
