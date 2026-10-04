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
 * class. The rule for which badges show, and in which order, is in resolve()
 * only. html() is the only badge renderer: the pages call it directly, and
 * decorateRows() uses it to send the cars list its badge HTML. To add a badge,
 * add one entry to BADGES and one line to resolve().
 *
 * The Vehicle Information card (account, car details, and vericode pages)
 * does not use badge keys from this class. It draws a fixed Sold stamp when
 * soldDate() returns a date. isSold() is the sold rule for all pages.
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
 *     tone: string
 * }
 */
final class CarBadges
{
    /**
     * Badge definitions by key.
     *
     * @var array<string, BadgeDefinition>
     */
    private const BADGES = [
        'new' => [
            'label'   => 'New',
            'icon'    => null,
            'tooltip' => 'Added to the registry in the last 90 days, or one of the 5 newest cars.',
            'tone'    => 'new',
        ],
        'sold' => [
            'label'   => 'Sold',
            'icon'    => null,
            'tooltip' => 'Reported sold by the owner — the car and its history stay in the registry.',
            'tone'    => 'sold',
        ],
        'verified' => [
            'label'   => 'Verified',
            'icon'    => '✓',
            'tooltip' => "The owner confirmed this car's details within the last year.",
            'tone'    => 'verified',
        ],
    ];

    // No instances — static pure-function holder, like VerificationEligibility.
    private function __construct()
    {
    }

    /**
     * Get the badge keys to show for a set of car states.
     *
     * Pure function. The rule:
     * - New shows when the car is new.
     * - Sold shows when the car is sold.
     * - Verified shows when the car is fresh, not sold, and not new.
     * The order is New, Sold, Verified.
     *
     * @param bool $sold  True when the car is sold (see isSold())
     * @param bool $fresh True when the car's registry data is fresh (see CarRepository::isFresh())
     * @param bool $isNew True when the car is one of the "new" cars (cars list only)
     * @return list<string> Badge keys, in display order
     */
    public static function resolve(bool $sold, bool $fresh, bool $isNew): array
    {
        return array_keys(array_filter([
            'new'      => $isNew,
            'sold'     => $sold,
            'verified' => $fresh && !$sold && !$isNew,
        ]));
    }

    /**
     * Get the badge keys for one car record.
     *
     * Sold comes from isSold(). Fresh comes from CarRepository::isFresh() with `last_verified` and
     * `owner_last_updated`. When the freshness dates are missing, have the
     * wrong type, or do not parse, the method logs one entry and treats the
     * car as not fresh. Sold still shows.
     *
     * @param object $car   Car record (for example Car::data()) with `solddate`,
     *                      `last_verified`, and `owner_last_updated`
     * @param bool   $isNew True to include the "new" badge (cars list only)
     * @return list<string> Badge keys, in display order
     */
    public static function forCar(object $car, bool $isNew = false): array
    {
        return self::resolve(self::isSold($car->solddate ?? null), self::isCarFresh($car), $isNew);
    }

    /**
     * Add badge keys and badge HTML to cars DataTables rows.
     *
     * Each row gets `badges` from resolve(): sold from isSold(), fresh from an
     * `is_fresh` that casts to 1 (the CarRepository::freshnessSql() column),
     * and new when the row `id` is in $newIds. Each row also gets `badges_html`
     * from listHtml(), which the cars list JS puts after the Details link.
     * A row without `is_fresh` is not fresh. The method logs one entry per
     * call for those rows, because the SELECT is wrong for all of them. The
     * method removes `is_fresh`, so the response does not expose it. Rows are
     * objects (the Database::results() shape). Each row is cloned, so the
     * input rows do not change.
     *
     * @param array<int, object> $rows   DataTables rows from the cars table
     * @param list<int>          $newIds IDs of the "new" cars (CarShowcaseService::getNewCarIds())
     * @return array<int, object> The rows with `badges` and `badges_html` set and `is_fresh` removed
     */
    public static function decorateRows(array $rows, array $newIds): array
    {
        $newLookup = array_flip($newIds);
        $missingIsFresh = 0;

        foreach ($rows as $index => $row) {
            $row = clone $row;
            if (!property_exists($row, 'is_fresh')) {
                $missingIsFresh++;
            }
            $row->badges = self::resolve(
                self::isSold($row->solddate ?? null),
                (int) ($row->is_fresh ?? 0) === 1,
                isset($newLookup[(int) ($row->id ?? 0)])
            );
            $row->badges_html = self::listHtml($row->badges);
            unset($row->is_fresh);
            $rows[$index] = $row;
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
     * Get the HTML for a list of badge keys.
     *
     * Each known key gives one focusable span with a Bootstrap tooltip, in the
     * order of $keys. Unknown keys give nothing. There is no wrapper element,
     * so the caller owns the container. There is no title or aria-label:
     * Bootstrap adds aria-describedby when the tooltip shows. All values are
     * escaped with htmlspecialchars().
     *
     * @param list<string> $keys  Badge keys, for example from forCar()
     * @param string       $style 'stamp' (rotated, account hero and details card) or 'flat' (pill, cars list)
     * @return string The badge spans, or '' when no key is known
     */
    public static function html(array $keys, string $style = 'flat'): string
    {
        $stampClass = $style === 'stamp' ? ' er-badge--stamp' : '';
        $html = '';

        foreach ($keys as $key) {
            if (!isset(self::BADGES[$key])) {
                continue;
            }
            $badge = self::BADGES[$key];
            $icon = $badge['icon'] === null
                ? ''
                : '<span aria-hidden="true">' . self::escape($badge['icon']) . '</span> ';
            $html .= '<span class="er-badge er-badge--' . self::escape($badge['tone']) . $stampClass . '"'
                . ' data-bs-toggle="tooltip" data-bs-title="' . self::escape($badge['tooltip']) . '" tabindex="0">'
                . $icon . self::escape($badge['label']) . "</span>\n";
        }

        return $html;
    }

    /**
     * Decide if a car is sold. This is the sold rule for all pages.
     *
     * A car is sold when `solddate` is not null and not ''. The save paths
     * (app/api/cars/save.php, CarValidator) accept only a real `Y-m-d` date,
     * so the value is not checked again here.
     *
     * @param mixed $solddate Value of `solddate` from the record
     * @return bool True when the car is sold
     */
    public static function isSold(mixed $solddate): bool
    {
        return $solddate !== null && $solddate !== '';
    }

    /**
     * Get the sold date for display.
     *
     * @param mixed $solddate Value of `solddate` from the record
     * @return DateTimeImmutable|null The sold date at midnight, or null when the car is not sold or the value is not a `Y-m-d` string
     */
    public static function soldDate(mixed $solddate): ?DateTimeImmutable
    {
        if (!self::isSold($solddate) || !is_string($solddate)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $solddate);

        return $parsed === false ? null : $parsed;
    }

    /**
     * Get the cars list badge row: flat badges in the `er-badges` wrapper.
     *
     * The wrapper is outside the Details link in the list cell, because a
     * focusable span inside <a> is a nested interactive element (WCAG 4.1.2).
     *
     * @param list<string> $keys Badge keys from resolve()
     * @return string The wrapped badges, or '' when there are no badges
     */
    private static function listHtml(array $keys): string
    {
        $badges = self::html($keys);

        return $badges === '' ? '' : '<div class="er-badges d-flex flex-wrap gap-1 mt-1">' . $badges . '</div>';
    }

    /**
     * Escape a value for HTML text or a quoted attribute.
     *
     * @param string $value Raw value
     * @return string The escaped value
     */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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
