<?php

declare(strict_types=1);

namespace Tests\Integration\Cars\Services;

use PHPUnit\Framework\TestCase;
use ElanRegistry\Car\CarValidator;
use ElanRegistry\Exceptions\CarValidationException;

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration Tests for CarValidator Model Validation
 *
 * Tests model validation that requires car_models reference data.
 * These tests verify that CarValidator correctly integrates with the
 * CarModel class to validate model combinations against the database.
 */
#[Group('integration')]
#[Group('reference-data')]
final class CarValidatorModelTest extends TestCase
{
    private CarValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CarValidator();
    }

    /**
     * @test
     * Model validation accepts valid model combinations
     */
    public function testValidateModelAcceptsValidCombination(): void
    {
        $data = [
            'model' => 'S4|FHC|36',
        ];

        $result = $this->validator->validateAndSanitizeFields($data, false);

        $this->assertArrayHasKey('model', $result);
        $this->assertEquals('S4|FHC|36', $result['model']);
    }

    /**
     * @test
     * Model validation accepts Sprint FHC
     */
    public function testValidateModelAcceptsSprintFHC(): void
    {
        $data = [
            'model' => 'Sprint|FHC|36',
        ];

        $result = $this->validator->validateAndSanitizeFields($data, false);

        $this->assertArrayHasKey('model', $result);
        $this->assertEquals('Sprint|FHC|36', $result['model']);
    }

    /**
     * @test
     * Model validation accepts Sprint DHC
     */
    public function testValidateModelAcceptsSprintDHC(): void
    {
        $data = [
            'model' => 'Sprint|DHC|45',
        ];

        $result = $this->validator->validateAndSanitizeFields($data, false);

        $this->assertArrayHasKey('model', $result);
        $this->assertEquals('Sprint|DHC|45', $result['model']);
    }

    /**
     * @test
     * Model validation accepts S4 DHC (Drophead)
     */
    public function testValidateModelAcceptsS4DHC(): void
    {
        $data = [
            'model' => 'S4|DHC|45',
        ];

        $result = $this->validator->validateAndSanitizeFields($data, false);

        $this->assertArrayHasKey('model', $result);
        $this->assertEquals('S4|DHC|45', $result['model']);
    }

    /**
     * @test
     * Model validation accepts Plus 2 models
     */
    public function testValidateModelAcceptsPlus2(): void
    {
        $data = [
            'model' => '+2|FHC|50',
        ];

        $result = $this->validator->validateAndSanitizeFields($data, false);

        $this->assertArrayHasKey('model', $result);
        $this->assertEquals('+2|FHC|50', $result['model']);
    }

    /**
     * @test
     * Model validation rejects invalid combinations
     */
    public function testValidateModelRejectsInvalidCombination(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('is not a valid Lotus Elan model');

        $data = [
            'model' => 'S4|Roadster|99', // Invalid: S4 Roadster with type 99
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

    /**
     * @test
     * Full positive case with valid model - integration test
     */
    public function testValidateAndSanitizeFieldsReturnsFullSanitizedArrayWithModel(): void
    {
        $fields = [
            'chassis' => 'ABC123',
            'model' => 'S4|FHC|36',
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
}
