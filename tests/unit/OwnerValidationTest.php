<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\OwnerValidationException;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Owner's pure field-validation logic: validateAndSanitizeFields(),
 * validateRequiredFields(), extractUserFields(), qualityScoreFromRow(), and
 * update()'s empty-payload guard. None of these reach the database.
 *
 * Moved from tests/integration/OwnerIntegrationTest.php (#2161); the tests that
 * genuinely read or write the database stay there.
 */
#[Group('fast')]
#[Group('unit')]
final class OwnerValidationTest extends TestCase
{
    /**
     * A DatabaseInterface double is required: Owner's constructor otherwise
     * falls back to dbi(), which is undefined in the unit tier
     * (custom_functions.php — where dbi() lives — is never loaded there).
     */
    private function makeOwner(): Owner
    {
        return new Owner(null, $this->createStub(DatabaseInterface::class));
    }

    /**
     * @param array<string, string|null> $fields Null values are intentional —
     *        testLatNullPassesThrough() asserts null coordinates are dropped.
     * @return array<string, mixed>
     */
    private function callValidateAndSanitize(array $fields, bool $requireAll = false): array
    {
        $owner = $this->makeOwner();
        $method = new \ReflectionMethod($owner, 'validateAndSanitizeFields');
        /** @var array<string, mixed> */
        return $method->invoke($owner, $fields, $requireAll);
    }

    public function testWebsiteHttpIsAccepted(): void
    {
        $result = $this->callValidateAndSanitize(['website' => 'http://example.com']);
        $this->assertEquals('http://example.com', $result['website']);
    }

    public function testWebsiteHttpsIsAccepted(): void
    {
        $result = $this->callValidateAndSanitize(['website' => 'https://example.com']);
        $this->assertEquals('https://example.com', $result['website']);
    }

    public function testWebsiteJavascriptSchemeIsRejected(): void
    {
        // javascript:alert(1) fails FILTER_VALIDATE_URL — caught by the URL format check
        // Regression for #927: getUserMessage() must return the specific URL message,
        // not the generic 'The owner information provided is invalid...' default.
        try {
            $this->callValidateAndSanitize(['website' => 'javascript:alert(1)']);
            $this->fail('Expected OwnerValidationException was not thrown');
        } catch (OwnerValidationException $e) {
            $this->assertMatchesRegularExpression('/http.*https/i', $e->getMessage());
            $this->assertEquals(
                'Website URL must start with http:// or https:// (e.g. https://example.com)',
                $e->getUserMessage(),
                'getUserMessage() must return the specific URL message, not the generic default'
            );
            $this->assertNotEquals(
                'The owner information provided is invalid. Please check your input.',
                $e->getUserMessage()
            );
        }
    }

    public function testWebsiteJavascriptVoidSchemeIsRejected(): void
    {
        // javascript:void(0) passes FILTER_VALIDATE_URL on some PHP versions — blocked by scheme whitelist
        // Regression for #927: getUserMessage() must return the specific URL message.
        // The exact throw path (URL format vs scheme whitelist) may vary by PHP version,
        // so we assert on the shared pattern rather than the exact message string.
        try {
            $this->callValidateAndSanitize(['website' => 'javascript:void(0)']);
            $this->fail('Expected OwnerValidationException was not thrown');
        } catch (OwnerValidationException $e) {
            $this->assertMatchesRegularExpression('/http.*https/i', $e->getMessage());
            $this->assertMatchesRegularExpression(
                '/http.*https/i',
                $e->getUserMessage(),
                'getUserMessage() must return a specific URL message, not the generic default'
            );
            $this->assertNotEquals(
                'The owner information provided is invalid. Please check your input.',
                $e->getUserMessage()
            );
        }
    }

    public function testWebsiteDataSchemeIsRejected(): void
    {
        // data: fails FILTER_VALIDATE_URL — caught by the URL format check
        // Regression for #927: getUserMessage() must return the specific URL message.
        try {
            $this->callValidateAndSanitize(['website' => 'data:text/html,test']);
            $this->fail('Expected OwnerValidationException was not thrown');
        } catch (OwnerValidationException $e) {
            $this->assertMatchesRegularExpression('/http.*https/i', $e->getMessage());
            $this->assertEquals(
                'Website URL must start with http:// or https:// (e.g. https://example.com)',
                $e->getUserMessage(),
                'getUserMessage() must return the specific URL message, not the generic default'
            );
            $this->assertNotEquals(
                'The owner information provided is invalid. Please check your input.',
                $e->getUserMessage()
            );
        }
    }

    public function testWebsiteFtpSchemeIsRejected(): void
    {
        // ftp: passes FILTER_VALIDATE_URL but is blocked by the scheme whitelist
        // Regression for #927: getUserMessage() must return the specific URL message.
        try {
            $this->callValidateAndSanitize(['website' => 'ftp://example.com/file.txt']);
            $this->fail('Expected OwnerValidationException was not thrown');
        } catch (OwnerValidationException $e) {
            $this->assertEquals(
                'Website URL must use http:// or https:// — other protocols are not allowed',
                $e->getMessage()
            );
            $this->assertEquals(
                'Website URL must use http:// or https:// — other protocols are not allowed',
                $e->getUserMessage(),
                'getUserMessage() must return the specific URL message, not the generic default'
            );
            $this->assertNotEquals(
                'The owner information provided is invalid. Please check your input.',
                $e->getUserMessage()
            );
        }
    }

    public function testWebsiteSchemelessDomainIsRejected(): void
    {
        // Regression for #927: getUserMessage() must not return the generic default.
        try {
            $this->callValidateAndSanitize(['website' => 'example.com']);
            $this->fail('Expected OwnerValidationException was not thrown');
        } catch (OwnerValidationException $e) {
            $this->assertEquals(
                'Website URL must start with http:// or https:// (e.g. https://example.com)',
                $e->getUserMessage(),
                'getUserMessage() must return the specific URL message, not the generic default'
            );
        }
    }

    public function testWebsiteEmptyIsAccepted(): void
    {
        $result = $this->callValidateAndSanitize(['website' => '']);
        $this->assertArrayNotHasKey('website', $result);
    }

    public function testLatNullPassesThrough(): void
    {
        $result = $this->callValidateAndSanitize(['lat' => null]);
        $this->assertArrayNotHasKey('lat', $result);
    }

    public function testLatEmptyStringPassesThrough(): void
    {
        $result = $this->callValidateAndSanitize(['lat' => '']);
        $this->assertArrayNotHasKey('lat', $result);
    }

    public function testLatZeroIsAccepted(): void
    {
        // Zero is a valid equator coordinate — !empty() would incorrectly reject it
        $result = $this->callValidateAndSanitize(['lat' => '0']);
        $this->assertSame(0.0, $result['lat']);
    }

    public function testLatBoundaryAccepted(): void
    {
        $result = $this->callValidateAndSanitize(['lat' => '90']);
        $this->assertSame(90.0, $result['lat']);
    }

    public function testLatOutOfRangeRejected(): void
    {
        $this->expectException(OwnerValidationException::class);
        $this->callValidateAndSanitize(['lat' => '91']);
    }

    public function testLatNonNumericRejected(): void
    {
        $this->expectException(OwnerValidationException::class);
        $this->callValidateAndSanitize(['lat' => 'north']);
    }

    public function testLatIsCastToFloat(): void
    {
        $result = $this->callValidateAndSanitize(['lat' => '51.5']);
        $this->assertSame(51.5, $result['lat']);
    }

    public function testLonBoundaryAccepted(): void
    {
        $result = $this->callValidateAndSanitize(['lon' => '180']);
        $this->assertSame(180.0, $result['lon']);
    }

    public function testLonOutOfRangeRejected(): void
    {
        $this->expectException(OwnerValidationException::class);
        $this->callValidateAndSanitize(['lon' => '181']);
    }

    public function testLonZeroIsAccepted(): void
    {
        // Zero is a valid prime-meridian coordinate — !empty() would incorrectly reject it
        $result = $this->callValidateAndSanitize(['lon' => '0']);
        $this->assertSame(0.0, $result['lon']);
    }

    public function testQualityScoreCountsZeroCoordinates(): void
    {
        // qualityScoreFromRow() must not treat 0.0 as "missing" — regression guard for !empty() fix
        $row = (object)[
            'fname' => 'Alice', 'lname' => 'Smith', 'email' => 'a@b.com',
            'city' => 'X', 'state' => 'Y', 'country' => 'Z',
            'lat' => '0', 'lon' => '0',
        ];
        $this->assertSame(100.0, Owner::qualityScoreFromRow($row));
    }

    // -------------------------------------------------------------------------
    // city/state/country length cap raised from 50 → 100 (issue #1233 fix 1)
    // -------------------------------------------------------------------------

    public function testCityAccepts75CharValue(): void
    {
        // 75-char value is well within the 100-char cap — must pass through unchanged
        $city = str_repeat('x', 75);
        $result = $this->callValidateAndSanitize(['city' => $city]);
        $this->assertSame($city, $result['city']);
    }

    public function testCityAccepts100CharValue(): void
    {
        // Exactly at the new cap of 100 chars — must pass through unchanged
        $city = str_repeat('x', 100);
        $result = $this->callValidateAndSanitize(['city' => $city]);
        $this->assertSame(100, strlen($result['city']));
    }

    public function testCityTruncatesAt100Chars(): void
    {
        // 101-char value exceeds the cap and must be truncated to exactly 100 chars
        $city = str_repeat('x', 101);
        $result = $this->callValidateAndSanitize(['city' => $city]);
        $this->assertSame(100, strlen($result['city']));
    }

    // -------------------------------------------------------------------------
    // validateRequiredFields() '0' acceptance (issue #1233 fix 3)
    // -------------------------------------------------------------------------

    /**
     * @param array<string, string> $fields
     * @param list<string>          $required
     */
    private function callValidateRequiredFields(array $fields, array $required): void
    {
        $owner = $this->makeOwner();
        $method = new \ReflectionMethod($owner, 'validateRequiredFields');
        $method->invoke($owner, $fields, $required);
    }

    public function testRequiredFieldAcceptsZeroString(): void
    {
        // '0' is a legitimate value — trim((string)'0') === '' is false, so no exception
        $this->expectNotToPerformAssertions();
        $this->callValidateRequiredFields(
            ['fname' => '0', 'lname' => '0', 'email' => 'test@example.com'],
            ['fname', 'lname', 'email']
        );
    }

    public function testRequiredFieldRejectsEmptyString(): void
    {
        $this->expectException(OwnerValidationException::class);
        $this->callValidateRequiredFields(
            ['fname' => '', 'lname' => 'Smith', 'email' => 'test@example.com'],
            ['fname', 'lname', 'email']
        );
    }

    public function testRequiredFieldRejectsWhitespaceOnly(): void
    {
        $this->expectException(OwnerValidationException::class);
        $this->callValidateRequiredFields(
            ['fname' => '   ', 'lname' => 'Smith', 'email' => 'test@example.com'],
            ['fname', 'lname', 'email']
        );
    }

    // -------------------------------------------------------------------------
    // Privilege-escalation guard (#1232)
    // active/permissions must never pass through validateAndSanitizeFields or
    // extractUserFields — they are not profile fields and must not be writable
    // via the general Owner::update() path.
    // -------------------------------------------------------------------------

    public function testActiveFieldIsDroppedByValidation(): void
    {
        $result = $this->callValidateAndSanitize(['active' => '0']);
        $this->assertArrayNotHasKey('active', $result,
            'active must be silently dropped by validateAndSanitizeFields');
    }

    public function testPermissionsFieldIsDroppedByValidation(): void
    {
        $result = $this->callValidateAndSanitize(['permissions' => '3']);
        $this->assertArrayNotHasKey('permissions', $result,
            'permissions must be silently dropped by validateAndSanitizeFields');
    }

    public function testUnknownFieldIsDroppedByValidation(): void
    {
        $result = $this->callValidateAndSanitize(['totally_unknown_field' => 'injected']);
        $this->assertArrayNotHasKey('totally_unknown_field', $result,
            'Unknown fields must be silently dropped by the default case');
    }

    public function testUsernameFieldIsDroppedByValidation(): void
    {
        $result = $this->callValidateAndSanitize(['username' => 'hacker']);
        $this->assertArrayNotHasKey('username', $result,
            'username must be silently dropped by validateAndSanitizeFields');
    }

    /**
     * update() with only 'id' and no other fields must throw OwnerValidationException.
     *
     * This guard is newly reachable without a 'csrf' field in the array (previously
     * the array was never empty because 'csrf' was always present).
     */
    public function testUpdateWithOnlyIdThrowsValidationException(): void
    {
        $this->expectException(OwnerValidationException::class);
        $this->makeOwner()->update(['id' => 1]);
    }

    public function testExtractUserFieldsExcludesDangerousColumns(): void
    {
        $owner = $this->makeOwner();
        $method = new \ReflectionMethod($owner, 'extractUserFields');
        /** @var array<string, mixed> */
        $result = $method->invoke($owner, [
            'fname'       => 'Alice',
            'active'      => '1',
            'permissions' => '3',
            'username'    => 'hacker',
            'password'    => 'hash',
        ]);
        $this->assertArrayHasKey('fname', $result, 'fname must be allowed through extractUserFields');
        $this->assertArrayNotHasKey('active', $result,
            'active must be excluded from the extractUserFields allowlist');
        $this->assertArrayNotHasKey('permissions', $result,
            'permissions must be excluded from the extractUserFields allowlist');
        $this->assertArrayNotHasKey('username', $result,
            'username must be excluded from the extractUserFields allowlist');
    }
}
