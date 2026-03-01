<?php

declare(strict_types=1);

namespace ElanRegistry\Reference;

use DB;

/**
 * CarModel - Reference Data for Lotus Elan Model Definitions
 *
 * Provides read-only access to car model reference data from the car_models table.
 * This class represents factory-defined model specifications extracted from
 * cardefinition.js and supports queries for filtering, validation, and lookup.
 *
 * @package ElanRegistry\Reference
 * @since Phase 1.11.12 (Issue #577)
 *
 * Usage:
 * ```php
 * $carModel = new CarModel();
 * $models = $carModel->getAvailableInYear(1970);
 * $s4Models = $carModel->getBySeries('S4');
 * ```
 */
class CarModel
{
    private DB $db;

    /**
     * Construct and initialize database connection
     */
    public function __construct()
    {
        $this->db = DB::getInstance();
    }

    /**
     * Get all models available in a specific production year
     *
     * @param int $year Production year (1963-1974)
     * @return array<object> Array of model objects ordered by series
     * @throws \Exception If year is outside valid range
     *
     * Query: WHERE year_available_from <= year AND year_available_to >= year
     *
     * @example
     * $models = $carModel->getAvailableInYear(1970);
     * // Returns S4, Sprint, and Plus 2 models produced in 1970
     */
    public function getAvailableInYear(int $year): array
    {
        if ($year < 1963 || $year > 1974) {
            throw new \Exception("Year must be between 1963 and 1974, got {$year}");
        }

        return $this->db->query(
            'SELECT * FROM car_models
             WHERE year_available_from <= ? AND year_available_to >= ?
             ORDER BY series, variant, type_code',
            [$year, $year]
        )->results();
    }

    /**
     * Get all models for a specific series (normalized)
     *
     * @param string $series Series name (S1, S2, S3, S4, Sprint, +2, etc.)
     * @return array<object> Array of model objects ordered by year range
     *
     * Query: WHERE series_normalized = series
     * Note: Uses series_normalized column which strips SE/S/E/Race suffixes
     *
     * @example
     * $s4Models = $carModel->getBySeries('S4');
     * // Returns all S4 FHC and DHC models (1968-1971)
     *
     * $sprintModels = $carModel->getBySeries('Sprint');
     * // Returns Sprint FHC and DHC models (1970-1973)
     */
    public function getBySeries(string $series): array
    {
        $series = trim($series);
        if (empty($series)) {
            return [];
        }

        return $this->db->query(
            'SELECT * FROM car_models
             WHERE series_normalized = ?
             ORDER BY year_available_from, variant, type_code',
            [$series]
        )->results();
    }

    /**
     * Get model by composite model_value (series|variant|type)
     *
     * @param string $modelValue Composite key format: "series|variant|type"
     * @return object|null Model object or null if not found
     *
     * Query: WHERE model_value = value
     * Example values:
     * - "S4|FHC|36" → Coupe S4
     * - "S4|DHC|45" → Drophead S4
     * - "+2S/130|FHC|50" → Plus 2S 130
     *
     * @example
     * $model = $carModel->byValue('S4|FHC|36');
     * if ($model) {
     *     echo $model->human_readable_short; // "Coupe S4"
     *     echo $model->year_available_from;  // 1968
     *     echo $model->year_available_to;    // 1971
     * }
     */
    public function byValue(string $modelValue): ?object
    {
        $modelValue = trim($modelValue);
        if (empty($modelValue)) {
            return null;
        }

        $result = $this->db->query(
            'SELECT * FROM car_models WHERE model_value = ?',
            [$modelValue]
        )->first();

        return $result ?: null;
    }

    /**
     * Get unique series available in a specific production year
     *
     * @param int $year Production year (1963-1974)
     * @return array<string> Array of series names (S1, S2, S3, S4, Sprint, +2, etc.)
     * @throws \Exception If year is outside valid range
     *
     * Query: SELECT DISTINCT series_normalized
     * WHERE year_available_from <= year AND year_available_to >= year
     *
     * @example
     * $series = $carModel->getSeriesInYear(1970);
     * // Returns ["S4", "Sprint", "+2", "+2S"]
     */
    public function getSeriesInYear(int $year): array
    {
        if ($year < 1963 || $year > 1974) {
            throw new \Exception("Year must be between 1963 and 1974, got {$year}");
        }

        $results = $this->db->query(
            'SELECT DISTINCT series_normalized as series FROM car_models
             WHERE year_available_from <= ? AND year_available_to >= ?
             ORDER BY series_normalized',
            [$year, $year]
        )->results();

        return array_map(fn($r) => $r->series, $results);
    }

    /**
     * Get all models grouped by production year
     *
     * @return array<int, array<object>> Models grouped by year [1963 => [...], 1964 => [...], ...]
     *
     * Returns complete data structure suitable for generating MENU-style arrays
     * or year-based filtering interfaces.
     *
     * @example
     * $byYear = $carModel->groupByYear();
     * foreach ($byYear as $year => $models) {
     *     echo "Year $year: " . count($models) . " models\n";
     * }
     */
    public function groupByYear(): array
    {
        $all = $this->getAll();
        $grouped = [];

        foreach ($all as $model) {
            for ($year = $model->year_available_from; $year <= $model->year_available_to; $year++) {
                if (!isset($grouped[$year])) {
                    $grouped[$year] = [];
                }
                $grouped[$year][] = $model;
            }
        }

        // Fill in missing years with empty arrays
        for ($year = 1963; $year <= 1974; $year++) {
            if (!isset($grouped[$year])) {
                $grouped[$year] = [];
            }
        }

        ksort($grouped);
        return $grouped;
    }

    /**
     * Get all models in database (admin/reference)
     *
     * @return array<object> All model objects ordered by year and series
     *
     * Returns complete model registry for administrative purposes,
     * validation, and system initialization.
     *
     * @example
     * $allModels = $carModel->getAll();
     * echo "Total models: " . count($allModels);
     *
     * // Find all roadsters
     * $roadsters = array_filter($allModels, fn($m) => $m->variant === 'Roadster');
     */
    public function getAll(): array
    {
        return $this->db->query(
            'SELECT * FROM car_models
             ORDER BY year_available_from, series, variant, type_code'
        )->results();
    }

    /**
     * Validate if a model combination exists in database
     *
     * @param string $series Series name (S1, S2, S3, S4, Sprint, +2, etc.)
     * @param string $variant Body style (Roadster, FHC, DHC, Federal, Race)
     * @param string $typeCode Type code (26, 36, 45, 50, 26R)
     * @return bool True if model combination exists
     *
     * Query: WHERE series = ? AND variant = ? AND type_code = ?
     *
     * @example
     * if ($carModel->exists('S4', 'FHC', '36')) {
     *     // Valid model combination
     * }
     *
     * if (!$carModel->exists('S4', 'Coupe', '36')) {
     *     // Invalid variant name
     * }
     */
    public function exists(string $series, string $variant, string $typeCode): bool
    {
        $result = $this->db->query(
            'SELECT COUNT(*) as cnt FROM car_models
             WHERE series = ? AND variant = ? AND type_code = ?',
            [trim($series), trim($variant), trim($typeCode)]
        )->first();

        return ($result->cnt ?? 0) > 0;
    }
}
