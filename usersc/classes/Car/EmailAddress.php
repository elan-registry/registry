<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

/**
 * EmailAddress - The matching rule for owner email addresses
 *
 * Runtime code compares addresses from `users`, `cars`, `profiles`,
 * `er_email_events` and the sendinblue plugin with this rule. Both sides of a
 * compare go through {@see self::normalize()}, then the compare is byte for
 * byte. Do the compare in PHP, or in SQL with `COLLATE utf8mb4_bin`, because
 * the column collations are case- and accent-insensitive.
 *
 * Migrations do not call this class. They keep a frozen copy of the rule
 * (for example `DeliverabilityMigrationQueries::normalizeAddress()`), so that
 * a migration gives the same result when it runs again after this rule
 * changes.
 *
 * @package ElanRegistry\Car
 * @since v2.30.8
 * @see https://github.com/elan-registry/registry/issues/2358
 */
final class EmailAddress
{
    /** The class holds one static rule and no state, so it has no instances. */
    private function __construct()
    {
    }

    /**
     * Normalize an address for a compare.
     *
     * NULL becomes '', and '' matches no real address.
     *
     * @param string|null $address A raw address
     * @return string The trimmed, lower-case address
     */
    public static function normalize(?string $address): string
    {
        return strtolower(trim((string) $address));
    }
}
