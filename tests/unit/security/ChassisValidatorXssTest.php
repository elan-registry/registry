<?php

declare(strict_types=1);

use ElanRegistry\ChassisValidator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression tests for the XSS allowlist guard in ChassisValidator.
 *
 * Issue #1304 introduced a character-allowlist check that runs before the
 * override branch, ensuring that no chassis value containing angle brackets,
 * null bytes, spaces, or other characters outside [0-9A-Za-z/\-] can be
 * stored — even when $allowOverride=true bypasses format validation.
 *
 * Pattern under test (ChassisValidator::validate(), added in #1304):
 *
 *   if (!preg_match('/^[0-9A-Za-z\/\-]+$/', $this->result['chassis'])) {
 *       $this->result['error_reason'] = 'Chassis number contains invalid characters';
 *       return $this->result;
 *   }
 *
 * Because this guard executes before the override branch, $allowOverride=true
 * cannot bypass it. Tests 1–5 confirm rejection. Tests 6–8 confirm that
 * legitimate Lotus Elan formats still pass through the allowlist unharmed.
 *
 * @see usersc/classes/ChassisValidator.php
 */
#[Group('fast')]
#[Group('unit')]
#[Group('security')]
#[Group('chassis')]
final class ChassisValidatorXssTest extends TestCase
{
    // Standard test fixtures used by most cases
    private const MODEL  = 'S1|Standard|Roadster';
    private const YEAR   = 1966;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Convenience wrapper: construct a fresh validator and call validate().
     *
     * @param string $chassis
     * @param int    $year
     * @param string $model
     * @param bool   $allowOverride
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

    // -------------------------------------------------------------------------
    // XSS / injection payloads must be rejected regardless of override flag
    // -------------------------------------------------------------------------

    /**
     * Angle-bracket payload is rejected when override is disabled.
     *
     * The string "<CHASSIS>" contains "<" and ">" which are outside the
     * allowlist. Without override the normal path would also reject it, but
     * the test verifies the allowlist fires first and sets the right reason.
     */
    public function testAngleBracketsRejectedWithOverrideFalse(): void
    {
        $result = $this->validate('<CHASSIS>', allowOverride: false);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('invalid characters', $result['error_reason']);
    }

    /**
     * Angle-bracket payload is rejected even when override is enabled.
     *
     * The override branch sets valid=true only when the chassis passes the
     * allowlist. Because the allowlist guard returns early, the override
     * branch is never reached.
     */
    public function testAngleBracketsRejectedWithOverrideTrue(): void
    {
        $result = $this->validate('<CHASSIS>', allowOverride: true);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('invalid characters', $result['error_reason']);
    }

    /**
     * A complete <script> XSS payload is rejected even with override=true.
     *
     * Both "<" and ">" are outside the allowlist. This confirms that the most
     * common stored-XSS vector targeting DataTables cannot be saved.
     */
    public function testScriptTagRejectedWithOverride(): void
    {
        $result = $this->validate('<script>alert(1)</script>', allowOverride: true);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('invalid characters', $result['error_reason']);
    }

    /**
     * An embedded (mid-string) null byte is rejected even with override=true.
     *
     * Null bytes (\x00) are not in [0-9A-Za-z/\-] and are outside the
     * allowlist. They can cause truncation bugs in C-string APIs and are a
     * recognised injection vector.
     *
     * Note: PHP's trim() strips leading and trailing null bytes (they are in
     * its default character set), so a leading or trailing \x00 is removed
     * before the allowlist sees it. This test uses a mid-string null
     * ("CHAS\x00SIS") because trim() does not remove interior characters.
     * A mid-string null is the realistic vector for string-length confusion
     * exploits.
     */
    public function testNulByteRejectedWithOverride(): void
    {
        $result = $this->validate("CHAS\x00SIS", allowOverride: true);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('invalid characters', $result['error_reason']);
    }

    /**
     * An embedded space is rejected even with override=true.
     *
     * Space (ASCII 0x20) is not in the allowlist. This prevents payloads like
     * "1234 <img>" from slipping through as a two-word override chassis.
     */
    public function testSpaceRejectedWithOverride(): void
    {
        $result = $this->validate('12345 6789', allowOverride: true);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('invalid characters', $result['error_reason']);
    }

    // -------------------------------------------------------------------------
    // Legitimate Lotus Elan chassis formats must still pass the allowlist
    // -------------------------------------------------------------------------

    /**
     * The slash-delimited format "26/0001" passes the allowlist and reaches
     * the override branch.
     *
     * "26/0001" contains only digits and a forward-slash — all in the
     * allowlist. It does not match any known production format for year=1966,
     * so the format validator fails, but with $allowOverride=true the override
     * branch sets valid=true and override_used=true. Confirming both means the
     * allowlist did not block the chassis and the override path was reached.
     */
    public function testValidSlashFormatPassesAllowlist(): void
    {
        $result = $this->validate('26/0001', allowOverride: true);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['override_used']);
    }

    /**
     * The hyphen-delimited 1963 race-car format "26-R-01" passes the
     * allowlist and validates normally without requiring override.
     *
     * "26-R-01" contains only digits, uppercase letters, and hyphens — all
     * in the allowlist. With $year=1963 and variant="Standard" (non-Race), the
     * production-car path is taken; the format won't match the 4-digit pre-1970
     * rule and will fail format validation. That is fine — this test only
     * verifies that the allowlist itself does not block the chassis. It does
     * that by asserting the error reason does NOT say "invalid characters".
     *
     * Note: the "26-R-01" race pattern only activates when the model variant
     * contains "Race". With model "S1|Standard|Roadster", the production path
     * is taken, and the format will fail for other reasons. The allowlist
     * concern is separate from format correctness.
     */
    public function testValidHyphenFormatPassesAllowlist(): void
    {
        // Use override to bypass format failure so the allowlist decision is
        // the only thing that can make valid=false.
        $result = $this->validate('26-R-01', year: 1963, allowOverride: true);

        // The allowlist passes hyphens, digits, and letters — override must fire
        $this->assertTrue($result['valid']);
        $this->assertTrue($result['override_used']);
        // The allowlist did not fire
        $this->assertStringNotContainsString('invalid characters', $result['error_reason']);
    }

    /**
     * The alphanumeric chassis "11120R0001A" passes the allowlist.
     *
     * "11120R0001A" contains only digits and uppercase letters — all in the
     * allowlist. The format validator may accept or reject it depending on
     * year/model rules; that is irrelevant here. The test asserts only that
     * the allowlist check does NOT fire (error_reason must not say
     * "invalid characters"), confirming the guard does not over-block
     * legitimate alphanumeric chassis values.
     */
    public function testValidAlphanumericPassesAllowlist(): void
    {
        $result = $this->validate('11120R0001A', year: 1966, allowOverride: true);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['override_used']);
        $this->assertStringNotContainsString('invalid characters', $result['error_reason']);
    }
}
