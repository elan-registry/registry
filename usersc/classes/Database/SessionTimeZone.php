<?php

declare(strict_types=1);

namespace ElanRegistry\Database;

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\SessionTimeZoneException;

/**
 * SessionTimeZone - Keeps the MySQL session clock on PHP's wall time
 *
 * PHP runs on Pacific time, but the MySQL server uses its own system zone.
 * Each connection sets its session time_zone to PHP's named zone, not to a
 * numeric offset. With a named zone, NOW() follows DST, and MySQL reads each
 * TIMESTAMP with the offset of its own date. A fixed offset reads the rows
 * from the other half of the year 1 hour off.
 *
 * A named zone needs the MySQL time zone tables. a2hosting and the Docker db
 * image have them. If MySQL rejects the name, apply() throws.
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
     * Set the session time_zone of a database connection to PHP's named zone.
     *
     * @param DatabaseInterface $db The connection to configure
     * @return void
     * @throws SessionTimeZoneException If MySQL rejects the SET statement
     */
    public static function apply(DatabaseInterface $db): void
    {
        $zone = date_default_timezone_get();
        $db->query('SET time_zone = ?', [$zone]);
        if ($db->error()) {
            throw new SessionTimeZoneException(
                "Could not set MySQL session time_zone to {$zone}. "
                . 'The MySQL time zone tables may be missing: ' . $db->errorString()
            );
        }
    }

    /**
     * Return a SET statement for a PDO init command (Pdo\Mysql::ATTR_INIT_COMMAND).
     *
     * The value comes only from PHP's own time zone, never from user input.
     * PHP accepts only valid zone identifiers, which contain letters, digits,
     * "/", "_", "+" and "-", so the value is safe to put in the SQL text.
     *
     * @return string For example "SET time_zone = 'America/Los_Angeles'"
     */
    public static function initCommand(): string
    {
        return "SET time_zone = '" . date_default_timezone_get() . "'";
    }
}
