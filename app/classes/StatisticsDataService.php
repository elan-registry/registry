<?php

declare(strict_types=1);

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
    private object $db;

    /**
     * Constructor for StatisticsDataService
     *
     * @param object $database Database connection instance
     */
    public function __construct(object $database) {
        $this->db = $database;
    }

    /**
     * Execute database query and return results
     *
     * @param string $query SQL query to execute
     * @param bool $single Whether to return single result or array
     * @return array|object|null Query results
     */
    private function executeQuery(string $query, bool $single = false): array|object|null {
        $result = $this->db->query($query);
        return $single ? $result->first() : $result->results();
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
                    WHEN year BETWEEN '1963' AND '1967' THEN 'Early Production (1963-1967)'
                    WHEN year BETWEEN '1968' AND '1974' THEN 'Late Production (1968-1974)'
                END as period,
                COUNT(*) as count
             FROM cars
             WHERE year BETWEEN '1963' AND '1974'
             GROUP BY period"
        );
    }

    /**
     * Get detailed series counts
     *
     * @return array Series counts by type
     */
    public function getSeriesCounts(): array {
        return [
            's1' => $this->executeQuery("select count(*) as count from cars where series like 's1%'", true)->count,
            's2' => $this->executeQuery("select count(*) as count from cars where series like 's2%'", true)->count,
            's3' => $this->executeQuery("select count(*) as count from cars where series like 's3%'", true)->count,
            's4' => $this->executeQuery("select count(*) as count from cars where series like 's4%'", true)->count,
            'sprint' => $this->executeQuery("select count(*) as count from cars where series like 'sprint%'", true)->count,
            '+2' => $this->executeQuery("select count(*) as count from cars where series like '+2%'", true)->count,
        ];
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

    /**
     * Get data quality age analysis
     *
     * @return array Age-based data quality metrics
     */
    public function getAgeData(): array {
        return $this->executeQuery(
            "SELECT
               periods.age,
               COALESCE(data.count, 0) as count
             FROM (
               SELECT '15 days' as age, 1 as sort_order
               UNION SELECT '30 days' as age, 2 as sort_order
               UNION SELECT '60 days' as age, 3 as sort_order
               UNION SELECT '90 days' as age, 4 as sort_order
             ) periods
             LEFT JOIN (
               SELECT
                 CASE
                   WHEN ctime >= DATE_SUB(CURDATE(), INTERVAL 15 DAY) THEN '15 days'
                   WHEN ctime >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN '30 days'
                   WHEN ctime >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) THEN '60 days'
                   WHEN ctime >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN '90 days'
                 END AS age,
                 COUNT(*) as count
               FROM cars
               WHERE ctime >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
               GROUP BY age
             ) data ON periods.age = data.age
             ORDER BY periods.sort_order"
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