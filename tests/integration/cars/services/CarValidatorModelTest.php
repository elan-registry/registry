<?php

declare(strict_types=1);

namespace Tests\Integration\Cars\Services;

use PHPUnit\Framework\TestCase;
use ElanRegistry\Car\CarValidator;
use ElanRegistry\Exceptions\CarValidationException;
use RuntimeException;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * CarValidator model validation against car_models reference data.
 * Not run by CI; run `composer test:integration` before merge.
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
     * The provider reads the CSV that CarModelsSeed seeds from, so it cannot
     * drift from the database (#1446).
     */
    #[DataProvider('allReferenceModelCombinationsProvider')]
    public function testValidateModelAcceptsEveryReferenceCombination(
        string $series,
        string $variant,
        string $typeCode
    ): void {
        $data = [
            'model' => "{$series}|{$variant}|{$typeCode}",
        ];

        $result = $this->validator->validateAndSanitizeFields($data, false);

        $this->assertArrayHasKey('model', $result);
        $this->assertEquals("{$series}|{$variant}|{$typeCode}", $result['model']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> Keyed by
     *   model_value; each value is [series, variant, type_code].
     */
    public static function allReferenceModelCombinationsProvider(): array
    {
        $csvPath = dirname(__DIR__, 4) . '/database/seeds/data/car_models.csv';
        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Could not read reference-data CSV at {$csvPath}");
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        $cases = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $record = array_combine($header, $row);
            $cases[$record['model_value']] = [
                $record['series'],
                $record['variant'],
                $record['type_code'],
            ];
        }
        fclose($handle);

        // A truncated CSV must fail, not run fewer cases. Keep in step with
        // CarModelsSeed::EXPECTED_ROWS.
        if (count($cases) !== 23) {
            throw new RuntimeException(
                'Expected 23 model combinations from ' . $csvPath . ', got ' . count($cases)
                . ' — if the dataset changed, update this count and CarModelsSeed::EXPECTED_ROWS'
            );
        }

        return $cases;
    }

    /** Each case has one mismatched field, so a check that skips one column fails. */
    #[DataProvider('invalidCombinationProvider')]
    public function testValidateModelRejectsInvalidCombination(string $model): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('is not a valid Lotus Elan model');

        $this->validator->validateAndSanitizeFields(['model' => $model], false);
    }

    /** @return array<string, array{0: string}> */
    public static function invalidCombinationProvider(): array
    {
        return [
            'all three fields wrong' => ['S4|Roadster|99'],
            // Real pairing is S4|FHC|36 — 45 belongs to S4|DHC, not S4|FHC.
            'type_code wrong for series+variant' => ['S4|FHC|45'],
            // FHC|36 is a real pairing (S3/S4), but S1 never had an FHC variant.
            'series wrong for variant+type_code' => ['S1|FHC|36'],
        ];
    }

    public function testValidateModelRejectsInvalidFormat(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('Invalid model format');

        $data = [
            'model' => 'InvalidFormat', // Missing pipe delimiters
        ];

        $this->validator->validateAndSanitizeFields($data, false);
    }

    public function testValidateModelHandlesEmptyWhenNotRequired(): void
    {
        $data = [
            // model omitted
        ];

        $result = $this->validator->validateAndSanitizeFields($data, false);

        $this->assertArrayNotHasKey('model', $result);
    }

    public function testValidateModelRequiredWhenRequireAll(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('Model is required');

        $data = [
            'model' => '',
        ];

        $this->validator->validateAndSanitizeFields($data, true);
    }

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
