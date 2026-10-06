<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;

/**
 * Builds the car pool for the home page cycling showcase.
 */
class CarShowcaseService
{
    private const RECENT_LIMIT = 6;
    private const RANDOM_LIMIT = 6;
    // Public because CarBadges builds the "new" badge tooltip from them.
    public const NEW_DAYS = 90;
    public const NEW_FLOOR = 5;
    private const IMAGE_CONDITION = "image <> '' AND image <> '[]' AND JSON_VALID(image) = 1 AND JSON_LENGTH(image) > 0 AND ctime IS NOT NULL";

    private DatabaseInterface $db;

    /**
     * @param DatabaseInterface|null $db Optional database instance for testing. Defaults to the shared dbi() handle.
     */
    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db ?? dbi();
    }

    /**
     * Return IDs of all "new" cars: added within NEW_DAYS days OR among the
     * NEW_FLOOR most-recently-added (all cars, regardless of images).
     *
     * @return list<int>
     */
    public function getNewCarIds(): array
    {
        $this->db->query("
            SELECT id FROM cars
            WHERE ctime > (NOW() - INTERVAL " . self::NEW_DAYS . " DAY)
            UNION
            SELECT id FROM (
                SELECT id FROM cars
                ORDER BY ctime DESC, id DESC
                LIMIT " . self::NEW_FLOOR . "
            ) AS recent
        ");

        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'CarShowcaseService: getNewCarIds query failed: ' . $this->db->errorString());
            return [];
        }

        $results = $this->db->results();
        return array_map(fn($r) => (int) $r->id, $results ?: []);
    }

    /**
     * Return a shuffled pool of up to 12 cars: RECENT_LIMIT most-recently-added
     * plus RANDOM_LIMIT random older cars. Only cars with at least one valid image
     * are eligible. Each item has `id`, `year`, `series`, `variant`, `type`,
     * `ctime`, and `is_new` (bool).
     *
     * @return array<object>
     */
    public function buildShowcasePool(): array
    {
        $recent = $this->db->query("
            SELECT id, year, series, variant, type, ctime
            FROM cars
            WHERE " . self::IMAGE_CONDITION . "
            ORDER BY ctime DESC
            LIMIT " . self::RECENT_LIMIT
        )->results();

        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'CarShowcaseService: recent query failed: ' . $this->db->errorString());
            return [];
        }

        $recentIds = array_map(fn($r) => (int) $r->id, $recent);
        $excludeList = empty($recentIds) ? '0' : implode(',', $recentIds);

        $random = $this->db->query("
            SELECT id, year, series, variant, type, ctime
            FROM cars
            WHERE " . self::IMAGE_CONDITION . "
              AND id NOT IN ({$excludeList})
            ORDER BY RAND()
            LIMIT " . self::RANDOM_LIMIT
        )->results();

        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'CarShowcaseService: random query failed: ' . $this->db->errorString());
            return $this->stampIsNew($recent);
        }

        $pool = array_merge($recent, $random);
        shuffle($pool);

        return $this->stampIsNew($pool);
    }

    /**
     * Stamp each car with `is_new` (bool), per getNewCarIds(). Applied to every
     * pool returned by buildShowcasePool() — including its random-query-error
     * fallback — so callers can always rely on the property being present, per
     * this class's documented return shape.
     *
     * @param array<object> $pool
     * @return array<object>
     */
    private function stampIsNew(array $pool): array
    {
        $newIds = $this->getNewCarIds();
        foreach ($pool as $car) {
            $car->is_new = in_array((int) $car->id, $newIds, true);
        }

        return $pool;
    }
}
