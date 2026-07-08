<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\LogCategories;

/**
 * Builds the car pool for the home page cycling showcase.
 */
class CarShowcaseService
{
    private const RECENT_LIMIT = 6;
    private const RANDOM_LIMIT = 6;
    private const NEW_DAYS = 90;
    private const NEW_FLOOR = 5;
    private const IMAGE_CONDITION = "image <> '' AND image <> '[]' AND JSON_VALID(image) = 1 AND JSON_LENGTH(image) > 0 AND ctime IS NOT NULL";

    /**
     * Return IDs of all "new" cars: added within NEW_DAYS days OR among the
     * NEW_FLOOR most-recently-added (all cars, regardless of images).
     *
     * @param object $db UserSpice database object
     * @return list<int>
     */
    public static function getNewCarIds(object $db): array
    {
        $db->query("
            SELECT id FROM cars
            WHERE ctime > (NOW() - INTERVAL " . self::NEW_DAYS . " DAY)
            UNION
            SELECT id FROM (
                SELECT id FROM cars
                ORDER BY ctime DESC, id DESC
                LIMIT " . self::NEW_FLOOR . "
            ) AS recent
        ");

        if ($db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'CarShowcaseService: getNewCarIds query failed: ' . $db->errorString());
            return [];
        }

        $results = $db->results();
        return array_map(fn($r) => (int) $r->id, $results ?: []);
    }

    /**
     * Return a shuffled pool of up to 12 cars: RECENT_LIMIT most-recently-added
     * plus RANDOM_LIMIT random older cars. Only cars with at least one valid image
     * are eligible. Each item has `id`, `year`, `series`, `variant`, `type`,
     * `ctime`, and `is_new` (bool).
     *
     * @param object $db UserSpice database object
     * @return array<object>
     */
    public static function buildShowcasePool(object $db): array
    {
        $recent = $db->query("
            SELECT id, year, series, variant, type, ctime
            FROM cars
            WHERE " . self::IMAGE_CONDITION . "
            ORDER BY ctime DESC
            LIMIT " . self::RECENT_LIMIT
        )->results();

        if ($db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'CarShowcaseService: recent query failed: ' . $db->errorString());
            return [];
        }

        $recentIds = array_map(fn($r) => (int) $r->id, $recent);
        $excludeList = empty($recentIds) ? '0' : implode(',', $recentIds);

        $random = $db->query("
            SELECT id, year, series, variant, type, ctime
            FROM cars
            WHERE " . self::IMAGE_CONDITION . "
              AND id NOT IN ({$excludeList})
            ORDER BY RAND()
            LIMIT " . self::RANDOM_LIMIT
        )->results();

        if ($db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'CarShowcaseService: random query failed: ' . $db->errorString());
            return $recent;
        }

        $pool = array_merge($recent, $random);
        shuffle($pool);

        $newIds = self::getNewCarIds($db);
        foreach ($pool as $car) {
            $car->is_new = in_array((int) $car->id, $newIds, true);
        }

        return $pool;
    }
}
