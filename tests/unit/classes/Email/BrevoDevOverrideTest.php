<?php

declare(strict_types=1);

use ElanRegistry\Email\BrevoDevOverride;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BrevoDevOverride::hostOverride() (#2127) and
 * BrevoDevOverride::applyToSdk() (#2184).
 *
 * hostOverride() is a pure static guard with no I/O. Its tests cover the
 * two-part gate that keeps this dev-only override from ever affecting
 * production/staging:
 * `US_ENVIRONMENT` must be exactly `'development'` AND `BREVO_API_HOST`
 * must be a non-empty value, otherwise the real Brevo host is used
 * (signaled here by a `null` return).
 *
 * `US_ENVIRONMENT` is a `define()`'d constant, which PHP allows to be set
 * only once per process. The unit-test bootstrap (tests/bootstrap-unit.php,
 * used by phpunit-unit.xml) deliberately never loads
 * usersc/includes/rate_limits_dev_override.php or loader.php — it is a
 * mocks-only bootstrap with no full framework init — so `US_ENVIRONMENT`
 * is NOT defined when this suite starts. Every test method below still
 * runs with `#[RunInSeparateProcess]`: PHPUnit does not guarantee method
 * execution order, and a shared-process test class here would have its
 * first-run method's `define()` leak into every method that runs after it,
 * turning later assertions into false positives against stale state rather
 * than the case each method claims to test. Isolating every method removes
 * that ordering hazard entirely rather than relying on it not mattering
 * today.
 *
 * The applyToSdk() tests load a fixture that declares
 * `Brevo\Client\Configuration`. Separate processes also keep that class
 * from leaking into other tests.
 *
 * @see usersc/classes/Email/BrevoDevOverride.php
 * @see https://github.com/elan-registry/registry/issues/2127
 * @see https://github.com/elan-registry/registry/issues/2184
 */
#[Group('fast')]
#[Group('unit')]
final class BrevoDevOverrideTest extends TestCase
{
    private const MOCK_HOST = 'http://mock-brevo:8080/v3';

    private const FIXTURE_AUTOLOAD = __DIR__ . '/fixtures/brevo-sdk/autoload.php';

    private const REAL_SDK_AUTOLOAD = __DIR__ . '/../../../../usersc/plugins/sendinblue/vendor/autoload.php';

    protected function tearDown(): void
    {
        putenv('BREVO_API_HOST');
        unset($_ENV['BREVO_API_HOST']);

        parent::tearDown();
    }

    #[RunInSeparateProcess]
    public function testHostOverride_UsEnvironmentUndefined_ReturnsNull(): void
    {
        $_ENV['BREVO_API_HOST'] = 'http://mock-brevo:8080/v3';

        $this->assertNull(BrevoDevOverride::hostOverride());
    }

    #[RunInSeparateProcess]
    public function testHostOverride_UsEnvironmentProductionWithHostSet_ReturnsNull(): void
    {
        define('US_ENVIRONMENT', 'production');
        $_ENV['BREVO_API_HOST'] = 'http://mock-brevo:8080/v3';

        $this->assertNull(BrevoDevOverride::hostOverride());
    }

    #[RunInSeparateProcess]
    public function testHostOverride_DevelopmentWithHostUnset_ReturnsNull(): void
    {
        define('US_ENVIRONMENT', 'development');
        putenv('BREVO_API_HOST');
        unset($_ENV['BREVO_API_HOST']);

        $this->assertNull(BrevoDevOverride::hostOverride());
    }

    #[RunInSeparateProcess]
    public function testHostOverride_DevelopmentWithHostSet_ReturnsHostVerbatim(): void
    {
        define('US_ENVIRONMENT', 'development');
        $_ENV['BREVO_API_HOST'] = 'http://mock-brevo:8080/v3';

        $this->assertSame('http://mock-brevo:8080/v3', BrevoDevOverride::hostOverride());
    }

    #[RunInSeparateProcess]
    public function testHostOverride_DevelopmentWithHostOnlyInProcessEnv_ReturnsHost(): void
    {
        define('US_ENVIRONMENT', 'development');
        putenv('BREVO_API_HOST=http://mock-brevo:8080/v3');
        unset($_ENV['BREVO_API_HOST']);

        $this->assertSame('http://mock-brevo:8080/v3', BrevoDevOverride::hostOverride());
    }

    #[RunInSeparateProcess]
    public function testHostOverride_DevelopmentWithEmptyStringHost_ReturnsNull(): void
    {
        define('US_ENVIRONMENT', 'development');
        $_ENV['BREVO_API_HOST'] = '';

        $this->assertNull(BrevoDevOverride::hostOverride());
    }

    #[RunInSeparateProcess]
    public function testHostOverride_DevelopmentWithZeroStringHost_ReturnsNull(): void
    {
        define('US_ENVIRONMENT', 'development');
        $_ENV['BREVO_API_HOST'] = '0';

        $this->assertNull(BrevoDevOverride::hostOverride());
    }

    #[RunInSeparateProcess]
    public function testApplyToSdk_Production_ReturnsFalseAndDoesNotLoadSdk(): void
    {
        define('US_ENVIRONMENT', 'production');
        $_ENV['BREVO_API_HOST'] = self::MOCK_HOST;

        $this->assertFalse(BrevoDevOverride::applyToSdk(self::FIXTURE_AUTOLOAD));
        $this->assertFalse(class_exists('Brevo\\Client\\Configuration', false));
    }

    #[RunInSeparateProcess]
    public function testApplyToSdk_DevelopmentWithHostUnset_ReturnsFalseAndDoesNotLoadSdk(): void
    {
        define('US_ENVIRONMENT', 'development');
        putenv('BREVO_API_HOST');
        unset($_ENV['BREVO_API_HOST']);

        $this->assertFalse(BrevoDevOverride::applyToSdk(self::FIXTURE_AUTOLOAD));
        $this->assertFalse(class_exists('Brevo\\Client\\Configuration', false));
    }

    #[RunInSeparateProcess]
    public function testApplyToSdk_DevelopmentWithMissingAutoload_ReturnsFalse(): void
    {
        define('US_ENVIRONMENT', 'development');
        $_ENV['BREVO_API_HOST'] = self::MOCK_HOST;

        $this->assertFalse(BrevoDevOverride::applyToSdk(__DIR__ . '/fixtures/brevo-sdk/missing-autoload.php'));
    }

    /**
     * A vendor/ that loads but lacks the SDK leaves dev mail on the real Brevo
     * API, so the failure must reach the log, not only the return value.
     */
    #[RunInSeparateProcess]
    public function testApplyToSdk_DevelopmentWithAutoloadMissingSdk_ReturnsFalseAndLogs(): void
    {
        global $mockLogEntries;
        // logFailure() always writes error_log(). Keep it out of the PHPUnit output.
        $errorLog = tempnam(sys_get_temp_dir(), 'brevo_dev_override_log_');
        ini_set('error_log', (string) $errorLog);
        define('US_ENVIRONMENT', 'development');
        $_ENV['BREVO_API_HOST'] = self::MOCK_HOST;

        $this->assertFalse(BrevoDevOverride::applyToSdk(__DIR__ . '/fixtures/brevo-sdk/autoload-without-sdk.php'));
        @unlink((string) $errorLog);

        $this->assertCount(1, $mockLogEntries ?? []);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_ERROR, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('real Brevo API', $mockLogEntries[0]['message']);
    }

    /**
     * UserSpice logger() reports no failed database write, so the failure must
     * also reach the PHP error log.
     */
    #[RunInSeparateProcess]
    public function testApplyToSdk_DevelopmentWithAutoloadMissingSdk_AlsoWritesPhpErrorLog(): void
    {
        $errorLog = tempnam(sys_get_temp_dir(), 'brevo_dev_override_log_');
        ini_set('error_log', (string) $errorLog);
        define('US_ENVIRONMENT', 'development');
        $_ENV['BREVO_API_HOST'] = self::MOCK_HOST;

        $this->assertFalse(BrevoDevOverride::applyToSdk(__DIR__ . '/fixtures/brevo-sdk/autoload-without-sdk.php'));

        $logged = (string) file_get_contents((string) $errorLog);
        @unlink((string) $errorLog);
        $this->assertStringContainsString('goes to the real Brevo API', $logged);
    }

    #[RunInSeparateProcess]
    public function testApplyToSdk_DevelopmentWithFixtureSdk_SetsSharedHostThatSurvivesSetApiKey(): void
    {
        define('US_ENVIRONMENT', 'development');
        $_ENV['BREVO_API_HOST'] = self::MOCK_HOST;

        $this->assertTrue(BrevoDevOverride::applyToSdk(self::FIXTURE_AUTOLOAD));
        $this->assertSame(self::MOCK_HOST, \Brevo\Client\Configuration::getDefaultConfiguration()->getHost());

        // Same call the sendinblue plugin makes before each send.
        \Brevo\Client\Configuration::getDefaultConfiguration()->setApiKey('api-key', 'x');
        $this->assertSame(self::MOCK_HOST, \Brevo\Client\Configuration::getDefaultConfiguration()->getHost());
    }

    #[RunInSeparateProcess]
    public function testApplyToSdk_DevelopmentWithRealSdk_SetsSharedHostThatSurvivesSetApiKey(): void
    {
        if (!is_file(self::REAL_SDK_AUTOLOAD)) {
            $this->markTestSkipped('sendinblue plugin vendor/ is not installed on this checkout.');
        }

        define('US_ENVIRONMENT', 'development');
        $_ENV['BREVO_API_HOST'] = self::MOCK_HOST;

        $this->assertTrue(BrevoDevOverride::applyToSdk(self::REAL_SDK_AUTOLOAD));

        \Brevo\Client\Configuration::getDefaultConfiguration()->setApiKey('api-key', 'x');
        $this->assertSame(self::MOCK_HOST, \Brevo\Client\Configuration::getDefaultConfiguration()->getHost());
    }

    /**
     * The loader include is the one project call (#2184). Without it, or with
     * it above the development check, dev mail reaches the real Brevo API.
     *
     * @return string The temp site root, holding a plugin vendor/autoload.php
     *                that loads the fixture SDK
     */
    private function makeSiteRootWithFixtureSdk(): string
    {
        $root = sys_get_temp_dir() . '/brevo_dev_override_test_' . uniqid('', true);
        $vendorDir = $root . '/usersc/plugins/sendinblue/vendor';
        mkdir($vendorDir, 0777, true);
        file_put_contents(
            $vendorDir . '/autoload.php',
            '<?php require_once ' . var_export(self::FIXTURE_AUTOLOAD, true) . ';'
        );

        // The test runs in a child process; remove the tree when it exits.
        register_shutdown_function(static function () use ($root, $vendorDir): void {
            @unlink($vendorDir . '/autoload.php');
            foreach ([$vendorDir, dirname($vendorDir), dirname($vendorDir, 2), dirname($vendorDir, 3), $root] as $dir) {
                @rmdir($dir);
            }
        });

        return $root;
    }

    #[RunInSeparateProcess]
    public function testLoaderInclude_Development_SetsSdkHost(): void
    {
        $_ENV['US_ENVIRONMENT'] = 'development';
        $_ENV['BREVO_API_HOST'] = self::MOCK_HOST;
        $abs_us_root = $this->makeSiteRootWithFixtureSdk() . '/';
        $us_url_root = '';
        $rateLimits = [];

        require dirname(__DIR__, 4) . '/usersc/includes/rate_limits_dev_override.php';

        $this->assertTrue(class_exists(\Brevo\Client\Configuration::class, false));
        $this->assertSame(self::MOCK_HOST, \Brevo\Client\Configuration::getDefaultConfiguration()->getHost());
    }

    #[RunInSeparateProcess]
    public function testLoaderInclude_Production_DoesNotLoadSdk(): void
    {
        $_ENV['US_ENVIRONMENT'] = 'production';
        $_ENV['BREVO_API_HOST'] = self::MOCK_HOST;
        $abs_us_root = $this->makeSiteRootWithFixtureSdk() . '/';
        $us_url_root = '';
        $rateLimits = [];

        require dirname(__DIR__, 4) . '/usersc/includes/rate_limits_dev_override.php';

        $this->assertFalse(class_exists(\Brevo\Client\Configuration::class, false));
    }
}
