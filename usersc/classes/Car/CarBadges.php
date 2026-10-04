<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use DateTimeImmutable;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\LogCategories;

/**
 * CarBadges - One definition of the status badges a car can show
 *
 * The account page hero and the cars list get their badge keys from this
 * class. The precedence rules (group exclusivity, suppression, order, maximum
 * count) exist only here, so the PHP partial and the list JS only draw the
 * keys they receive. To add a badge, add one entry to BADGES and one flag to
 * resolve().
 *
 * The Vehicle Information card (account, car details, and vericode pages)
 * does not use badge keys from this class. It draws a fixed Sold stamp when
 * parseSoldDate() returns a date. parseSoldDate() is the one sold-date rule
 * for all pages.
 *
 * Each badge has a label and an optional icon. The icon is decorative: the
 * renderer puts it in an `aria-hidden="true"` span before the label, so a
 * screen reader reads only the label (for example "Verified", not "✓ Verified").
 *
 * @package ElanRegistry\Car
 * @since v2.30.4
 * @see https://github.com/elan-registry/registry/issues/1900
 *
 * @phpstan-type BadgeDefinition array{
 *     label: string,
 *     icon: ?string,
 *     tooltip: string,
 *     tone: string,
 *     priority: int,
 *     group: ?string,
 *     suppressedBy: list<string>
 * }
 * @phpstan-type PublicBadgeDefinition array{
 *     label: string,
 *     icon: ?string,
 *     tooltip: string,
 *     tone: string
 * }
 */
final class CarBadges
{
    /** Maximum number of badges that one car shows. */
    public const MAX_BADGES = 2;

    /** Maximum number of car IDs in the decorateRows() bad-solddate log entry. */
    private const MAX_LOGGED_IDS = 10;

    /**
     * Badge definitions by key.
     *
     * `group`: in each group, only the badge with the highest priority shows.
     * `suppressedBy`: the badge does not show when one of these keys shows.
     *
     * @var array<string, BadgeDefinition>
     */
    private const BADGES = [
        'new' => [
            'label'        => 'New',
            'icon'         => null,
            'tooltip'      => 'Added to the registry in the last 90 days, or one of the 5 newest cars.',
            'tone'         => 'new',
            'priority'     => 100,
            'group'        => null,
            'suppressedBy' => [],
        ],
        'sold' => [
            'label'        => 'Sold',
            'icon'         => null,
            'tooltip'      => 'Reported sold by the owner — the car and its history stay in the registry.',
            'tone'         => 'sold',
            'priority'     => 90,
            'group'        => 'lifecycle',
            'suppressedBy' => [],
        ],
        'verified' => [
            'label'        => 'Verified',
            'icon'         => '✓',
            'tooltip'      => "The owner confirmed this car's details within the last year.",
            'tone'         => 'verified',
            'priority'     => 50,
            'group'        => 'lifecycle',
            'suppressedBy' => ['new'],
        ],
    ];

    // No instances — static pure-function holder, like VerificationEligibility.
    private function __construct()
    {
    }

    /**
     * Get the badge keys to show for a set of car states.
     *
     * Pure function. In each group, only the badge with the highest priority
     * stays. Then a badge is dropped when a key in its `suppressedBy` stays.
     * The result is sorted by priority, highest first, and has a maximum of
     * MAX_BADGES keys.
     *
     * @param bool $sold  True when the car has a sold date
     * @param bool $fresh True when the car's registry data is fresh (see CarRepository::isFresh())
     * @param bool $isNew True when the car is one of the "new" cars (cars list only)
     * @return list<string> Badge keys, highest priority first
     */
    public static function resolve(bool $sold, bool $fresh, bool $isNew): array
    {
        $flags = ['new' => $isNew, 'sold' => $sold, 'verified' => $fresh];
        $candidates = array_keys(array_filter($flags));

        $topOfGroup = [];
        foreach ($candidates as $key) {
            $group = self::BADGES[$key]['group'];
            if ($group === null) {
                continue;
            }
            if (!isset($topOfGroup[$group])
                || self::BADGES[$key]['priority'] > self::BADGES[$topOfGroup[$group]]['priority']
            ) {
                $topOfGroup[$group] = $key;
            }
        }

        $kept = array_values(array_filter(
            $candidates,
            static function (string $key) use ($topOfGroup): bool {
                $group = self::BADGES[$key]['group'];
                return $group === null || $topOfGroup[$group] === $key;
            }
        ));

        $kept = array_values(array_filter(
            $kept,
            static fn(string $key): bool => array_intersect(self::BADGES[$key]['suppressedBy'], $kept) === []
        ));

        usort(
            $kept,
            static fn(string $a, string $b): int => self::BADGES[$b]['priority'] <=> self::BADGES[$a]['priority']
        );

        return array_slice($kept, 0, self::MAX_BADGES);
    }

    /**
     * Get the badge keys for one car record.
     *
     * Sold is a `solddate` that is a real `Y-m-d` date (see parseSoldDate()). Fresh
     * comes from CarRepository::isFresh() with `last_verified` and
     * `owner_last_updated`. When the freshness dates are missing, have the
     * wrong type, or do not parse, the method logs one entry and treats the
     * car as not fresh. Sold still shows.
     *
     * @param object $car   Car record (for example Car::data()) with `solddate`,
     *                      `last_verified`, and `owner_last_updated`
     * @param bool   $isNew True to include the "new" badge (cars list only)
     * @return list<string> Badge keys, highest priority first
     */
    public static function forCar(object $car, bool $isNew = false): array
    {
        $sold = self::parseSoldDate($car->solddate ?? null, $car->id ?? 'unknown') !== null;

        return self::resolve($sold, self::isCarFresh($car), $isNew);
    }

    /**
     * Add badge keys to cars DataTables rows.
     *
     * Each row gets `badges` from resolve(): sold from a `solddate` that is a
     * real `Y-m-d` date (see parseSoldDate()), fresh from an `is_fresh` that
     * casts to 1 (the CarRepository::freshnessSql() column), and new when the
     * row `id` is in $newIds. A row without `is_fresh` is not fresh. The method
     * logs one entry per call for those rows, because the SELECT is wrong for
     * all of them. A row with a bad `solddate` is not sold. The method logs one
     * entry per call for those rows too, with the count and the first car IDs.
     * The method removes `is_fresh`, so the response does not expose it.
     * Rows can be objects (the default Database::results() shape) or
     * associative arrays. Object rows are cloned, so the input rows do not
     * change.
     *
     * @param array<int, object|array<string, mixed>> $rows   DataTables rows from the cars table
     * @param list<int>                               $newIds IDs of the "new" cars (CarShowcaseService::getNewCarIds())
     * @return array<int, object|array<string, mixed>> The rows with `badges` set and `is_fresh` removed
     */
    public static function decorateRows(array $rows, array $newIds): array
    {
        $newLookup = array_flip($newIds);
        $missingIsFresh = 0;
        $badSoldIds = [];

        foreach ($rows as $index => $row) {
            if (is_object($row)) {
                $row = clone $row;
                if (!property_exists($row, 'is_fresh')) {
                    $missingIsFresh++;
                }
                $sold = self::soldDateStatus($row->solddate ?? null);
                if ($sold === null) {
                    $badSoldIds[] = $row->id ?? 'unknown';
                }
                $row->badges = self::resolve(
                    $sold === true,
                    (int) ($row->is_fresh ?? 0) === 1,
                    isset($newLookup[(int) ($row->id ?? 0)])
                );
                unset($row->is_fresh);
            } else {
                if (!array_key_exists('is_fresh', $row)) {
                    $missingIsFresh++;
                }
                $sold = self::soldDateStatus($row['solddate'] ?? null);
                if ($sold === null) {
                    $badSoldIds[] = $row['id'] ?? 'unknown';
                }
                $row['badges'] = self::resolve(
                    $sold === true,
                    (int) ($row['is_fresh'] ?? 0) === 1,
                    isset($newLookup[(int) ($row['id'] ?? 0)])
                );
                unset($row['is_fresh']);
            }
            $rows[$index] = $row;
        }

        if ($badSoldIds !== []) {
            logger(
                0,
                LogCategories::LOG_CATEGORY_CAR_ERRORS,
                sprintf(
                    'CarBadges: %d of %d rows have a bad solddate, Sold badge not shown. Car IDs: %s',
                    count($badSoldIds),
                    count($rows),
                    implode(', ', array_map(
                        static fn(mixed $id): string => is_scalar($id) ? (string) $id : 'unknown',
                        array_slice($badSoldIds, 0, self::MAX_LOGGED_IDS)
                    )) . (count($badSoldIds) > self::MAX_LOGGED_IDS ? ', ...' : '')
                )
            );
        }

        if ($missingIsFresh > 0) {
            logger(
                0,
                LogCategories::LOG_CATEGORY_CAR_ERRORS,
                sprintf(
                    'CarBadges: %d of %d rows have no is_fresh column, Verified badge not shown. '
                    . 'The SELECT must include CarRepository::freshnessSql() AS is_fresh.',
                    $missingIsFresh,
                    count($rows)
                )
            );
        }

        return $rows;
    }

    /**
     * Get the display data for each badge, for JSON to the cars list JS.
     *
     * The precedence fields (`priority`, `group`, `suppressedBy`) are not
     * included: only resolve() applies them.
     *
     * @return array<string, PublicBadgeDefinition> Display data by badge key
     */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (self::BADGES as $key => $badge) {
            $definitions[$key] = [
                'label'    => $badge['label'],
                'icon'     => $badge['icon'],
                'tooltip'  => $badge['tooltip'],
                'tone'     => $badge['tone'],
            ];
        }

        return $definitions;
    }

    /**
     * Parse a solddate value. This is the one sold-date rule for all pages.
     *
     * `cars.solddate` is a DATE column, so a real value is a `Y-m-d` string.
     * Null and '' mean not sold and do not log. Any other value (a zero date
     * such as '0000-00-00', text that is not a date, a date that does not
     * exist such as '2025-02-30', or a non-string) means not sold and logs one
     * entry: the application connection runs with `sql_mode = ''`, so MySQL
     * can store a zero date.
     *
     * Pass `$logInvalid = false` when another call on the same request already
     * logs the same value, so one bad car gives one log entry.
     *
     * @param mixed $solddate   Value of `solddate` from the record
     * @param mixed $carId      Car ID from the record, for the log entry
     * @param bool  $logInvalid False to skip the log entry for a bad value
     * @return DateTimeImmutable|null The sold date at midnight, or null when the car is not sold
     */
    public static function parseSoldDate(mixed $solddate, mixed $carId = null, bool $logInvalid = true): ?DateTimeImmutable
    {
        if ($solddate === null || $solddate === '') {
            return null;
        }

        $parsed = self::parseValidSoldDate($solddate);
        if ($parsed !== null || !$logInvalid) {
            return $parsed;
        }

        logger(
            0,
            LogCategories::LOG_CATEGORY_CAR_ERRORS,
            sprintf(
                'CarBadges: car %s has a bad solddate, Sold badge not shown: %s',
                is_scalar($carId) ? (string) $carId : 'unknown',
                is_string($solddate) ? "'" . mb_substr($solddate, 0, 40) . "'" : get_debug_type($solddate)
            )
        );

        return null;
    }

    /**
     * Get the sold status of a solddate value, without a log entry.
     *
     * decorateRows() uses this so it can write one log entry per call.
     *
     * @param mixed $solddate Value of `solddate` from the row
     * @return bool|null True when sold, false when null or '', null when the value is bad
     */
    private static function soldDateStatus(mixed $solddate): ?bool
    {
        if ($solddate === null || $solddate === '') {
            return false;
        }

        return self::parseValidSoldDate($solddate) !== null ? true : null;
    }

    /**
     * Parse a value that must be an exact `Y-m-d` date that exists.
     *
     * @param mixed $solddate Value of `solddate` from the record
     * @return DateTimeImmutable|null The date at midnight, or null when the value is not a real `Y-m-d` date
     */
    private static function parseValidSoldDate(mixed $solddate): ?DateTimeImmutable
    {
        if (!is_string($solddate)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $solddate);
        if ($parsed === false || $parsed->format('Y-m-d') !== $solddate) {
            return null;
        }

        return $parsed;
    }

    /**
     * Decide if a car record is fresh, without an exception for bad data.
     *
     * A bad freshness date must not stop the page: the car shows no Verified
     * badge and the problem goes to the log.
     *
     * @param object $car Car record
     * @return bool True when CarRepository::isFresh() returns true for the record
     */
    private static function isCarFresh(object $car): bool
    {
        $carId = $car->id ?? 'unknown';
        $ownerLastUpdated = $car->owner_last_updated ?? null;
        $lastVerified = $car->last_verified ?? null;

        if (!is_string($ownerLastUpdated)) {
            self::logBadDates($carId, 'owner_last_updated is missing or is not a string (' . get_debug_type($ownerLastUpdated) . ')');
            return false;
        }
        if ($lastVerified !== null && !is_string($lastVerified)) {
            self::logBadDates($carId, 'last_verified is not a string or null (' . get_debug_type($lastVerified) . ')');
            return false;
        }

        try {
            return CarRepository::isFresh($lastVerified, $ownerLastUpdated);
        } catch (CarValidationException $e) {
            self::logBadDates($carId, $e->getMessage());
            return false;
        }
    }

    /**
     * Log one entry for a car whose freshness dates are not usable.
     *
     * @param mixed  $carId  Car ID from the record, or 'unknown'
     * @param string $reason Why the dates are not usable
     * @return void
     */
    private static function logBadDates(mixed $carId, string $reason): void
    {
        logger(
            0,
            LogCategories::LOG_CATEGORY_CAR_ERRORS,
            sprintf(
                'CarBadges: car %s has bad freshness dates, Verified badge not shown: %s',
                is_scalar($carId) ? (string) $carId : 'unknown',
                $reason
            )
        );
    }
}
