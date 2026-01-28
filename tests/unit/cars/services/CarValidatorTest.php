<?php

declare(strict_types=1);

use ElanRegistry\Car\CarValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CarValidator service class
 *
 * @group fast
 */
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

    public function testValidateAndSanitizeFieldsPassesThroughUnknownFields(): void
    {
        $fields = ['custom_field' => 'value'];
        $result = $this->validator->validateAndSanitizeFields($fields, false);
        $this->assertEquals('value', $result['custom_field']);
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
}
