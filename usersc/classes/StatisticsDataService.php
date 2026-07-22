<?php

declare(strict_types=1);

namespace ElanRegistry;

use DB;

/**
 * StatisticsDataService.php
 * Centralized data service for statistics
 *
 * Provides data queries for the statistics dashboard.
 *
 * @author Elan Registry Development Team
 * @copyright 2025
 */

class StatisticsDataService {
    private DB $db;

    /**
     * Constructor for StatisticsDataService
     *
     * @param DB $database Database connection instance
     */
    public function __construct(DB $database) {
        $this->db = $database;
    }

    /**
     * Execute database query and return results
     *
     * @param string $query SQL query to execute
     * @param bool $single Whether to return single result or array
     * @return array|object|null Query results, or null/empty array on database error (error is logged)
     */
    private function executeQuery(string $query, bool $single = false): array|object|null {
        try {
            $result = $this->db->query($query);
            // UserSpice DB class swallows errors internally and never throws — check explicitly.
            // Also guard $result === null for fringe edge cases where query() returns null without setting the error flag.
            if ($result === null || $this->db->error()) {
                logger(
                    0,
                    LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                    'StatisticsDataService query failed: ' . $this->db->errorString() . ' | Query: ' . substr($query, 0, 200)
                );
                return $single ? null : [];
            }
            return $single ? $result->first() : $result->results();
        } catch (\Throwable $e) {
            logger(
                0,
                LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                'StatisticsDataService query threw: ' . $e->getMessage() . ' | Query: ' . substr($query, 0, 200)
            );
            return $single ? null : [];
        }
    }

    // === GEOGRAPHIC DATA ===

    /**
     * Get country distribution data
     *
     * @return array Country data with counts
     */
    public function getCountryData(): array {
        return $this->executeQuery(
            "SELECT country, COUNT(country) as count FROM cars GROUP BY country ORDER BY count DESC"
        );
    }

    /**
     * Get top country distribution data
     *
     * @return array Top 15 countries with car counts
     */
    public function getCountryDistribution(): array {
        return $this->executeQuery(
            "SELECT country, COUNT(*) as count
             FROM cars
             WHERE country IS NOT NULL AND country != ''
             GROUP BY country
             ORDER BY count DESC
             LIMIT 15"
        );
    }

    /**
     * Get US state distribution data
     *
     * @return array Top 10 US states with normalized names and counts
     */
    public function getUSStateDistribution(): array {
        return $this->executeQuery(
            "SELECT
                CASE
                    WHEN state = 'California' OR state = 'CA' THEN 'California'
                    WHEN state = 'Texas' OR state = 'TX' THEN 'Texas'
                    WHEN state = 'New York' OR state = 'NY' THEN 'New York'
                    WHEN state = 'Massachusetts' OR state = 'MA' THEN 'Massachusetts'
                    WHEN state = 'Pennsylvania' OR state = 'PA' THEN 'Pennsylvania'
                    WHEN state = 'Washington' OR state = 'WA' THEN 'Washington'
                    WHEN state = 'New Jersey' OR state = 'NJ' THEN 'New Jersey'
                    WHEN state = 'Connecticut' OR state = 'CT' THEN 'Connecticut'
                    WHEN state = 'Virginia' OR state = 'VA' THEN 'Virginia'
                    WHEN state = 'Oregon' OR state = 'OR' THEN 'Oregon'
                    WHEN LOWER(state) = 'ohio' THEN 'Ohio'
                    ELSE state
                END as normalized_state,
                COUNT(*) as count
             FROM cars
             WHERE country IN ('United States', 'US')
                AND state IS NOT NULL
                AND state != ''
                AND state != 'None'
                AND TRIM(state) != ''
             GROUP BY normalized_state
             ORDER BY count DESC
             LIMIT 10"
        );
    }

    // === PRODUCTION DATA ===

    /**
     * Get car type distribution data
     *
     * @return array Car types with counts
     */
    public function getTypeData(): array {
        return $this->executeQuery(
            "SELECT type, COUNT(type) as count FROM cars GROUP BY type ORDER BY count DESC"
        );
    }

    /**
     * Get car series distribution data
     *
     * @return array Car series with counts
     */
    public function getSeriesData(): array {
        return $this->executeQuery(
            "SELECT series, COUNT(series) as count FROM cars GROUP BY series ORDER BY count DESC"
        );
    }

    /**
     * Get car variant distribution data
     *
     * @return array Car variants with counts
     */
    public function getVariantData(): array {
        return $this->executeQuery(
            "SELECT variant, COUNT(variant) as count FROM cars GROUP BY variant ORDER BY count DESC"
        );
    }

    /**
     * Get production counts by year
     *
     * @return array Production data by year
     */
    public function getProductionByYear(): array {
        return $this->executeQuery(
            "SELECT year, COUNT(*) as count
             FROM cars
             GROUP BY year
             ORDER BY year"
        );
    }

    /**
     * Get early vs late production comparison
     *
     * @return array Production periods with counts
     */
    public function getEarlyVsLateProduction(): array {
        return $this->executeQuery(
            "SELECT
                CASE
                    WHEN year BETWEEN 1963 AND 1967 THEN 'Early Production (1963-1967)'
                    WHEN year BETWEEN 1968 AND 1974 THEN 'Late Production (1968-1974)'
                END as period,
                COUNT(*) as count
             FROM cars
             WHERE year BETWEEN 1963 AND 1974
             GROUP BY period"
        );
    }

    /**
     * Get detailed series counts
     *
     * @return array Series counts by type
     */
    public function getSeriesCounts(): array {
        $row = $this->executeQuery(
            "SELECT
                 SUM(series LIKE 's1%')     AS s1,
                 SUM(series LIKE 's2%')     AS s2,
                 SUM(series LIKE 's3%')     AS s3,
                 SUM(series LIKE 's4%')     AS s4,
                 SUM(series LIKE 'sprint%') AS sprint,
                 SUM(series LIKE '+2%')     AS plus2
             FROM cars",
            true
        );
        if ($row === null) {
            return ['s1' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 'sprint' => 0, '+2' => 0];
        }
        return [
            's1'     => (int)($row->s1     ?? 0),
            's2'     => (int)($row->s2     ?? 0),
            's3'     => (int)($row->s3     ?? 0),
            's4'     => (int)($row->s4     ?? 0),
            'sprint' => (int)($row->sprint ?? 0),
            '+2'     => (int)($row->plus2  ?? 0),
        ];
    }

    /**
     * Get map pin data for the world map
     *
     * @return array<int, object{id: int, year: int|null, series: string, chassis: string,
     *                           variant: string, image: string, city: string, state: string,
     *                           country: string, owner: string, lat: float, lon: float}> Cars with coordinates
     */
    public function getMapPins(): array {
        return $this->executeQuery(
            "SELECT id, year, series, chassis, variant, image,
                    city, state, country, fname AS owner, lat, lon
             FROM cars
             WHERE lat != 0 AND lon != 0"
        );
    }

    // === COLOR DATA ===

    /**
     * Get color distribution data
     *
     * @return array Top 15 colors with counts
     */
    public function getColorData(): array {
        return $this->executeQuery(
            "SELECT color, COUNT(*) as count
             FROM cars
             WHERE color IS NOT NULL AND color != ''
             GROUP BY color
             ORDER BY count DESC
             LIMIT 15"
        );
    }

    /**
     * Get color distribution by year
     *
     * @return array Color data grouped by year
     */
    public function getColorByYear(): array {
        return $this->executeQuery(
            "SELECT year, color, COUNT(*) as count
             FROM cars
             WHERE color IS NOT NULL AND color != ''
                AND color IN ('red', 'yellow', 'White', 'Blue', 'BRG', 'Green', 'Cirrus White', 'carnival red')
             GROUP BY year, color
             ORDER BY year, count DESC"
        );
    }

    /**
     * Get color distribution by series
     *
     * @return array Color data grouped by series
     */
    public function getColorBySeries(): array {
        return $this->executeQuery(
            "SELECT series, color, COUNT(*) as count
             FROM cars
             WHERE color IS NOT NULL AND color != ''
                AND color IN ('red', 'yellow', 'White', 'Blue', 'BRG', 'Green')
             GROUP BY series, color
             ORDER BY series, count DESC"
        );
    }

    // === TIMELINE DATA ===

    /**
     * Get timeline data for car registrations
     *
     * @return array Timeline data sorted by creation time
     */
    public function getTimelineData(): array {
        return $this->executeQuery(
            "SELECT ctime FROM cars WHERE 1 ORDER BY `cars`.`ctime` ASC"
        );
    }


    // === DATA QUALITY ===

    /**
     * Get data completeness analysis
     *
     * @return object|null Data completeness metrics
     */
    public function getDataCompleteness(): object|null {
        return $this->executeQuery(
            "SELECT
                COUNT(*) as total_cars,
                COUNT(chassis) as has_chassis,
                COUNT(color) as has_color,
                COUNT(engine) as has_engine,
                COUNT(purchasedate) as has_purchase_date,
                COUNT(solddate) as has_sold_date,
                COUNT(image) as has_image,
                COUNT(lat) as has_location,
                COUNT(last_verified) as verified_cars
             FROM cars",
            true
        );
    }

    // === CONSTANTS ===

    /**
     * Get series production notes
     *
     * @return array Series production numbers
     */
    public function getSeriesNotes(): array {
        return [
            's1' => "900",
            's2' => "1250",
            's3' => "2650",
            's4' => "2976",
            'sprint' => "900",
            '+2' => "4526"
        ];
    }
}