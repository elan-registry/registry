<?php

declare(strict_types=1);

namespace ElanRegistry\Reference;

/**
 * SeriesData - Static reference data for Lotus Elan production series.
 *
 * Provides production-year ranges and canonical series names for the
 * Lotus Elan series as compiled from factory records. All data is
 * static — no database access required.
 *
 * @package ElanRegistry\Reference
 * @since v2.26.0
 */
class SeriesData
{
    /**
     * Production year ranges indexed by series name.
     *
     * @var array<string, array{from: int, to: int}>
     */
    public const PRODUCTION_YEARS = [
        'S1'     => ['from' => 1963, 'to' => 1965],
        'S2'     => ['from' => 1965, 'to' => 1966],
        'S3'     => ['from' => 1966, 'to' => 1969],
        'S4'     => ['from' => 1968, 'to' => 1971],
        'Sprint' => ['from' => 1970, 'to' => 1973],
        '+2'     => ['from' => 1967, 'to' => 1969],
        '+2S'    => ['from' => 1969, 'to' => 1974],
        '+2S130' => ['from' => 1971, 'to' => 1974],
    ];

    /**
     * Return all canonical series names.
     *
     * @return list<string>
     */
    public static function allSeries(): array
    {
        return array_keys(self::PRODUCTION_YEARS);
    }

    /**
     * Return the production year range for a given series, or null if unknown.
     *
     * @param string $series Canonical series name (S1, S2, S3, S4, Sprint, +2, +2S, +2S130)
     * @return array{from: int, to: int}|null Year range, or null if the series is not recognised
     */
    public static function productionYears(string $series): ?array
    {
        return self::PRODUCTION_YEARS[$series] ?? null;
    }
}
