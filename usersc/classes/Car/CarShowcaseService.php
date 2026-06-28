<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use LogCategories;

/**
 * Builds the car pool for the home page cycling showcase.
 */
class CarShowcaseService
{
    private const RECENT_LIMIT = 6;
    private const RANDOM_LIMIT = 6;
    private const NEW_DAYS = 90;
    private const IMAGE_CONDITION = "image <> '' AND image <> '[]' AND JSON_VALID(image) = 1 AND JSON_LENGTH(image) > 0 AND ctime IS NOT NULL";

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
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'CarShowcaseService: recent query failed');
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
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'CarShowcaseService: random query failed');
            return $recent;
        }

        $pool = array_merge($recent, $random);
        shuffle($pool);

        // is_new: within NEW_DAYS OR in top-5 most-recently-added (ensures badges even on dormant registries)
        $top5Ids = array_slice($recentIds, 0, 5);
        $threshold = new \DateTime('-' . self::NEW_DAYS . ' days');

        foreach ($pool as $car) {
            try {
                $carDate = new \DateTime((string) $car->ctime);
                $car->is_new = ($carDate >= $threshold) || in_array((int) $car->id, $top5Ids, true);
            } catch (\Exception) {
                $car->is_new = in_array((int) $car->id, $top5Ids, true);
            }
        }

        return $pool;
    }
}
