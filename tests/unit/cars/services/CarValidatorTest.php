<?php

declare(strict_types=1);

use ElanRegistry\Car\CarValidator;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ElanRegistryException;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for CarValidator service class
 */
#[Group('fast')]
final class CarValidatorTest extends TestCase
{
    private CarValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CarValidator();
    }

    // ============================================================
    // validateRequiredFields tests
    // ============================================================

    public function testValidateRequiredFieldsPassesWithAllPresent(): void
    {
        $this->expectNotToPerformAssertions();
        $fields = ['chassis' => 'ABC123', 'model' => 'Elan', 'year' => '1970'];
        $this->validator->validateRequiredFields($fields, ['chassis', 'model', 'year']);
    }

    public function testValidateRequiredFieldsThrowsOnMissingField(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage("Required field 'chassis' is missing or empty");

        $fields = ['model' => 'Elan', 'year' => '1970'];
        $this->validator->validateRequiredFields($fields, ['chassis', 'model', 'year']);
    }

    public function testValidateRequiredFieldsThrowsOnEmptyField(): void
    {
        $this->expectException(CarValidationException::class);

        $fields = ['chassis' => '   ', 'model' => 'Elan', 'year' => '1970'];
        $this->validator->validateRequiredFields($fields, ['chassis', 'model', 'year']);
    }

    public function testValidateRequiredFieldsAcceptsZeroString(): void
    {
        // '0' is a legitimate value — trim((string)'0') !== '', so no exception should be thrown
        $this->expectNotToPerformAssertions();
        $fields = ['chassis' => '0', 'model' => 'S4|FHC|36', 'year' => '1970'];
        $this->validator->validateRequiredFields($fields, ['chassis', 'model', 'year']);
    }

    // ============================================================
    // validateAndSanitizeFields tests
    // ============================================================

    public function testValidateAndSanitizeFieldsSanitizesChassis(): void
    {
        $fields = ['chassis' => '  ABC123  '];
        $result = $this->validator->validateAndSanitizeFields($fields, false);
        $this->assertEquals('ABC123', $result['chassis']);
    }

    public function testValidateAndSanitizeFieldsPreservesSpecialCharsInColor(): void
    {
        $fields = ['color' => "Lagoon Blue & Grey"];
        $result = $this->validator->validateAndSanitizeFields($fields, false);
        $this->assertSame("Lagoon Blue & Grey", $result['color']);
    }

    public function testValidateAndSanitizeFieldsPreservesSpecialCharsInEngine(): void
    {
        $fields = ['engine' => 'Twin-Cam <36>'];
        $result = $this->validator->validateAndSanitizeFields($fields, false);
        $this->assertSame('Twin-Cam <36>', $result['engine']);
    }

    public function testValidateAndSanitizeFieldsRejectShortChassis(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('Chassis number must be at least 3 characters');

        $fields = ['chassis' => 'AB'];
        $this->validator->validateAndSanitizeFields($fields, false);
    }

    public function testValidateAndSanitizeFieldsValidatesYear(): void
    {
        $fields = ['year' => '1970'];
        $result = $this->validator->validateAndSanitizeFields($fields, false);
        $this->assertSame(1970, $result['year']);
    }

    public function testValidateAndSanitizeFieldsRejectsInvalidYear(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('Year must be between 1963 and 1974');

        $fields = ['year' => '2000'];
        $this->validator->validateAndSanitizeFields($fields, false);
    }

    public function testValidateAndSanitizeFieldsValidatesEmail(): void
    {
        $fields = ['email' => 'test@example.com'];
        $result = $this->validator->validateAndSanitizeFields($fields, false);
        $this->assertEquals('test@example.com', $result['email']);
    }

    public function testValidateAndSanitizeFieldsRejectsInvalidEmail(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('Invalid email address format');

        $fields = ['email' => 'not-an-email'];
        $this->validator->validateAndSanitizeFields($fields, false);
    }

    public function testValidateAndSanitizeFieldsValidatesDate(): void
    {
        $fields = ['purchasedate' => '2023-01-15'];
        $result = $this->validator->validateAndSanitizeFields($fields, false);
        $this->assertEquals('2023-01-15', $result['purchasedate']);
    }

    public function testValidateAndSanitizeFieldsRejectsInvalidDate(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('Invalid date format');

        $fields = ['purchasedate' => '15-01-2023'];
        $this->validator->validateAndSanitizeFields($fields, false);
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('dateOutOfRangeProvider')]
    public function testDateOutOfRangeIsRejected(array $fields, string $expectedPattern): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessageMatches($expectedPattern);
        $this->validator->validateAndSanitizeFields($fields, false);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function dateOutOfRangeProvider(): array
    {
        $tomorrow = (new \DateTime('tomorrow'))->format('Y-m-d');

        return [
            'purchasedate before min'  => [['purchasedate' => '1956-12-31'], '/Purchase date must be between/'],
            'purchasedate in future'   => [['purchasedate' => $tomorrow],    '/Purchase date must be between/'],
            'solddate before min'      => [['solddate'     => '1900-01-01'], '/Sold date must be between/'],
            'solddate in future'       => [['solddate'     => $tomorrow],    '/Sold date must be between/'],
        ];
    }

    public function testMinBoundaryDateIsAccepted(): void
    {
        $result = $this->validator->validateAndSanitizeFields(['purchasedate' => '1957-01-01'], false);
        $this->assertEquals('1957-01-01', $result['purchasedate']);
    }

    public function testSolddateMinBoundaryIsAccepted(): void
    {
        $result = $this->validator->validateAndSanitizeFields(['solddate' => '1957-01-01'], false);
        $this->assertEquals('1957-01-01', $result['solddate']);
    }

    public function testTodayIsAccepted(): void
    {
        $today = (new \DateTime('today'))->format('Y-m-d');
        $result = $this->validator->validateAndSanitizeFields(['purchasedate' => $today], false);
        $this->assertEquals($today, $result['purchasedate']);
    }

    public function testSolddateTodayIsAccepted(): void
    {
        $today = (new \DateTime('today'))->format('Y-m-d');
        $result = $this->validator->validateAndSanitizeFields(['solddate' => $today], false);
        $this->assertEquals($today, $result['solddate']);
    }

    public function testBothDatesValidWithCorrectOrderingIsAccepted(): void
    {
        $result = $this->validator->validateAndSanitizeFields([
            'purchasedate' => '1970-03-01',
            'solddate'     => '1985-06-15',
        ], false);
        $this->assertEquals('1970-03-01', $result['purchasedate']);
        $this->assertEquals('1985-06-15', $result['solddate']);
    }

    public function testSolddateBeforePurchasedateIsRejected(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('Sold date cannot be before purchase date');
        $this->validator->validateAndSanitizeFields([
            'purchasedate' => '1980-06-01',
            'solddate'     => '1979-12-31',
        ], false);
    }

    public function testSolddateEqualsPurchasedateIsAccepted(): void
    {
        $result = $this->validator->validateAndSanitizeFields([
            'purchasedate' => '1975-03-15',
            'solddate'     => '1975-03-15',
        ], false);
        $this->assertEquals('1975-03-15', $result['purchasedate']);
        $this->assertEquals('1975-03-15', $result['solddate']);
    }

    public function testValidateAndSanitizeFieldsValidatesCoordinates(): void
    {
        $fields = ['lat' => '51.5', 'lon' => '-0.1'];
        $result = $this->validator->validateAndSanitizeFields($fields, false);
        $this->assertSame(51.5, $result['lat']);
        $this->assertSame(-0.1, $result['lon']);
    }

    public function testValidateAndSanitizeFieldsRejectsInvalidCoordinate(): void
    {
        $this->expectException(CarValidationException::class);

        $fields = ['lat' => '999'];
        $this->validator->validateAndSanitizeFields($fields, false);
    }

    public function testLatZeroIsAccepted(): void
    {
        // Zero is a valid equator coordinate — !empty() would incorrectly reject it
        $result = $this->validator->validateAndSanitizeFields(['lat' => '0'], false);
        $this->assertSame(0.0, $result['lat']);
    }

    public function testLonZeroIsAccepted(): void
    {
        // Zero is a valid prime-meridian coordinate — !empty() would incorrectly reject it
        $result = $this->validator->validateAndSanitizeFields(['lon' => '0'], false);
        $this->assertSame(0.0, $result['lon']);
    }

    public function testLatBoundaryAccepted(): void
    {
        $result = $this->validator->validateAndSanitizeFields(['lat' => '90'], false);
        $this->assertSame(90.0, $result['lat']);
    }

    public function testLatOutOfRangeRejected(): void
    {
        $this->expectException(CarValidationException::class);
        $this->validator->validateAndSanitizeFields(['lat' => '91'], false);
    }

    public function testLonBoundaryAccepted(): void
    {
        $result = $this->validator->validateAndSanitizeFields(['lon' => '180'], false);
        $this->assertSame(180.0, $result['lon']);
    }

    public function testLonOutOfRangeRejected(): void
    {
        $this->expectException(CarValidationException::class);
        $this->validator->validateAndSanitizeFields(['lon' => '181'], false);
    }

    public function testValidateAndSanitizeFieldsRequiresChassisWhenRequireAll(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('Chassis number is required');

        $fields = ['chassis' => ''];
        $this->validator->validateAndSanitizeFields($fields, true);
    }

    public function testValidateAndSanitizeFieldsRequiresModelWhenRequireAll(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('Model is required');

        $fields = ['model' => ''];
        $this->validator->validateAndSanitizeFields($fields, true);
    }

    public function testValidateAndSanitizeFieldsRequiresYearWhenRequireAll(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('Year is required');

        $fields = ['year' => ''];
        $this->validator->validateAndSanitizeFields($fields, true);
    }

    public function testValidateAndSanitizeFieldsRejectsInvalidWebsiteUrl(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('Website URL must start with http:// or https://');

        $fields = ['website' => 'not-a-url'];
        $this->validator->validateAndSanitizeFields($fields, false);
    }

    public function testValidateAndSanitizeFieldsAcceptsValidWebsiteUrl(): void
    {
        $fields = ['website' => 'https://example.com'];
        $result = $this->validator->validateAndSanitizeFields($fields, false);
        $this->assertEquals('https://example.com', $result['website']);
    }

    // ============================================================
    // website field validation — issue #851
    // ============================================================

    /**
     * Empty string for website is accepted — field is optional.
     */
    public function testWebsiteEmptyIsAccepted(): void
    {
        $result = $this->validator->validateAndSanitizeFields(['website' => ''], false);
        // Empty value is not stored in the validated array (matches the `if (!empty($value))` guard)
        $this->assertArrayNotHasKey('website', $result);
    }

    /**
     * A bare domain without a scheme is rejected with the structural-invalidity message.
     */
    public function testWebsiteSchemelessDomainIsRejected(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessageMatches('/http:\/\//');

        $this->validator->validateAndSanitizeFields(['website' => 'example.com'], false);
    }

    /**
     * A relative path is rejected — it has no scheme and fails FILTER_VALIDATE_URL.
     */
    public function testWebsiteRelativePathIsRejected(): void
    {
        $this->expectException(CarValidationException::class);

        $this->validator->validateAndSanitizeFields(['website' => '/path/to/page'], false);
    }

    /**
     * javascript: scheme passes FILTER_VALIDATE_URL on some PHP versions but must be
     * rejected by the explicit scheme allowlist check.
     * The exception message must reference http:// or https://.
     */
    public function testWebsiteJavascriptSchemeIsRejected(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessageMatches('/(http:\/\/|https:\/\/)/');

        $this->validator->validateAndSanitizeFields(['website' => 'javascript:void(0)'], false);
    }

    /**
     * data: URIs are rejected — they are structurally invalid per FILTER_VALIDATE_URL
     * and must not be stored.
     */
    public function testWebsiteDataSchemeIsRejected(): void
    {
        $this->expectException(CarValidationException::class);

        $this->validator->validateAndSanitizeFields(['website' => 'data:text/html,<h1>x</h1>'], false);
    }

    /**
     * A valid http:// URL is accepted and the sanitized URL is returned.
     */
    public function testWebsiteHttpIsAccepted(): void
    {
        $result = $this->validator->validateAndSanitizeFields(['website' => 'http://www.example.com'], false);
        $this->assertArrayHasKey('website', $result);
        $this->assertStringStartsWith('http://', $result['website']);
    }

    /**
     * A valid https:// URL is accepted and the sanitized URL is returned.
     */
    public function testWebsiteHttpsIsAccepted(): void
    {
        $result = $this->validator->validateAndSanitizeFields(['website' => 'https://www.example.com'], false);
        $this->assertArrayHasKey('website', $result);
        $this->assertStringStartsWith('https://', $result['website']);
    }

    /**
     * An ftp:// URL passes FILTER_VALIDATE_URL but is rejected by the scheme allowlist.
     */
    public function testWebsiteFtpSchemeIsRejected(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessageMatches('/must use http:\/\/ or https:\/\//');
        $this->validator->validateAndSanitizeFields(['website' => 'ftp://files.example.com'], false);
    }

    public function testValidateAndSanitizeFieldsRejectsInvalidUserId(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('Invalid user ID');

        $fields = ['user_id' => 'abc'];
        $this->validator->validateAndSanitizeFields($fields, false);
    }

    public function testValidateAndSanitizeFieldsRejectsNegativeUserId(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('Invalid user ID');

        $fields = ['user_id' => '-5'];
        $this->validator->validateAndSanitizeFields($fields, false);
    }

    public function testValidateAndSanitizeFieldsAcceptsValidUserId(): void
    {
        $fields = ['user_id' => '42'];
        $result = $this->validator->validateAndSanitizeFields($fields, false);
        $this->assertSame(42, $result['user_id']);
    }

    public function testValidateAndSanitizeFieldsPassesThroughUnknownFields(): void
    {
        $fields = ['custom_field' => 'value'];
        $result = $this->validator->validateAndSanitizeFields($fields, false);
        $this->assertEquals('value', $result['custom_field']);
    }

    // ============================================================
    // default case — empty guard (issue #1233, fix 2)
    // ============================================================

    /**
     * Unknown keys with a null value must be dropped from the result (not passed through).
     */
    #[Group('unit')]
    public function testDefaultCaseDropsNullValue(): void
    {
        $result = $this->validator->validateAndSanitizeFields(['unknownKey' => null], false);
        $this->assertArrayNotHasKey('unknownKey', $result);
    }

    /**
     * Unknown keys with an empty-string value must be dropped from the result (not passed through).
     */
    #[Group('unit')]
    public function testDefaultCaseDropsEmptyStringValue(): void
    {
        $result = $this->validator->validateAndSanitizeFields(['unknownKey' => ''], false);
        $this->assertArrayNotHasKey('unknownKey', $result);
    }

    /**
     * Unknown keys with a falsy string value ('0') must pass through — '0' is a legitimate
     * value that !empty() would silently drop, motivating the !== null && !== '' guard.
     *
     * Note: named-field cases (color, engine, model, etc.) intentionally still use !empty()
     * because '0' is not a meaningful value for any of those domain fields. Tracked in #1262.
     */
    #[Group('unit')]
    public function testDefaultCasePassesThroughZeroString(): void
    {
        $result = $this->validator->validateAndSanitizeFields(['unknownKey' => '0'], false);
        $this->assertArrayHasKey('unknownKey', $result);
        $this->assertSame('0', $result['unknownKey']);
    }

    // ============================================================
    // chassis — ChassisValidator integration (issue #1233, fix 4)
    // ============================================================

    /**
     * When year and model are present and year < 1970, ChassisValidator enforces the
     * pre-1970 format (exactly 4 numeric digits).  A valid 4-digit numeric chassis must
     * pass without exception and be stored in the result.
     *
     * Uses 'S3|FHC|36' — a combination present in the unit-test mock CarModel — so that
     * the 'model' key also passes validation within the same call.
     */
    #[Group('unit')]
    public function testChassisValidationUsesChassisValidatorForValidPre1970Chassis(): void
    {
        $result = $this->validator->validateAndSanitizeFields(
            ['chassis' => '1234', 'year' => 1965, 'model' => 'S3|FHC|36'],
            false
        );
        $this->assertSame('1234', $result['chassis']);
    }

    /**
     * When year and model are present and year < 1970, a non-numeric chassis (e.g. 'ABCD')
     * fails ChassisValidator's pre-1970 numeric rule (must be exactly 4 digits — letters are
     * rejected) and must throw CarValidationException with a message beginning
     * 'Chassis validation failed'.
     *
     * Uses 'S3|FHC|36' — a combination present in the unit-test mock CarModel — so that
     * the 'model' key does not itself trigger a validation error before chassis is checked.
     */
    #[Group('unit')]
    public function testChassisValidationRejectsInvalidPre1970FormatWhenYearModelPresent(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('Chassis validation failed');

        $this->validator->validateAndSanitizeFields(
            ['chassis' => 'ABCD', 'year' => 1965, 'model' => 'S3|FHC|36'],
            false
        );
    }

    /**
     * When chassis_override=1 is set and the chassis fails format validation, ChassisValidator
     * grants the override and CarValidator must not throw.  '12345' is invalid for pre-1970
     * (must be exactly 4 numeric digits), but the override allows it.
     */
    #[Group('unit')]
    public function testChassisValidationPermitsInvalidChassisWhenOverrideSet(): void
    {
        $result = $this->validator->validateAndSanitizeFields(
            ['chassis' => '12345', 'year' => 1965, 'model' => 'S3|FHC|36', 'chassis_override' => '1'],
            false
        );
        $this->assertSame('12345', $result['chassis']);
    }

    /**
     * When year and model are absent, ChassisValidator is not invoked.  The fallback
     * 3-character minimum applies: a 3-character chassis must be accepted and stored.
     */
    #[Group('unit')]
    public function testChassisValidationSkipsChassisValidatorWhenYearAbsent(): void
    {
        $result = $this->validator->validateAndSanitizeFields(['chassis' => 'ABC'], false);
        $this->assertSame('ABC', $result['chassis']);
    }

    // ============================================================
    // Full positive case: valid inputs return sanitized array
    // ============================================================

    /**
     * Full positive case with mock model validation
     * Uses mock CarModel class that accepts known valid combinations
     */
    public function testValidateAndSanitizeFieldsReturnsFullSanitizedArray(): void
    {
        $fields = [
            'chassis' => '1234A',
            'model' => 'S4|FHC|36', // Valid in mock CarModel
            'year' => '1970',
            'email' => 'owner@example.com',
            'website' => 'https://example.com',
            'purchasedate' => '2020-06-15',
            'user_id' => '7',
            'lat' => '51.5',
            'lon' => '-0.1',
            'city' => 'London',
            'color' => 'Red',
            'comments' => 'Well maintained',
        ];

        $result = $this->validator->validateAndSanitizeFields($fields, true);

        $this->assertEquals('1234A', $result['chassis']);
        $this->assertEquals('S4|FHC|36', $result['model']);
        $this->assertSame(1970, $result['year']);
        $this->assertEquals('owner@example.com', $result['email']);
        $this->assertEquals('https://example.com', $result['website']);
        $this->assertEquals('2020-06-15', $result['purchasedate']);
        $this->assertSame(7, $result['user_id']);
        $this->assertSame(51.5, $result['lat']);
        $this->assertSame(-0.1, $result['lon']);
        $this->assertEquals('London', $result['city']);
        $this->assertEquals('Red', $result['color']);
        $this->assertEquals('Well maintained', $result['comments']);
    }

    // ============================================================
    // Exception type verification: all throws are CarValidationException
    // ============================================================

    /**
     * Verify every validation error path throws CarValidationException
     * (extends ElanRegistryException), never generic Exception.
     *
     * @param array<string, mixed> $fields
     */
    #[DataProvider('invalidFieldsProvider')]
    public function testAllValidationErrorsThrowCarValidationException(array $fields, bool $requireAll): void
    {
        try {
            $this->validator->validateAndSanitizeFields($fields, $requireAll);
            $this->fail('Expected CarValidationException was not thrown');
        } catch (\Exception $e) {
            $this->assertInstanceOf(
                CarValidationException::class,
                $e,
                'Validation error must throw CarValidationException, got ' . get_class($e)
            );
            $this->assertInstanceOf(
                ElanRegistryException::class,
                $e,
                'CarValidationException must extend ElanRegistryException'
            );
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function invalidFieldsProvider(): array
    {
        return [
            'chassis required' => [['chassis' => ''], true],
            'chassis too short' => [['chassis' => 'AB'], false],
            'model required' => [['model' => ''], true],
            'year required' => [['year' => ''], true],
            'year too high' => [['year' => '2024'], false],
            'year too low' => [['year' => '1900'], false],
            'invalid email' => [['email' => 'bad'], false],
            'invalid website' => [['website' => 'bad'], false],
            'invalid date' => [['purchasedate' => '15/01/2023'], false],
            'invalid coordinate' => [['lat' => '999'], false],
            'invalid user_id' => [['user_id' => 'abc'], false],
            'negative user_id' => [['user_id' => '-1'], false],
        ];
    }

    // ============================================================
    // chassis_override field validation — issue #915
    // ============================================================

    /**
     * chassis_override with string '1' must be coerced to integer 1.
     */
    public function testChassisOverrideValidatesAsOne(): void
    {
        $result = $this->validator->validateAndSanitizeFields(['chassis_override' => '1'], false);
        $this->assertSame(1, $result['chassis_override']);
    }

    /**
     * chassis_override with string '0' must be coerced to integer 0.
     */
    public function testChassisOverrideValidatesAsZero(): void
    {
        $result = $this->validator->validateAndSanitizeFields(['chassis_override' => '0'], false);
        $this->assertSame(0, $result['chassis_override']);
    }

    /**
     * Any value other than '1' must be coerced to 0 — the field is a boolean flag,
     * not a free integer.
     */
    public function testChassisOverrideCoercesNonOneToZero(): void
    {
        $result = $this->validator->validateAndSanitizeFields(['chassis_override' => '99'], false);
        $this->assertSame(0, $result['chassis_override']);
    }

    // ============================================================
    // Model validation tests with Mock CarModel
    // Unit tests using mock CarModel class (see bootstrap-unit.php)
    // Integration tests that require real database are in
    // tests/integration/cars/services/CarValidatorModelTest.php
    // ============================================================

    /**
     * @test
     * Model validation accepts valid model combinations (mock)
     */
    public function testValidateModelAcceptsValidCombination(): void
    {
        $data = [
            'model' => 'S4|FHC|36', // Valid in mock CarModel
        ];

        $result = $this->validator->validateAndSanitizeFields($data, false);

        $this->assertArrayHasKey('model', $result);
        $this->assertEquals('S4|FHC|36', $result['model']);
    }

    /**
     * @test
     * Model validation rejects invalid combinations (mock)
     */
    public function testValidateModelRejectsInvalidCombination(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('is not a valid Lotus Elan model');

        $data = [
            'model' => 'S4|Roadster|99', // Invalid: not in mock CarModel
        ];

        $this->validator->validateAndSanitizeFields($data, false);
    }

    /**
     * @test
     * Model validation rejects invalid format
     */
    public function testValidateModelRejectsInvalidFormat(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('Invalid model format');

        $data = [
            'model' => 'InvalidFormat', // Missing pipe delimiters
        ];

        $this->validator->validateAndSanitizeFields($data, false);
    }

    /**
     * @test
     * Model validation handles empty model (when not required)
     */
    public function testValidateModelHandlesEmptyWhenNotRequired(): void
    {
        $data = [
            // model omitted
        ];

        $result = $this->validator->validateAndSanitizeFields($data, false);

        $this->assertArrayNotHasKey('model', $result);
    }

    /**
     * @test
     * Model validation requires model when requireAll is true
     */
    public function testValidateModelRequiredWhenRequireAll(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('Model is required');

        $data = [
            'model' => '',
        ];

        $this->validator->validateAndSanitizeFields($data, true);
    }
}
