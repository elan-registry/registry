<?php

declare(strict_types=1);

use ElanRegistry\Email\BrevoDevOverride;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BrevoDevOverride::hostOverride() (#2127).
 *
 * Pure static guard — no I/O, no collaborators. Covers the two-part gate
 * that keeps this dev-only override from ever affecting production/staging:
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
 * @see usersc/classes/Email/BrevoDevOverride.php
 * @see https://github.com/elan-registry/registry/issues/2127
 */
#[Group('fast')]
#[Group('unit')]
final class BrevoDevOverrideTest extends TestCase
{
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
}
