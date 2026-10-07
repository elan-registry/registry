<?php

declare(strict_types=1);

use ElanRegistry\ChassisValidator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Issue #1304: the character allowlist runs before the override branch, so
 * $allowOverride=true cannot store a chassis with markup or control characters.
 */
#[Group('fast')]
#[Group('unit')]
#[Group('security')]
#[Group('chassis')]
final class ChassisValidatorXssTest extends TestCase
{
    private const MODEL  = 'S1|Standard|Roadster';
    private const YEAR   = 1966;

    /**
     * @return array{valid: bool, chassis: string, error_reason: string, format_type: string, override_used: bool}
     */
    private function validate(
        string $chassis,
        int    $year          = self::YEAR,
        string $model         = self::MODEL,
        bool   $allowOverride = false
    ): array {
        return (new ChassisValidator())->validate($chassis, $year, $model, $allowOverride);
    }

    public function testAngleBracketsRejectedWithOverrideFalse(): void
    {
        $result = $this->validate('<CHASSIS>', allowOverride: false);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('invalid characters', $result['error_reason']);
    }

    public function testAngleBracketsRejectedWithOverrideTrue(): void
    {
        $result = $this->validate('<CHASSIS>', allowOverride: true);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('invalid characters', $result['error_reason']);
    }

    public function testScriptTagRejectedWithOverride(): void
    {
        $result = $this->validate('<script>alert(1)</script>', allowOverride: true);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('invalid characters', $result['error_reason']);
    }

    /**
     * trim() strips leading and trailing NUL bytes, so the NUL is mid-string.
     */
    public function testNulByteRejectedWithOverride(): void
    {
        $result = $this->validate("CHAS\x00SIS", allowOverride: true);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('invalid characters', $result['error_reason']);
    }

    public function testSpaceRejectedWithOverride(): void
    {
        $result = $this->validate('12345 6789', allowOverride: true);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('invalid characters', $result['error_reason']);
    }

    public function testValidSlashFormatPassesAllowlist(): void
    {
        $result = $this->validate('26/0001', allowOverride: true);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['override_used']);
    }

    /**
     * With a non-Race model the format check fails, so override isolates the allowlist.
     */
    public function testValidHyphenFormatPassesAllowlist(): void
    {
        $result = $this->validate('26-R-01', year: 1963, allowOverride: true);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['override_used']);
        $this->assertStringNotContainsString('invalid characters', $result['error_reason']);
    }

    public function testValidAlphanumericPassesAllowlist(): void
    {
        $result = $this->validate('11120R0001A', year: 1966, allowOverride: true);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['override_used']);
        $this->assertStringNotContainsString('invalid characters', $result['error_reason']);
    }

    public function testMalformedModelStringFailsValidation(): void
    {
        $result = (new \ElanRegistry\ChassisValidator())->validate('1234', 1966, 'MALFORMED', false);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Invalid model format', $result['error_reason']);
    }

    /**
     * The Race variant is what activates the hyphen race-car format.
     */
    public function testValidHyphenFormatPassesWithoutOverride(): void
    {
        $result = $this->validate('26-R-01', year: 1963, model: '26|Race|Roadster', allowOverride: false);

        $this->assertTrue($result['valid']);
        $this->assertFalse($result['override_used']);
    }

    /**
     * No recognized format uses a slash, but the allowlist must not be what rejects it.
     */
    public function testSlashFormatFailsWithoutOverride(): void
    {
        $result = $this->validate('26/0001', allowOverride: false);

        $this->assertFalse($result['valid']);
        $this->assertStringNotContainsString('invalid characters', $result['error_reason']);
    }
}
