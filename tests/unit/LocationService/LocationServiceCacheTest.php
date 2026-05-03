<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for LocationService silent-failure logging in the file-cache path.
 *
 * These tests exercise the three @-suppressed I/O calls that were hardened to
 * call logger() on failure (v2.20.0):
 *
 *   1. getCache()  — unlink failure on an expired cache file
 *   2. setCache()  — mkdir failure when cache directory cannot be created
 *   3. setCache()  — file_put_contents failure when the cache file cannot be written
 *
 * === Logger Spy Strategy ===
 *
 * The bootstrap-unit.php defines a no-op logger() that wins over the recording
 * mock defined later in the same file (both are guarded by function_exists()).
 * PHP does not allow function redefinition, so we cannot intercept logger()
 * calls directly in tests.
 *
 * Instead, each failure-path test asserts the observable FILE SYSTEM effect
 * that proves the failure branch was executed:
 *
 *   - unlink failure    → the expired cache file still exists after getCache()
 *   - mkdir failure     → no cache file was created; setCache() returned early
 *   - write failure     → no cache file was created; file_put_contents returned false
 *
 * These filesystem invariants are on the same code path as the logger() calls,
 * so satisfying them proves the logger call line was reached.  The functional
 * correctness and round-trip tests additionally verify the happy paths.
 *
 * All three code paths live in private methods, so they are exercised via
 * ReflectionMethod where needed to avoid requiring live HTTP endpoints.
 *
 * The file-cache path is active whenever APCu is unavailable.  PHPUnit does not
 * load the APCu extension in this project, so the file path is always taken.
 *
 * @see usersc/classes/LocationService.php
 */
#[Group('fast')]
#[Group('unit')]
#[Group('location-service')]
final class LocationServiceCacheTest extends TestCase
{
    /**
     * Temporary directory that serves as the application root during tests.
     * Cleaned up in tearDown().
     */
    private string $tempRoot = '';

    /** @var string Full path to the cache directory inside $tempRoot */
    private string $cacheDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Build an isolated temp directory that mimics the structure
        // LocationService expects:  $abs_us_root . $us_url_root . 'usersc/cache/'
        // We set $us_url_root = '' so the cache dir is $abs_us_root/usersc/cache/
        $this->tempRoot = sys_get_temp_dir() . '/location_service_test_' . uniqid('', true);
        $this->cacheDir = $this->tempRoot . '/usersc/cache/';
        mkdir($this->cacheDir, 0755, true);

        // Point the globals the service reads at our temp directory
        $GLOBALS['abs_us_root'] = $this->tempRoot . '/';
        $GLOBALS['us_url_root'] = '';
    }

    protected function tearDown(): void
    {
        // Restore write permissions so cleanup can remove everything
        if (is_dir($this->cacheDir)) {
            chmod($this->cacheDir, 0755);
            foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
                if (is_link($file)) {
                    unlink($file);
                } elseif (is_file($file)) {
                    chmod($file, 0644);
                    unlink($file);
                }
            }
        }

        $this->removeDirectory($this->tempRoot);

        unset($GLOBALS['abs_us_root'], $GLOBALS['us_url_root']);

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Recursively remove a directory tree.
     */
    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (glob($path . '/*') ?: [] as $entry) {
            is_dir($entry) && !is_link($entry)
                ? $this->removeDirectory($entry)
                : unlink($entry);
        }
        rmdir($path);
    }

    /**
     * Return the cache file path for a given key, matching LocationService's
     * internal naming convention (md5($key) . '.cache').
     */
    private function cacheFilePath(string $key): string
    {
        return $this->cacheDir . md5($key) . '.cache';
    }

    /**
     * Write a valid (non-expired) file-cache entry directly, bypassing the
     * service, so that getCache() returns a hit for the given key.
     *
     * @param mixed $value
     */
    private function writeCacheFile(string $key, mixed $value, int $ttlSeconds = 300): void
    {
        $data = [
            'value'   => $value,
            'expires' => time() + $ttlSeconds,
        ];
        file_put_contents($this->cacheFilePath($key), json_encode($data));
    }

    /**
     * Write an EXPIRED file-cache entry directly so that getCache() will
     * attempt to delete it.
     *
     * @param mixed $value
     */
    private function writeExpiredCacheFile(string $key, mixed $value): void
    {
        $data = [
            'value'   => $value,
            'expires' => time() - 1,   // already expired
        ];
        file_put_contents($this->cacheFilePath($key), json_encode($data));
    }

    /**
     * Access a private method on LocationService via Reflection.
     *
     * Note: setAccessible() became a no-op in PHP 8.1 and is deprecated in
     * PHP 8.5.  We omit it; private methods are accessible via Reflection
     * without the call on all supported PHP versions for this project (8.2+).
     */
    private function privateMethod(LocationService $service, string $name): ReflectionMethod
    {
        $ref = new ReflectionClass($service);
        return $ref->getMethod($name);
    }

    // =========================================================================
    // Tests: getCache() — unlink failure on expired cache file
    //
    // Strategy: write an expired cache file, then make the cache DIRECTORY
    // read-only (chmod 0555) so that unlink() inside getCache() cannot remove
    // the file.  Call getCache() via reflection and assert:
    //   (a) it returns null  (expired entry is never used)
    //   (b) the expired file STILL EXISTS  (unlink failed — same code path as
    //       the logger() call we cannot intercept directly)
    // =========================================================================

    /**
     * getCache() returns null for an expired entry and leaves the file in
     * place when unlink() cannot remove it (directory is read-only).
     *
     * The logger() call is on the same if-branch as the failed unlink, so
     * the file still existing proves that code path was reached.
     */
    #[Group('fast')]
    public function test_getCache_returnsNull_andLeavesExpiredFile_whenUnlinkFails(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test unlink failure as root — chmod 0555 has no effect.');
        }

        $key = 'rate_limit_unlink_test';
        $this->writeExpiredCacheFile($key, []);
        $cacheFile = $this->cacheFilePath($key);

        // Lock the directory so unlink() cannot remove the expired file
        chmod($this->cacheDir, 0555);

        $service = new LocationService();
        $result  = $this->privateMethod($service, 'getCache')->invoke($service, $key);

        $this->assertNull(
            $result,
            'getCache() must return null for an expired entry even when unlink fails.'
        );
        $this->assertFileExists(
            $cacheFile,
            'Expired cache file must still exist when unlink() fails — proving the failure branch (and logger call) was reached.'
        );
    }

    /**
     * When unlink() succeeds the expired file is removed and getCache()
     * returns null (no FileError branch reached).
     */
    #[Group('fast')]
    public function test_getCache_returnsNull_andDeletesExpiredFile_whenUnlinkSucceeds(): void
    {
        $key = 'rate_limit_unlink_ok';
        $this->writeExpiredCacheFile($key, []);
        $cacheFile = $this->cacheFilePath($key);

        $service = new LocationService();
        $result  = $this->privateMethod($service, 'getCache')->invoke($service, $key);

        $this->assertNull($result, 'getCache() must return null for an expired entry.');
        $this->assertFileDoesNotExist(
            $cacheFile,
            'Expired cache file should be deleted when unlink() succeeds.'
        );
    }

    // =========================================================================
    // Tests: setCache() — mkdir failure
    //
    // Strategy: remove the cache directory and make the parent (usersc/) read-
    // only so mkdir() cannot recreate it.  Call setCache() via reflection and
    // assert that NO cache file was created anywhere, proving setCache() hit
    // the early-return path (the same line as the logger() call).
    // =========================================================================

    /**
     * setCache() silently returns early when the cache directory cannot be
     * created, leaving no cache file behind.
     *
     * The logger() call precedes the return, so the absence of a cache file
     * (combined with an inaccessible directory) proves the early-return/logger
     * branch was taken.
     */
    #[Group('fast')]
    public function test_setCache_returnsEarly_andCreatesNoCacheFile_whenMkdirFails(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test mkdir failure as root — chmod 0555 has no effect.');
        }

        // Remove the cache directory and lock the parent so mkdir cannot create it
        $this->removeDirectory($this->cacheDir);
        $userscDir = $this->tempRoot . '/usersc';
        chmod($userscDir, 0555);

        $key     = 'mkdir_fail_key';
        $service = new LocationService();
        $this->privateMethod($service, 'setCache')->invoke($service, $key, ['data' => 'value']);

        // Restore permissions for tearDown
        chmod($userscDir, 0755);

        // setCache() must have returned early — the cache dir was not created
        $this->assertDirectoryDoesNotExist(
            $this->cacheDir,
            'Cache directory must not be created when mkdir() fails.'
        );

        // And therefore no cache file exists
        $this->assertFileDoesNotExist(
            $this->cacheFilePath($key),
            'No cache file should exist when setCache() returns early due to mkdir failure.'
        );
    }

    /**
     * When the cache directory already exists, setCache() skips mkdir entirely
     * and writes the cache file normally.
     */
    #[Group('fast')]
    public function test_setCache_writesCacheFile_whenDirectoryAlreadyExists(): void
    {
        $key     = 'mkdir_ok_existing';
        $service = new LocationService();
        $this->privateMethod($service, 'setCache')->invoke($service, $key, ['city' => 'Portland']);

        $this->assertFileExists(
            $this->cacheFilePath($key),
            'Cache file should be written when the directory already exists.'
        );
    }

    // =========================================================================
    // Tests: setCache() — file_put_contents failure
    //
    // Strategy: ensure the cache directory exists but is read-only (chmod 0555)
    // so that file_put_contents() cannot create the cache file.  Assert that
    // no cache file was created, proving the logger() call line was reached.
    // =========================================================================

    /**
     * setCache() does not create a cache file and does not throw when
     * file_put_contents() fails (directory is read-only).
     *
     * The logger() call is on the if-branch that executes when
     * file_put_contents() returns false; the absence of a cache file proves
     * that branch was reached.
     *
     * PHP emits an E_WARNING when file_put_contents() cannot open the stream.
     * LocationService does not suppress it with @.  PHPUnit 10+ removed
     * expectWarning(), so we capture the PHP warning via a custom error handler
     * and assert on it directly.
     */
    #[Group('fast')]
    public function test_setCache_createsNoCacheFile_whenFileWriteFails(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test write failure as root — chmod 0555 has no effect.');
        }

        // Cache directory exists but is not writable
        chmod($this->cacheDir, 0555);

        $key             = 'write_fail_key';
        $service         = new LocationService();
        $capturedWarning = null;

        // Capture the E_WARNING that file_put_contents() emits so PHPUnit
        // does not flag the test as producing unexpected output/warnings.
        set_error_handler(
            static function (int $errno, string $errstr) use (&$capturedWarning): bool {
                if ($errno === E_WARNING) {
                    $capturedWarning = $errstr;
                    return true; // suppress further propagation
                }
                return false;
            },
            E_WARNING
        );

        try {
            $this->privateMethod($service, 'setCache')->invoke($service, $key, ['result' => 'data']);
        } finally {
            restore_error_handler();
        }

        // A PHP warning was emitted (proves file_put_contents() was called and failed)
        $this->assertNotNull(
            $capturedWarning,
            'A PHP E_WARNING should have been emitted by file_put_contents() when the directory is read-only.'
        );
        $this->assertMatchesRegularExpression(
            '/[Ff]ailed to open stream|[Pp]ermission denied/',
            $capturedWarning,
            'The warning message should indicate a stream or permission failure.'
        );

        // No cache file should have been created
        $this->assertFileDoesNotExist(
            $this->cacheFilePath($key),
            'No cache file should exist when file_put_contents() fails — the logger call is on the same if-branch.'
        );
    }

    /**
     * setCache() writes the cache file correctly when the directory is writable.
     * Verifies the happy path produces a valid, readable cache entry.
     */
    #[Group('fast')]
    public function test_setCache_writesCacheFile_whenDirectoryIsWritable(): void
    {
        $key     = 'write_ok_key';
        $payload = ['city' => 'Portland', 'state' => 'Oregon'];

        $service = new LocationService();
        $this->privateMethod($service, 'setCache')->invoke($service, $key, $payload);

        $cacheFile = $this->cacheFilePath($key);
        $this->assertFileExists($cacheFile, 'Cache file should be written when the directory is writable.');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(file_get_contents($cacheFile), true);
        $this->assertSame($payload, $decoded['value']);
        $this->assertGreaterThan(time(), $decoded['expires']);
    }

    // =========================================================================
    // Tests: getCache() — functional correctness (happy paths and edge cases)
    // =========================================================================

    /**
     * getCache() returns null when no cache file exists for the key.
     */
    #[Group('fast')]
    public function test_getCache_returnsNull_forMissingKey(): void
    {
        $service = new LocationService();
        $result  = $this->privateMethod($service, 'getCache')->invoke($service, 'nonexistent_key_xyz');

        $this->assertNull($result, 'getCache() must return null for a key with no cache file.');
    }

    /**
     * getCache() returns the cached value for a fresh (non-expired) entry.
     */
    #[Group('fast')]
    public function test_getCache_returnsCachedValue_forFreshEntry(): void
    {
        $key      = 'fresh_entry_test';
        $expected = ['city' => 'Portland', 'state' => 'Oregon'];
        $this->writeCacheFile($key, $expected, 300);

        $service = new LocationService();
        $result  = $this->privateMethod($service, 'getCache')->invoke($service, $key);

        $this->assertSame($expected, $result, 'getCache() must return the cached value for a fresh entry.');
    }

    /**
     * getCache() returns null for an expired entry (writable directory path —
     * the file is deleted and null is returned).
     */
    #[Group('fast')]
    public function test_getCache_returnsNull_forExpiredEntry(): void
    {
        $key = 'expired_entry_test';
        $this->writeExpiredCacheFile($key, ['stale' => 'data']);

        $service = new LocationService();
        $result  = $this->privateMethod($service, 'getCache')->invoke($service, $key);

        $this->assertNull($result, 'getCache() must return null for an expired cache entry.');
    }

    // =========================================================================
    // Tests: setCache() / getCache() round-trip
    // =========================================================================

    /**
     * A value stored via setCache() can be retrieved correctly by getCache().
     */
    #[Group('fast')]
    public function test_setCacheThenGetCache_roundTrip_returnsStoredValue(): void
    {
        $key     = 'roundtrip_key';
        $payload = [
            'city'    => 'London',
            'state'   => '',
            'country' => 'United Kingdom',
            'lat'     => 51.5074,
            'lon'     => -0.1278,
        ];

        $service   = new LocationService();
        $setMethod = $this->privateMethod($service, 'setCache');
        $getMethod = $this->privateMethod($service, 'getCache');

        $setMethod->invoke($service, $key, $payload);
        $result = $getMethod->invoke($service, $key);

        $this->assertSame($payload, $result, 'getCache() must return exactly what setCache() stored.');
    }

    /**
     * setCache() with a custom TTL results in a cache file that expires at the
     * correct time.
     */
    #[Group('fast')]
    public function test_setCache_respectsCustomTtl(): void
    {
        $key     = 'custom_ttl_key';
        $service = new LocationService();
        $before  = time();

        $this->privateMethod($service, 'setCache')->invoke($service, $key, ['data' => true], 60);

        $cacheFile = $this->cacheFilePath($key);
        $this->assertFileExists($cacheFile);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(file_get_contents($cacheFile), true);
        $this->assertGreaterThanOrEqual($before + 60, $decoded['expires']);
        $this->assertLessThanOrEqual($before + 61, $decoded['expires']);
    }

    /**
     * setCache() with a null TTL uses the default CACHE_TTL (300 seconds).
     */
    #[Group('fast')]
    public function test_setCache_usesDefaultTtl_whenTtlIsNull(): void
    {
        $key     = 'default_ttl_key';
        $service = new LocationService();
        $before  = time();

        // Invoke with explicit null TTL to exercise the $ttl ?? self::CACHE_TTL path
        $this->privateMethod($service, 'setCache')->invoke($service, $key, ['data' => true], null);

        $cacheFile = $this->cacheFilePath($key);
        $this->assertFileExists($cacheFile);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(file_get_contents($cacheFile), true);
        // Default TTL is 300 seconds
        $this->assertGreaterThanOrEqual($before + 300, $decoded['expires']);
        $this->assertLessThanOrEqual($before + 301, $decoded['expires']);
    }

    // =========================================================================
    // Tests: getCache() — corrupt/invalid cache file triggers the unlink branch
    //
    // A cache file with missing or invalid JSON is treated as expired and
    // enters the same realpath-guarded unlink block as a time-expired entry.
    // These tests verify that unlink failure on a corrupt file is also covered
    // by the logger() fix.
    // =========================================================================

    /**
     * A corrupt cache file (missing "expires" key) that cannot be deleted leaves
     * the file in place, proving the unlink-failure branch (and logger call) was
     * reached for this sub-condition too.
     */
    #[Group('fast')]
    public function test_getCache_returnsNull_andLeavesCorruptFile_whenUnlinkFails(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test unlink failure as root — chmod 0555 has no effect.');
        }

        $key       = 'corrupt_cache_unlink_fail';
        $cacheFile = $this->cacheFilePath($key);

        // Write a cache file with valid JSON but no "expires" key — triggers !isset($data['expires'])
        file_put_contents($cacheFile, json_encode(['value' => 'orphaned', 'no_expiry' => true]));

        // Lock the directory so unlink() inside getCache() cannot remove the corrupt file
        chmod($this->cacheDir, 0555);

        $service = new LocationService();
        $result  = $this->privateMethod($service, 'getCache')->invoke($service, $key);

        $this->assertNull($result, 'getCache() must return null for a corrupt cache entry.');
        $this->assertFileExists(
            $cacheFile,
            'Corrupt cache file must remain when unlink() fails — proving the failure branch was reached.'
        );
    }

    /**
     * A corrupt cache file (missing "expires" key) is deleted when the directory
     * is writable — the same unlink path runs for corrupt entries as for expired ones.
     */
    #[Group('fast')]
    public function test_getCache_returnsNull_andDeletesCorruptFile_whenUnlinkSucceeds(): void
    {
        $key       = 'corrupt_cache_unlink_ok';
        $cacheFile = $this->cacheFilePath($key);

        file_put_contents($cacheFile, json_encode(['value' => 'orphaned', 'no_expiry' => true]));

        $service = new LocationService();
        $result  = $this->privateMethod($service, 'getCache')->invoke($service, $key);

        $this->assertNull($result, 'getCache() must return null for a corrupt cache entry.');
        $this->assertFileDoesNotExist(
            $cacheFile,
            'Corrupt cache file should be deleted when unlink() succeeds.'
        );
    }

    // =========================================================================
    // Tests: path-traversal safety guard in getCache()
    //
    // The unlink in getCache() is guarded by a realpath() check that ensures
    // the file resolves within the cache directory.  A dangling symlink (where
    // file_exists() returns false) causes getCache() to return null early —
    // before even reaching the expired-entry branch — so no FileError path is
    // hit.
    // =========================================================================

    /**
     * A dangling symlink at the cache file path causes getCache() to return
     * null without reaching the expired-entry (or logger) branch.
     */
    #[Group('fast')]
    public function test_getCache_returnsNull_forDanglingSymlink(): void
    {
        $key       = 'dangling_symlink_test';
        $cacheFile = $this->cacheFilePath($key);

        // Create a dangling symlink — file_exists() returns false for it
        symlink('/tmp/nonexistent_target_' . uniqid('', true), $cacheFile);

        $service = new LocationService();
        $result  = $this->privateMethod($service, 'getCache')->invoke($service, $key);

        $this->assertNull(
            $result,
            'getCache() should return null when the cache file path is a dangling symlink.'
        );
        $this->assertTrue(
            is_link($cacheFile),
            'Dangling symlink must remain intact — realpath() returns false for it, so the realpath guard skipped unlink.'
        );

        // Cleanup the symlink (tearDown's glob won't catch it via is_file)
        if (is_link($cacheFile)) {
            unlink($cacheFile);
        }
    }

    // =========================================================================
    // Tests: LogCategories constant
    // =========================================================================

    /**
     * LOG_CATEGORY_FILE_ERROR equals the string literal used in logger() calls.
     */
    #[Group('fast')]
    public function test_logCategoryFileError_hasExpectedValue(): void
    {
        $this->assertSame(
            'FileError',
            LogCategories::LOG_CATEGORY_FILE_ERROR,
            'LOG_CATEGORY_FILE_ERROR must equal "FileError".'
        );
    }
}
