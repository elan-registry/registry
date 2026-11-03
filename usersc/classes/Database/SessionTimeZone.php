<?php

declare(strict_types=1);

namespace ElanRegistry\Database;

use DateTimeImmutable;
use DateTimeZone;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\SessionTimeZoneException;

/**
 * SessionTimeZone - Keeps the MySQL session clock on PHP's wall time
 *
 * PHP runs on Pacific time, but the MySQL server uses its own system zone.
 * Each connection sets its session time_zone to PHP's current UTC offset, so
 * NOW() and PHP dates agree. A numeric offset is used, not a named zone,
 * because the MySQL time zone tables may not be loaded.
 *
 * @package ElanRegistry\Database
 * @since v2.31.1
 * @see https://github.com/elan-registry/registry/issues/2589
 */
final class SessionTimeZone
{
    /**
     * The application time zone. users/init.php sets the same zone; entry
     * points that do not load init.php (phinx, deploy scripts) use this.
     */
    public const APP_TIMEZONE = 'America/Los_Angeles';

    /**
     * Return the UTC offset of a zone at a given instant, as MySQL accepts it.
     *
     * @param DateTimeZone $zone The zone to resolve
     * @param DateTimeImmutable $at The instant (the offset changes with DST)
     * @return string The offset, for example "-08:00" or "+05:30"
     */
    public static function offsetFor(DateTimeZone $zone, DateTimeImmutable $at): string
    {
        return $at->setTimezone($zone)->format('P');
    }

    /**
     * Set the session time_zone of a database connection to PHP's offset.
     *
     * @param DatabaseInterface $db The connection to configure
     * @return void
     * @throws SessionTimeZoneException If MySQL rejects the SET statement
     */
    public static function apply(DatabaseInterface $db): void
    {
        $offset = self::currentOffset();
        $db->query('SET time_zone = ?', [$offset]);
        if ($db->error()) {
            throw new SessionTimeZoneException(
                "Could not set MySQL session time_zone to {$offset}: " . $db->errorString()
            );
        }
    }

    /**
     * Return a SET statement for a PDO init command (Pdo\Mysql::ATTR_INIT_COMMAND).
     *
     * The value comes only from PHP's own time zone, never from user input,
     * so it is safe to put in the SQL text.
     *
     * @return string For example "SET time_zone = '-08:00'"
     */
    public static function initCommand(): string
    {
        return "SET time_zone = '" . self::currentOffset() . "'";
    }

    /**
     * Return PHP's current UTC offset for its default time zone.
     *
     * @return string
     */
    private static function currentOffset(): string
    {
        $zone = new DateTimeZone(date_default_timezone_get());

        return self::offsetFor($zone, new DateTimeImmutable('now', $zone));
    }
}
