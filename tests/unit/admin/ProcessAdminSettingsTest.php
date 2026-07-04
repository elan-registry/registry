<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for app/api/admin/process-settings.php field-allowlist logic.
 *
 * Pins three behavioral contracts via source inspection (the file requires a
 * full UserSpice bootstrap and cannot be included in unit tests):
 *
 *   1. Unknown field names are rejected with 400.
 *   2. Tables other than 'settings' are rejected with 400.
 *   3. Non-numeric values for numeric fields are rejected with 400.
 *
 * A fourth test acts as a mandatory review gate: it asserts the exact set of
 * keys in FIELD_TYPES so that any future expansion triggers a test failure and
 * a forced code review (a dangerous column such as `admin_override` would
 * otherwise sail through silently).
 *
 * Pattern: see GetDataTablesFindCarByChassisTest.php.
 */
#[Group('fast')]
#[Group('unit')]
#[Group('admin')]
final class ProcessAdminSettingsTest extends TestCase
{
    private string $endpointPath;
    private string $endpointContent;

    protected function setUp(): void
    {
        $this->endpointPath    = dirname(__DIR__, 3) . '/app/api/admin/process-settings.php';
        $this->endpointContent = file_get_contents($this->endpointPath);
    }

    public function testEndpointFileExists(): void
    {
        $this->assertFileExists($this->endpointPath);
    }

    /**
     * Unknown field must be rejected with 400 before any DB write occurs.
     *
     * Guard: !array_key_exists($field, FIELD_TYPES) → ApiResponse::error(..., 400)
     */
    public function testUnknownFieldIsRejectedWith400(): void
    {
        $this->assertStringContainsString(
            'array_key_exists($field, FIELD_TYPES)',
            $this->endpointContent,
            'Field allowlist check must use array_key_exists($field, FIELD_TYPES)'
        );

        $this->assertMatchesRegularExpression(
            '/ApiResponse::error\([^)]*400/',
            $this->endpointContent,
            'process-settings.php must return a 400 response for allowlist violations'
        );
    }

    /**
     * Writing to any table other than 'settings' must be rejected with 400.
     *
     * Guard: $table !== 'settings' → ApiResponse::error(..., 400)
     */
    public function testNonSettingsTableIsRejectedWith400(): void
    {
        $this->assertStringContainsString(
            "\$table !== 'settings'",
            $this->endpointContent,
            "Table guard must use strict inequality: \$table !== 'settings'"
        );

        // Verify a 400 error is returned (shared with the field check above — asserting
        // both guards produce the same status code).
        $this->assertMatchesRegularExpression(
            '/ApiResponse::error\([^)]*400/',
            $this->endpointContent,
            'process-settings.php must return a 400 response for invalid table attempts'
        );
    }

    /**
     * A non-numeric string value for a field typed 'num' must be rejected with 400.
     *
     * Guard: $type === 'num' && !is_numeric($value) → ApiResponse::error(..., 400)
     */
    public function testNonNumericValueForNumericFieldIsRejectedWith400(): void
    {
        $this->assertStringContainsString(
            "\$type === 'num' && !is_numeric(\$value)",
            $this->endpointContent,
            "Numeric-field guard must use \$type === 'num' && !is_numeric(\$value)"
        );

        $this->assertMatchesRegularExpression(
            '/ApiResponse::error\([^)]*400/',
            $this->endpointContent,
            'process-settings.php must return a 400 response for non-numeric values on numeric fields'
        );
    }

    /**
     * FIELD_TYPES key-set regression gate.
     *
     * Asserts the exact set of allowed field names so that any future addition
     * causes a deliberate test update and forces a security review. A dangerous
     * column (e.g., `admin_override`, `is_admin`) added to FIELD_TYPES without
     * review would grant admins write access to an arbitrary column.
     *
     * When adding a new field: update $expectedKeys here and document why the
     * new column is safe to expose.
     */
    public function testFieldTypesKeySetIsExactRegressionGate(): void
    {
        preg_match('/const FIELD_TYPES\s*=\s*\[(.*?)\];/s', $this->endpointContent, $matches);
        $this->assertNotEmpty(
            $matches,
            'FIELD_TYPES constant must be present in process-settings.php'
        );

        preg_match_all("/'([^']+)'\s*=>/", $matches[1], $keyMatches);
        $actualKeys = $keyMatches[1];
        sort($actualKeys);

        // sorted — must match PHP sort() output; keep in alphabetical order when adding keys
        $expectedKeys = [
            'elan_admin_emails',
            'elan_feedback_email',
            'elan_image_dir',
            'elan_image_display_max_size',
            'elan_image_max',
            'elan_image_thumbnail_sizes',
            'elan_image_upload_max_size',
        ];

        $this->assertSame(
            $expectedKeys,
            $actualKeys,
            'FIELD_TYPES key set has changed. Review each new field for write-access safety, '
            . 'then update $expectedKeys in this test to confirm the review was done.'
        );
    }
}
