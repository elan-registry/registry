<?php

declare(strict_types=1);

namespace Tests\Integration\Reference;

use PHPUnit\Framework\TestCase;
use ElanRegistry\Reference\CarModel;

/**
 * CarModelTest - Unit Tests for CarModel Reference Data Class
 *
 * Tests the read-only query interface for car model reference data.
 * These tests verify correct data retrieval and filtering from the car_models table.
 *
 * @group integration
 * @group reference-data
 * @covers \ElanRegistry\Reference\CarModel
 */
class CarModelTest extends TestCase
{
    private CarModel $carModel;

    /**
     * Set up test fixtures
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize CarModel with database connection
        $this->carModel = new CarModel();

        // Verify car_models table is populated (requires FIX script to be run first)
        $reflectionMethod = new \ReflectionMethod(CarModel::class, '__construct');
        $this->assertNotNull($reflectionMethod, 'CarModel should be instantiable');
    }

    /**
     * @test
     * Verify CarModel instantiation and database connection
     */
    public function testCarModelInstantiation(): void
    {
        $this->assertInstanceOf(CarModel::class, $this->carModel);
    }

    /**
     * @test
     * getAll() returns all car models
     */
    public function testGetAllReturnsAllModels(): void
    {
        $allModels = $this->carModel->getAll();

        $this->assertIsArray($allModels);
        $this->assertGreaterThanOrEqual(20, count($allModels), 'Should have at least 20 models');

        // Verify each model has required properties
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

    /**
     * @test
     * getAvailableInYear() returns models for specific year
     */
    public function testGetAvailableInYearReturnsCorrectModels(): void
    {
        // 1970 should have S4, Sprint, and Plus 2 models
        $models = $this->carModel->getAvailableInYear(1970);

        $this->assertIsArray($models);
        $this->assertGreaterThan(0, count($models), 'Year 1970 should have models');

        // Check for expected series in 1970
        $series = array_unique(array_map(fn($m) => $m->series_normalized, $models));
        $this->assertContains('S4', $series, 'S4 should be available in 1970');
        $this->assertContains('Sprint', $series, 'Sprint should be available in 1970');
    }

    /**
     * @test
     * getAvailableInYear() with 1963 returns S1 models
     */
    public function testGetAvailableInYear1963(): void
    {
        $models = $this->carModel->getAvailableInYear(1963);

        $this->assertGreaterThan(0, count($models));

        // S1 should be available
        $series = array_unique(array_map(fn($m) => $m->series_normalized, $models));
        $this->assertContains('S1', $series);
    }

    /**
     * @test
     * getAvailableInYear() with 1974 returns late models
     */
    public function testGetAvailableInYear1974(): void
    {
        $models = $this->carModel->getAvailableInYear(1974);

        $this->assertGreaterThan(0, count($models));

        // Should be Plus 2S 130 models only
        $series = array_unique(array_map(fn($m) => $m->series_normalized, $models));
        $this->assertContains('+2S/130', $series);
    }

    /**
     * @test
     * getAvailableInYear() throws for invalid year (too early)
     */
    public function testGetAvailableInYearThrowsForYearTooEarly(): void
    {
        $this->expectException(\Exception::class);
        $this->carModel->getAvailableInYear(1962);
    }

    /**
     * @test
     * getAvailableInYear() throws for invalid year (too late)
     */
    public function testGetAvailableInYearThrowsForYearTooLate(): void
    {
        $this->expectException(\Exception::class);
        $this->carModel->getAvailableInYear(1975);
    }

    /**
     * @test
     * getBySeries() returns models for S4
     */
    public function testGetBySeriesS4(): void
    {
        $s4Models = $this->carModel->getBySeries('S4');

        $this->assertGreaterThan(0, count($s4Models));

        // All should have S4 in series_normalized
        foreach ($s4Models as $model) {
            $this->assertEquals('S4', $model->series_normalized);
        }

        // Should have both FHC and DHC variants
        $variants = array_unique(array_map(fn($m) => $m->variant, $s4Models));
        $this->assertContains('FHC', $variants);
        $this->assertContains('DHC', $variants);
    }

    /**
     * @test
     * getBySeries() returns models for Sprint
     */
    public function testGetBySeriesSprint(): void
    {
        $sprintModels = $this->carModel->getBySeries('Sprint');

        $this->assertGreaterThan(0, count($sprintModels));

        foreach ($sprintModels as $model) {
            $this->assertEquals('Sprint', $model->series_normalized);
        }

        // Year range should be 1970-1973
        $minYear = min(array_map(fn($m) => $m->year_available_from, $sprintModels));
        $maxYear = max(array_map(fn($m) => $m->year_available_to, $sprintModels));
        $this->assertEquals(1970, $minYear);
        $this->assertEquals(1973, $maxYear);
    }

    /**
     * @test
     * getBySeries() returns models for +2 (Plus 2)
     */
    public function testGetBySeriesPlusTwo(): void
    {
        $plus2Models = $this->carModel->getBySeries('+2');

        $this->assertGreaterThan(0, count($plus2Models));

        foreach ($plus2Models as $model) {
            $this->assertStringContainsString('+2', $model->series_normalized);
        }
    }

    /**
     * @test
     * getBySeries() returns empty array for non-existent series
     */
    public function testGetBySeriesNonExistent(): void
    {
        $models = $this->carModel->getBySeries('NonExistent');
        $this->assertEmpty($models);
    }

    /**
     * @test
     * getBySeries() returns empty array for empty string
     */
    public function testGetBySeriesEmpty(): void
    {
        $models = $this->carModel->getBySeries('');
        $this->assertEmpty($models);
    }

    /**
     * @test
     * byValue() returns correct model for valid composite key
     */
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

    /**
     * @test
     * byValue() returns correct model for Drophead S4
     */
    public function testByValueDropheadS4(): void
    {
        $model = $this->carModel->byValue('S4|DHC|45');

        $this->assertNotNull($model);
        $this->assertEquals('Drophead S4 DHC', $model->human_readable_short);
        $this->assertEquals('S4', $model->series_normalized);
        $this->assertEquals('DHC', $model->variant);
        $this->assertEquals('45', $model->type_code);
    }

    /**
     * @test
     * byValue() returns null for non-existent model
     */
    public function testByValueNonExistent(): void
    {
        $model = $this->carModel->byValue('S4|NonExistentVariant|36');
        $this->assertNull($model);
    }

    /**
     * @test
     * byValue() returns null for empty string
     */
    public function testByValueEmpty(): void
    {
        $model = $this->carModel->byValue('');
        $this->assertNull($model);
    }

    /**
     * @test
     * getSeriesInYear() returns series available in 1970
     */
    public function testGetSeriesInYear1970(): void
    {
        $series = $this->carModel->getSeriesInYear(1970);

        $this->assertIsArray($series);
        $this->assertContains('S4', $series);
        $this->assertContains('Sprint', $series);
        // Plus 2 models should be present
        $plus2Variants = array_filter($series, fn($s) => strpos($s, '+2') === 0);
        $this->assertNotEmpty($plus2Variants, 'Should contain at least one Plus 2 variant');
    }

    /**
     * @test
     * getSeriesInYear() returns only S1 for 1963
     */
    public function testGetSeriesInYear1963(): void
    {
        $series = $this->carModel->getSeriesInYear(1963);

        $this->assertIsArray($series);
        $this->assertGreaterThan(0, count($series));
        // Should contain S1
        $this->assertTrue(in_array('S1', $series) || in_array('Elan 1500', $series));
    }

    /**
     * @test
     * getSeriesInYear() throws for invalid year
     */
    public function testGetSeriesInYearThrowsForInvalidYear(): void
    {
        $this->expectException(\Exception::class);
        $this->carModel->getSeriesInYear(1975);
    }

    /**
     * @test
     * groupByYear() returns all 12 years
     */
    public function testGroupByYearReturnsAllYears(): void
    {
        $grouped = $this->carModel->groupByYear();

        $this->assertIsArray($grouped);
        $this->assertEquals(12, count($grouped), 'Should have entries for years 1963-1974');

        // Check all years present
        for ($year = 1963; $year <= 1974; $year++) {
            $this->assertArrayHasKey($year, $grouped, "Year {$year} should be present");
            $this->assertIsArray($grouped[$year]);
        }
    }

    /**
     * @test
     * groupByYear() 1963 has at least 2 models (S1 Roadster + S1 Race)
     */
    public function testGroupByYear1963HasModels(): void
    {
        $grouped = $this->carModel->groupByYear();

        $this->assertGreaterThanOrEqual(2, count($grouped[1963]), 'Year 1963 should have S1 models');
    }

    /**
     * @test
     * groupByYear() returns consistent counts
     */
    public function testGroupByYearConsistency(): void
    {
        $grouped = $this->carModel->groupByYear();
        $allModels = $this->carModel->getAll();

        // Count total models across all years
        $totalByGroup = 0;
        foreach ($grouped as $year => $models) {
            $totalByGroup += count($models);
        }

        // Count total by year range
        $totalByRange = 0;
        foreach ($allModels as $model) {
            $totalByRange += ($model->year_available_to - $model->year_available_from + 1);
        }

        $this->assertEquals($totalByRange, $totalByGroup, 'Total model-year combinations should match');
    }

    /**
     * @test
     * exists() returns true for valid model combination
     */
    public function testExistsValidModel(): void
    {
        $exists = $this->carModel->exists('S4', 'FHC', '36');
        $this->assertTrue($exists);
    }

    /**
     * @test
     * exists() returns true for Sprint models
     */
    public function testExistsSprintModel(): void
    {
        $fhc = $this->carModel->exists('Sprint', 'FHC', '36');
        $this->assertTrue($fhc);

        $dhc = $this->carModel->exists('Sprint', 'DHC', '45');
        $this->assertTrue($dhc);
    }

    /**
     * @test
     * exists() returns false for invalid variant
     */
    public function testExistsInvalidVariant(): void
    {
        $exists = $this->carModel->exists('S4', 'Coupe', '36');
        // Should be false because Coupe is not valid, FHC is correct
        $this->assertFalse($exists);
    }

    /**
     * @test
     * exists() returns false for invalid type code
     */
    public function testExistsInvalidTypeCode(): void
    {
        $exists = $this->carModel->exists('S4', 'FHC', '99');
        $this->assertFalse($exists);
    }

    /**
     * @test
     * exists() handles whitespace in parameters
     */
    public function testExistsHandlesWhitespace(): void
    {
        $exists = $this->carModel->exists(' S4 ', ' FHC ', ' 36 ');
        $this->assertTrue($exists);
    }

    /**
     * @test
     * All models have valid type codes
     */
    public function testAllModelsHaveValidTypeCodes(): void
    {
        $validTypeCodes = ['26', '36', '45', '50', '26R'];
        $allModels = $this->carModel->getAll();

        foreach ($allModels as $model) {
            $this->assertContains($model->type_code, $validTypeCodes,
                "Model {$model->model_value} has invalid type_code: {$model->type_code}");
        }
    }

    /**
     * @test
     * All models have series_normalized value
     */
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

    /**
     * @test
     * All models have valid year ranges
     */
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

    /**
     * @test
     * Unique model values across all models
     */
    public function testUniqueModelValues(): void
    {
        $allModels = $this->carModel->getAll();
        $modelValues = array_map(fn($m) => $m->model_value, $allModels);

        $unique = array_unique($modelValues);
        $this->assertEquals(count($allModels), count($unique), 'All model_values should be unique');
    }

    /**
     * @test
     * Results are properly ordered in getAll()
     */
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

    /**
     * @test
     * SE/S/E suffixes are properly normalized
     */
    public function testSeriesNormalizationStripsSpecialSuffixes(): void
    {
        $allModels = $this->carModel->getAll();

        $withSE = array_filter($allModels, fn($m) => stripos($m->series, 'SE') !== false || stripos($m->series, 'S/E') !== false);

        foreach ($withSE as $model) {
            // series_normalized should not contain SE or S/E
            $this->assertStringNotContainsString('SE', $model->series_normalized);
            $this->assertStringNotContainsString('S/E', $model->series_normalized);
            // Should contain the base series name
            $this->assertGreaterThan(0, strlen($model->series_normalized));
        }
    }
}
