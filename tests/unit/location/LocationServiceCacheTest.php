<?php

declare(strict_types=1);

require_once __DIR__ . '/_apcu_namespace_overrides.php';

use ElanRegistry\LocationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * LocationService file-cache I/O failure paths.
 *
 * The unit bootstrap's no-op logger() cannot be replaced, so each failure test
 * asserts a file-system effect on the same branch as the logger() call (for
 * example, an expired file that still exists after a failed unlink).
 *
 * Tests that force an I/O failure install an error handler for the E_WARNING
 * that PHP emits, because PHPUnit fails on unexpected warnings.
 *
 * The file cache is also used when APCu is loaded but not working
 * (apc.enable_cli=Off on CI), so this suite does not depend on APCu (#1470).
 */
#[Group('fast')]
#[Group('unit')]
#[Group('location-service')]
final class LocationServiceCacheTest extends TestCase
{
    private string $tempRoot = '';

    private string $cacheDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir() . '/location_service_test_' . uniqid('', true);
        $this->cacheDir = $this->tempRoot . '/usersc/cache/';
        mkdir($this->cacheDir, 0755, true);

        $GLOBALS['abs_us_root'] = $this->tempRoot . '/';
        $GLOBALS['us_url_root'] = '';
    }

    protected function tearDown(): void
    {
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

    // Helpers

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

    /** Matches LocationService's md5($key) . '.cache' naming. */
    private function cacheFilePath(string $key): string
    {
        return $this->cacheDir . md5($key) . '.cache';
    }

    /** @param mixed $value */
    private function writeCacheFile(string $key, mixed $value, int $ttlSeconds = 300): void
    {
        $data = [
            'value'   => $value,
            'expires' => time() + $ttlSeconds,
        ];
        file_put_contents($this->cacheFilePath($key), json_encode($data));
    }

    /** @param mixed $value */
    private function writeExpiredCacheFile(string $key, mixed $value): void
    {
        $data = [
            'value'   => $value,
            'expires' => time() - 1,   // already expired
        ];
        file_put_contents($this->cacheFilePath($key), json_encode($data));
    }

    private function privateMethod(LocationService $service, string $name): ReflectionMethod
    {
        $ref = new ReflectionClass($service);
        return $ref->getMethod($name);
    }

    /**
     * Each content takes a getCache() branch that deletes the file: an expired
     * entry, valid JSON with no "expires" key (treated as expired), and
     * corrupt JSON (its own logger() and unlink branch).
     *
     * @return array<string, array{string}>
     */
    public static function unlinkContentProvider(): array
    {
        return [
            'expired entry'       => [(string) json_encode(['value' => [], 'expires' => time() - 1])],
            'missing expires key' => [(string) json_encode(['value' => 'orphaned', 'no_expiry' => true])],
            'corrupt JSON'        => ['{not valid json'],
        ];
    }

    /**
     * The file stays when unlink() fails, which proves that the failure
     * branch and its logger() call were reached.
     */
    #[Group('fast')]
    #[DataProvider('unlinkContentProvider')]
    public function test_getCache_returnsNull_andLeavesFile_whenUnlinkFails(string $content): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test unlink failure as root — chmod 0555 has no effect.');
        }

        $key       = 'unlink_branch_test';
        $cacheFile = $this->cacheFilePath($key);
        file_put_contents($cacheFile, $content);

        chmod($this->cacheDir, 0555);

        $service = new LocationService();
        set_error_handler(static function (): bool { return true; }, E_WARNING);
        try {
            $result = $this->privateMethod($service, 'getCache')->invoke($service, $key);
        } finally {
            restore_error_handler();
        }

        $this->assertNull($result, 'getCache() must return null when the entry cannot be used.');
        $this->assertFileExists($cacheFile, 'Cache file must remain when unlink() fails.');
    }

    #[Group('fast')]
    #[DataProvider('unlinkContentProvider')]
    public function test_getCache_returnsNull_andDeletesFile_whenUnlinkSucceeds(string $content): void
    {
        $key       = 'unlink_branch_test';
        $cacheFile = $this->cacheFilePath($key);
        file_put_contents($cacheFile, $content);

        $service = new LocationService();
        $result  = $this->privateMethod($service, 'getCache')->invoke($service, $key);

        $this->assertNull($result, 'getCache() must return null when the entry cannot be used.');
        $this->assertFileDoesNotExist($cacheFile, 'Cache file should be deleted when unlink() succeeds.');
    }

    // setCache(): mkdir failure (read-only parent)

    #[Group('fast')]
    public function test_setCache_returnsEarly_andCreatesNoCacheFile_whenMkdirFails(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test mkdir failure as root — chmod 0555 has no effect.');
        }

        $this->removeDirectory($this->cacheDir);
        $userscDir = $this->tempRoot . '/usersc';
        chmod($userscDir, 0555);

        $key     = 'mkdir_fail_key';
        $service = new LocationService();

        set_error_handler(static function (): bool { return true; }, E_WARNING);
        try {
            $this->privateMethod($service, 'setCache')->invoke($service, $key, ['data' => 'value']);
        } finally {
            restore_error_handler();
        }

        chmod($userscDir, 0755);

        $this->assertDirectoryDoesNotExist(
            $this->cacheDir,
            'Cache directory must not be created when mkdir() fails.'
        );

        $this->assertFileDoesNotExist(
            $this->cacheFilePath($key),
            'No cache file should exist when setCache() returns early due to mkdir failure.'
        );
    }

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

    // setCache(): file_put_contents failure (read-only directory)

    #[Group('fast')]
    public function test_setCache_createsNoCacheFile_whenFileWriteFails(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test write failure as root — chmod 0555 has no effect.');
        }

        chmod($this->cacheDir, 0555);

        $key             = 'write_fail_key';
        $service         = new LocationService();
        $capturedWarning = null;

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

        $this->assertNotNull(
            $capturedWarning,
            'A PHP E_WARNING should have been emitted by file_put_contents() when the directory is read-only.'
        );
        $this->assertMatchesRegularExpression(
            '/[Ff]ailed to open stream|[Pp]ermission denied/',
            $capturedWarning,
            'The warning message should indicate a stream or permission failure.'
        );

        $this->assertFileDoesNotExist(
            $this->cacheFilePath($key),
            'No cache file should exist when file_put_contents() fails — the logger call is on the same if-branch.'
        );
    }

    #[Group('fast')]
    public function test_setCache_returnsEarly_andCreatesNoCacheFile_whenJsonEncodeFails(): void
    {
        $key = 'json_encode_fail_key';
        // Invalid UTF-8, so json_encode() fails.
        $nonUtf8Value = "\xFF\xFE invalid bytes";

        $service = new LocationService();

        set_error_handler(static function (): bool { return true; }, E_WARNING);
        try {
            $this->privateMethod($service, 'setCache')->invoke($service, $key, $nonUtf8Value);
        } finally {
            restore_error_handler();
        }

        $this->assertFileDoesNotExist(
            $this->cacheFilePath($key),
            'No cache file should be created when json_encode() fails for the cached value.'
        );
    }

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

    // getCache(): happy paths

    #[Group('fast')]
    public function test_getCache_returnsNull_forMissingKey(): void
    {
        $service = new LocationService();
        $result  = $this->privateMethod($service, 'getCache')->invoke($service, 'nonexistent_key_xyz');

        $this->assertNull($result, 'getCache() must return null for a key with no cache file.');
    }

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

    #[Group('fast')]
    public function test_getCache_returnsNull_forExpiredEntry(): void
    {
        $key = 'expired_entry_test';
        $this->writeExpiredCacheFile($key, ['stale' => 'data']);

        $service = new LocationService();
        $result  = $this->privateMethod($service, 'getCache')->invoke($service, $key);

        $this->assertNull($result, 'getCache() must return null for an expired cache entry.');
    }

    // setCache() / getCache() round-trip

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

    #[Group('fast')]
    public function test_setCache_usesDefaultTtl_whenTtlIsNull(): void
    {
        $key     = 'default_ttl_key';
        $service = new LocationService();
        $before  = time();

        $this->privateMethod($service, 'setCache')->invoke($service, $key, ['data' => true], null);

        $cacheFile = $this->cacheFilePath($key);
        $this->assertFileExists($cacheFile);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(file_get_contents($cacheFile), true);
        $this->assertGreaterThanOrEqual($before + 300, $decoded['expires']);
        $this->assertLessThanOrEqual($before + 301, $decoded['expires']);
    }

    // getCache(): unreadable file

    #[Group('fast')]
    public function test_getCache_returnsNull_whenCacheFileIsUnreadable(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test read failure as root — chmod 0000 has no effect.');
        }

        $key       = 'unreadable_file_test';
        $cacheFile = $this->cacheFilePath($key);
        $this->writeCacheFile($key, ['data' => 'value']);

        chmod($cacheFile, 0000);

        $service         = new LocationService();
        $capturedWarning = null;
        set_error_handler(
            static function (int $errno, string $errstr) use (&$capturedWarning): bool {
                if ($errno === E_WARNING) {
                    $capturedWarning = $errstr;
                    return true;
                }
                return false;
            },
            E_WARNING
        );
        try {
            $result = $this->privateMethod($service, 'getCache')->invoke($service, $key);
        } finally {
            restore_error_handler();
        }

        $this->assertNull($result, 'getCache() must return null when the cache file cannot be read.');
        $this->assertNotNull(
            $capturedWarning,
            'A PHP E_WARNING should be emitted when file_get_contents() fails on an unreadable file.'
        );
    }

    // getCache(): path-traversal guard

    /** file_exists() is false for a dangling symlink, so getCache() returns early. */
    #[Group('fast')]
    public function test_getCache_returnsNull_forDanglingSymlink(): void
    {
        $key       = 'dangling_symlink_test';
        $cacheFile = $this->cacheFilePath($key);

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
    }

    // APCu loaded but not working (#1470). mockApcuSimulateFailure makes the
    // namespace overrides report apcu_* as present while every call fails.

    protected function tearDownApcuSimulation(): void
    {
        unset($GLOBALS['mockApcuSimulateFailure']);
    }

    #[Group('fast')]
    public function test_setCacheThenGetCache_roundTrip_whenApcuPresentButNonFunctional(): void
    {
        $GLOBALS['mockApcuSimulateFailure'] = true;

        try {
            $key     = 'apcu_fallback_roundtrip_key';
            $payload = ['city' => 'Berlin', 'state' => '', 'country' => 'Germany'];

            $service   = new LocationService();
            $setMethod = $this->privateMethod($service, 'setCache');
            $getMethod = $this->privateMethod($service, 'getCache');

            $setMethod->invoke($service, $key, $payload);

            $this->assertFileExists(
                $this->cacheFilePath($key),
                'setCache() must fall through to writing a file when APCu is present but every store fails.'
            );

            $result = $getMethod->invoke($service, $key);

            $this->assertSame(
                $payload,
                $result,
                'getCache() must fall through to reading the file cache when APCu is present but every fetch fails.'
            );
        } finally {
            $this->tearDownApcuSimulation();
        }
    }
}
