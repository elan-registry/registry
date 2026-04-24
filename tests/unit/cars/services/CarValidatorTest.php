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
        $fields = ['chassis' => 'ABC123', 'model' => 'Elan', 'year' => '1970'];
        $this->validator->validateRequiredFields($fields, ['chassis', 'model', 'year']);
        $this->assertTrue(true); // No exception thrown
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

    // ============================================================
    // validateAndSanitizeFields tests
    // ============================================================

    public function testValidateAndSanitizeFieldsSanitizesChassis(): void
    {
        $fields = ['chassis' => '<script>ABC123</script>'];
        $result = $this->validator->validateAndSanitizeFields($fields, false);
        $this->assertEquals('ABC123', $result['chassis']);
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
        $this->expectExceptionMessage('Invalid website URL format');

        $fields = ['website' => 'not-a-url'];
        $this->validator->validateAndSanitizeFields($fields, false);
    }

    public function testValidateAndSanitizeFieldsAcceptsValidWebsiteUrl(): void
    {
        $fields = ['website' => 'https://example.com'];
        $result = $this->validator->validateAndSanitizeFields($fields, false);
        $this->assertEquals('https://example.com', $result['website']);
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
    // Full positive case: valid inputs return sanitized array
    // ============================================================

    /**
     * Full positive case with mock model validation
     * Uses mock CarModel class that accepts known valid combinations
     */
    public function testValidateAndSanitizeFieldsReturnsFullSanitizedArray(): void
    {
        $fields = [
            'chassis' => 'ABC123',
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

        $this->assertEquals('ABC123', $result['chassis']);
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
    // sanitizeString tests
    // ============================================================

    public function testSanitizeStringStripsHtml(): void
    {
        $result = $this->validator->sanitizeString('<b>Bold</b> text', 100);
        $this->assertEquals('Bold text', $result);
    }

    public function testSanitizeStringTrimsWhitespace(): void
    {
        $result = $this->validator->sanitizeString('  hello  ', 100);
        $this->assertEquals('hello', $result);
    }

    public function testSanitizeStringTruncates(): void
    {
        $result = $this->validator->sanitizeString('Long string here', 4);
        $this->assertEquals('Long', $result);
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
