<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\OwnerValidationException;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private const URL_START_MESSAGE = 'Website URL must start with http:// or https:// (e.g. https://example.com)';
    private const URL_SCHEME_MESSAGE = 'Website URL must use http:// or https:// — other protocols are not allowed';
    private const GENERIC_MESSAGE = 'The owner information provided is invalid. Please check your input.';

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
     * @param array<string, mixed> $fields Mixed values are intentional —
     *        droppedCoordinateProvider() asserts null coordinates are dropped.
     * @return array<string, mixed>
     */
    private function callValidateAndSanitize(array $fields, bool $requireAll = false): array
    {
        $owner = $this->makeOwner();
        $method = new \ReflectionMethod($owner, 'validateAndSanitizeFields');
        /** @var array<string, mixed> */
        return $method->invoke($owner, $fields, $requireAll);
    }

    private static function exactPattern(string $message): string
    {
        return '/^' . preg_quote($message, '/') . '$/u';
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedWebsiteProvider(): array
    {
        return [
            'http' => ['http://example.com'],
            'https' => ['https://example.com'],
        ];
    }

    #[DataProvider('acceptedWebsiteProvider')]
    public function testWebsiteWithAllowedSchemeIsAccepted(string $website): void
    {
        $result = $this->callValidateAndSanitize(['website' => $website]);
        $this->assertSame($website, $result['website']);
    }

    /**
     * Regression for #927: getUserMessage() must return the specific URL
     * message, not the generic default. A null message pattern skips the
     * getMessage() check.
     *
     * @return array<string, array{string, ?string, string}>
     */
    public static function rejectedWebsiteProvider(): array
    {
        return [
            // Fails FILTER_VALIDATE_URL, so the URL format check catches it.
            'javascript alert' => ['javascript:alert(1)', '/http.*https/i', self::exactPattern(self::URL_START_MESSAGE)],
            // Passes FILTER_VALIDATE_URL on some PHP versions, so the throw path
            // (URL format or scheme allowlist) varies. Assert only the shared pattern.
            'javascript void' => ['javascript:void(0)', '/http.*https/i', '/http.*https/i'],
            // Fails FILTER_VALIDATE_URL, so the URL format check catches it.
            'data scheme' => ['data:text/html,test', '/http.*https/i', self::exactPattern(self::URL_START_MESSAGE)],
            // Passes FILTER_VALIDATE_URL, so only the scheme allowlist blocks it.
            'ftp scheme' => [
                'ftp://example.com/file.txt',
                self::exactPattern(self::URL_SCHEME_MESSAGE),
                self::exactPattern(self::URL_SCHEME_MESSAGE),
            ],
            'schemeless domain' => ['example.com', null, self::exactPattern(self::URL_START_MESSAGE)],
        ];
    }

    #[DataProvider('rejectedWebsiteProvider')]
    public function testWebsiteWithBadSchemeIsRejected(
        string $website,
        ?string $messagePattern,
        string $userMessagePattern
    ): void {
        try {
            $this->callValidateAndSanitize(['website' => $website]);
            $this->fail('Expected OwnerValidationException was not thrown');
        } catch (OwnerValidationException $e) {
            if ($messagePattern !== null) {
                $this->assertMatchesRegularExpression($messagePattern, $e->getMessage());
            }
            $this->assertMatchesRegularExpression(
                $userMessagePattern,
                $e->getUserMessage(),
                'getUserMessage() must return the specific URL message, not the generic default'
            );
            $this->assertNotEquals(self::GENERIC_MESSAGE, $e->getUserMessage());
        }
    }

    public function testWebsiteEmptyIsAccepted(): void
    {
        $result = $this->callValidateAndSanitize(['website' => '']);
        $this->assertArrayNotHasKey('website', $result);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function droppedCoordinateProvider(): array
    {
        return [
            'lat null' => [null],
            'lat empty string' => [''],
        ];
    }

    #[DataProvider('droppedCoordinateProvider')]
    public function testEmptyCoordinateIsDropped(mixed $value): void
    {
        $result = $this->callValidateAndSanitize(['lat' => $value]);
        $this->assertArrayNotHasKey('lat', $result);
    }

    /**
     * @return array<string, array{string, string, float}>
     */
    public static function acceptedCoordinateProvider(): array
    {
        return [
            // Zero is a valid coordinate. !empty() would reject it.
            'lat zero equator' => ['lat', '0', 0.0],
            'lat upper boundary' => ['lat', '90', 90.0],
            'lat decimal cast to float' => ['lat', '51.5', 51.5],
            'lon upper boundary' => ['lon', '180', 180.0],
            'lon zero prime meridian' => ['lon', '0', 0.0],
        ];
    }

    #[DataProvider('acceptedCoordinateProvider')]
    public function testCoordinateIsAccepted(string $field, string $value, float $expected): void
    {
        $result = $this->callValidateAndSanitize([$field => $value]);
        $this->assertSame($expected, $result[$field]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedCoordinateProvider(): array
    {
        return [
            'lat above 90' => ['lat', '91'],
            'lat non-numeric' => ['lat', 'north'],
            'lon above 180' => ['lon', '181'],
        ];
    }

    #[DataProvider('rejectedCoordinateProvider')]
    public function testCoordinateIsRejected(string $field, string $value): void
    {
        $this->expectException(OwnerValidationException::class);
        $this->callValidateAndSanitize([$field => $value]);
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
