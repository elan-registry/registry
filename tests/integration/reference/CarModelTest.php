<?php

declare(strict_types=1);

namespace Tests\Integration\Reference;

use PHPUnit\Framework\TestCase;
use ElanRegistry\Reference\CarModel;
use ElanRegistry\Car\CarValidator;
use ElanRegistry\Exceptions\CarValidationException;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tier: `new CarModel()` falls back to dbi() and reads the real,
 * seeded car_models table. DB-failure cases: tests/unit/CarModelFailureTest.php.
 */
#[Group('integration')]
#[Group('reference-data')]
#[CoversClass(CarModel::class)]
class CarModelTest extends TestCase
{
    private CarModel $carModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carModel = new CarModel();
    }

    public function testGetAllReturnsAllModels(): void
    {
        $allModels = $this->carModel->getAll();

        $this->assertIsArray($allModels);
        $this->assertGreaterThanOrEqual(20, count($allModels), 'Should have at least 20 models');

        foreach ($allModels as $model) {
            $this->assertObjectHasProperty('id', $model);
            $this->assertObjectHasProperty('year_available_from', $model);
            $this->assertObjectHasProperty('year_available_to', $model);
            $this->assertObjectHasProperty('display_name', $model);
            $this->assertObjectHasProperty('human_readable_short', $model);
            $this->assertObjectHasProperty('series', $model);
            $this->assertObjectHasProperty('variant', $model);
            $this->assertObjectHasProperty('type_code', $model);
            $this->assertObjectHasProperty('model_value', $model);
            $this->assertObjectHasProperty('series_normalized', $model);
        }
    }

    public function testGetAvailableInYearReturnsCorrectModels(): void
    {
        $models = $this->carModel->getAvailableInYear(1970);

        $this->assertIsArray($models);
        $this->assertGreaterThan(0, count($models), 'Year 1970 should have models');

        $series = array_unique(array_map(fn($m) => $m->series_normalized, $models));
        $this->assertContains('S4', $series, 'S4 should be available in 1970');
        $this->assertContains('Sprint', $series, 'Sprint should be available in 1970');
    }

    public function testGetAvailableInYear1963(): void
    {
        $models = $this->carModel->getAvailableInYear(1963);

        $this->assertGreaterThan(0, count($models));

        $series = array_unique(array_map(fn($m) => $m->series_normalized, $models));
        $this->assertContains('S1', $series);
    }

    public function testGetAvailableInYear1974(): void
    {
        $models = $this->carModel->getAvailableInYear(1974);

        $this->assertGreaterThan(0, count($models));

        $series = array_unique(array_map(fn($m) => $m->series_normalized, $models));
        $this->assertContains('+2S/130', $series);
    }

    public function testGetAvailableInYear1965ExcludesBadRow(): void
    {
        $models = $this->carModel->getAvailableInYear(1965);

        $modelValues = array_map(fn($m) => $m->model_value, $models);
        $this->assertNotContains('26R|Race|26', $modelValues);
    }

    public function testGetAvailableInYearThrowsForYearTooEarly(): void
    {
        $this->expectException(CarValidationException::class);
        $this->carModel->getAvailableInYear(1962);
    }

    public function testGetAvailableInYearThrowsForYearTooLate(): void
    {
        $this->expectException(CarValidationException::class);
        $this->carModel->getAvailableInYear(1975);
    }

    public function testGetBySeriesS4(): void
    {
        $s4Models = $this->carModel->getBySeries('S4');

        $this->assertGreaterThan(0, count($s4Models));

        foreach ($s4Models as $model) {
            $this->assertEquals('S4', $model->series_normalized);
        }

        $variants = array_unique(array_map(fn($m) => $m->variant, $s4Models));
        $this->assertContains('FHC', $variants);
        $this->assertContains('DHC', $variants);
    }

    public function testGetBySeriesSprint(): void
    {
        $sprintModels = $this->carModel->getBySeries('Sprint');

        $this->assertGreaterThan(0, count($sprintModels));

        foreach ($sprintModels as $model) {
            $this->assertEquals('Sprint', $model->series_normalized);
        }

        $minYear = min(array_map(fn($m) => $m->year_available_from, $sprintModels));
        $maxYear = max(array_map(fn($m) => $m->year_available_to, $sprintModels));
        $this->assertEquals(1970, $minYear);
        $this->assertEquals(1973, $maxYear);
    }

    public function testGetBySeriesPlusTwo(): void
    {
        $plus2Models = $this->carModel->getBySeries('+2');

        $this->assertGreaterThan(0, count($plus2Models));

        foreach ($plus2Models as $model) {
            $this->assertStringContainsString('+2', $model->series_normalized);
        }
    }

    public function testGetBySeriesNonExistent(): void
    {
        $models = $this->carModel->getBySeries('NonExistent');
        $this->assertEmpty($models);
    }

    public function testGetBySeriesEmpty(): void
    {
        $models = $this->carModel->getBySeries('');
        $this->assertEmpty($models);
    }

    public function testByValueValidModel(): void
    {
        $model = $this->carModel->byValue('S4|FHC|36');

        $this->assertNotNull($model);
        $this->assertEquals('S4', $model->series_normalized);
        $this->assertEquals('FHC', $model->variant);
        $this->assertEquals('36', $model->type_code);
        $this->assertEquals(1968, $model->year_available_from);
        $this->assertEquals(1971, $model->year_available_to);
    }

    public function testByValueDropheadS4(): void
    {
        $model = $this->carModel->byValue('S4|DHC|45');

        $this->assertNotNull($model);
        $this->assertEquals('Drophead S4 DHC', $model->human_readable_short);
        $this->assertEquals('S4', $model->series_normalized);
        $this->assertEquals('DHC', $model->variant);
        $this->assertEquals('45', $model->type_code);
    }

    public function testByValueNonExistent(): void
    {
        $model = $this->carModel->byValue('S4|NonExistentVariant|36');
        $this->assertNull($model);
    }

    public function testByValueEmpty(): void
    {
        $model = $this->carModel->byValue('');
        $this->assertNull($model);
    }

    public function testBadRowDoesNotExist(): void
    {
        $model = $this->carModel->byValue('26R|Race|26');
        $this->assertNull($model);
    }

    public function testByValueS1Race26R(): void
    {
        $model = $this->carModel->byValue('S1|Race|26R');
        $this->assertNotNull($model);
        $this->assertSame('S1', $model->series);
        $this->assertSame('Race', $model->variant);
        $this->assertSame('26R', $model->type_code);
    }

    public function testByValueS2Race26R(): void
    {
        $model = $this->carModel->byValue('S2|Race|26R');
        $this->assertNotNull($model);
        $this->assertSame('S2', $model->series);
        $this->assertSame('Race', $model->variant);
        $this->assertSame('26R', $model->type_code);
    }

    public function testGetSeriesInYear1970(): void
    {
        $series = $this->carModel->getSeriesInYear(1970);

        $this->assertIsArray($series);
        $this->assertContains('S4', $series);
        $this->assertContains('Sprint', $series);
        $plus2Variants = array_filter($series, fn($s) => strpos($s, '+2') === 0);
        $this->assertNotEmpty($plus2Variants, 'Should contain at least one Plus 2 variant');
    }

    public function testGetSeriesInYear1963(): void
    {
        $series = $this->carModel->getSeriesInYear(1963);

        $this->assertIsArray($series);
        $this->assertGreaterThan(0, count($series));
        $this->assertTrue(in_array('S1', $series) || in_array('Elan 1500', $series));
    }

    public function testGetSeriesInYearThrowsForInvalidYear(): void
    {
        $this->expectException(CarValidationException::class);
        $this->carModel->getSeriesInYear(1975);
    }

    public function testGroupByYearReturnsAllYears(): void
    {
        $grouped = $this->carModel->groupByYear();

        $this->assertIsArray($grouped);
        $this->assertEquals(12, count($grouped), 'Should have entries for years 1963-1974');

        for ($year = 1963; $year <= 1974; $year++) {
            $this->assertArrayHasKey($year, $grouped, "Year {$year} should be present");
            $this->assertIsArray($grouped[$year]);
        }
    }

    public function testGroupByYear1963HasModels(): void
    {
        $grouped = $this->carModel->groupByYear();

        $this->assertGreaterThanOrEqual(2, count($grouped[1963]), 'Year 1963 should have S1 models');
    }

    public function testGroupByYearConsistency(): void
    {
        $grouped = $this->carModel->groupByYear();
        $allModels = $this->carModel->getAll();

        $totalByGroup = 0;
        foreach ($grouped as $year => $models) {
            $totalByGroup += count($models);
        }

        $totalByRange = 0;
        foreach ($allModels as $model) {
            $totalByRange += ($model->year_available_to - $model->year_available_from + 1);
        }

        $this->assertEquals($totalByRange, $totalByGroup, 'Total model-year combinations should match');
    }

    public function testExistsValidModel(): void
    {
        $exists = $this->carModel->exists('S4', 'FHC', '36');
        $this->assertTrue($exists);
    }

    public function testExistsSprintModel(): void
    {
        $fhc = $this->carModel->exists('Sprint', 'FHC', '36');
        $this->assertTrue($fhc);

        $dhc = $this->carModel->exists('Sprint', 'DHC', '45');
        $this->assertTrue($dhc);
    }

    public function testExistsS1Race26R(): void
    {
        $this->assertTrue($this->carModel->exists('S1', 'Race', '26R'));
    }

    public function testExistsS2Race26R(): void
    {
        $this->assertTrue($this->carModel->exists('S2', 'Race', '26R'));
    }

    public function testExistsInvalidVariant(): void
    {
        $exists = $this->carModel->exists('S4', 'Coupe', '36');
        // Should be false because Coupe is not valid, FHC is correct
        $this->assertFalse($exists);
    }

    public function testExistsInvalidTypeCode(): void
    {
        $exists = $this->carModel->exists('S4', 'FHC', '99');
        $this->assertFalse($exists);
    }

    public function testExistsHandlesWhitespace(): void
    {
        $exists = $this->carModel->exists(' S4 ', ' FHC ', ' 36 ');
        $this->assertTrue($exists);
    }

    public function testAllModelsHaveValidTypeCodes(): void
    {
        $validTypeCodes = ['26', '36', '45', '50', '26R'];
        $allModels = $this->carModel->getAll();

        foreach ($allModels as $model) {
            $this->assertContains($model->type_code, $validTypeCodes,
                "Model {$model->model_value} has invalid type_code: {$model->type_code}");
        }
    }

    public function testRaceModelsHaveCorrectSeriesValues(): void
    {
        $raceModels = $this->getRaceModels();

        foreach ($raceModels as $model) {
            $this->assertContains($model->series, ['S1', 'S2'],
                "Race model {$model->model_value} has unexpected series '{$model->series}' — must be S1 or S2");
        }
    }

    public function testRaceModelsHaveCorrectTypeCode(): void
    {
        $raceModels = $this->getRaceModels();

        foreach ($raceModels as $model) {
            $this->assertSame('26R', $model->type_code,
                "Race model {$model->model_value} has unexpected type_code '{$model->type_code}' — must be 26R");
        }
    }

    /**
     * Returns all Race-variant models, asserting at least one exists.
     *
     * @return array<object>
     */
    private function getRaceModels(): array
    {
        $raceModels = array_filter($this->carModel->getAll(), fn($m) => $m->variant === 'Race');
        $this->assertNotEmpty($raceModels, 'There should be at least one Race model in the reference data');
        return $raceModels;
    }

    public function testValidateModelRejectsRemovedBadRow(): void
    {
        $validator = new CarValidator();

        $this->expectException(CarValidationException::class);
        $validator->validateAndSanitizeFields(['model' => '26R|Race|26'], true);
    }

    public function testAllModelsHaveSeriesNormalized(): void
    {
        $allModels = $this->carModel->getAll();

        foreach ($allModels as $model) {
            $this->assertNotNull($model->series_normalized,
                "Model {$model->model_value} has NULL series_normalized");
            $this->assertNotEmpty($model->series_normalized,
                "Model {$model->model_value} has empty series_normalized");
        }
    }

    public function testAllModelsHaveValidYearRanges(): void
    {
        $allModels = $this->carModel->getAll();

        foreach ($allModels as $model) {
            $this->assertGreaterThanOrEqual(1963, $model->year_available_from);
            $this->assertLessThanOrEqual(1974, $model->year_available_to);
            $this->assertLessThanOrEqual($model->year_available_to, $model->year_available_from,
                "Model {$model->model_value} has invalid year range");
        }
    }

    public function testUniqueModelValues(): void
    {
        $allModels = $this->carModel->getAll();
        $modelValues = array_map(fn($m) => $m->model_value, $allModels);

        $unique = array_unique($modelValues);
        $this->assertEquals(count($allModels), count($unique), 'All model_values should be unique');
    }

    public function testGetAllOrderingByYear(): void
    {
        $allModels = $this->carModel->getAll();

        $prevYear = 0;
        foreach ($allModels as $model) {
            $this->assertGreaterThanOrEqual($prevYear, $model->year_available_from,
                'Models should be ordered by year');
            $prevYear = $model->year_available_from;
        }
    }

    public function testSeriesNormalizationStripsSpecialSuffixes(): void
    {
        $allModels = $this->carModel->getAll();

        $withSE = array_filter($allModels, fn($m) => stripos($m->series, 'SE') !== false || stripos($m->series, 'S/E') !== false);

        foreach ($withSE as $model) {
            $this->assertStringNotContainsString('SE', $model->series_normalized);
            $this->assertStringNotContainsString('S/E', $model->series_normalized);
            $this->assertGreaterThan(0, strlen($model->series_normalized));
        }
    }
}
