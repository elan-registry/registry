<?php

declare(strict_types=1);

use ElanRegistry\AppConstants;
use PHPUnit\Framework\TestCase;

/**
 * AppConstantsTest
 *
 * Regression coverage for GitHub issue #947: DATETIME_FORMAT used 'G' (no leading zero)
 * instead of 'H' (zero-padded 24-hour), causing inconsistent hour strings for hours 0–9.
 *
 * @issue 947
 * @link https://github.com/unibrain1/elanregistry/issues/947
 */
class AppConstantsTest extends TestCase
{
    /**
     * Regression for #947: morning hours must be zero-padded.
     *
     * Before the fix, hour 9 produced "9:30:00"; after, it produces "09:30:00".
     */
    public function testDatetimeFormatProducesZeroPaddedHour(): void
    {
        $timestamp = mktime(9, 30, 0, 1, 1, 2025);
        $formatted = date(AppConstants::DATETIME_FORMAT, $timestamp);

        $this->assertEquals('2025-01-01 09:30:00', $formatted, 'Hour 9 must be zero-padded to 09');
    }

    public function testDatetimeFormatProducesTwoDigitHourForDoubleDigits(): void
    {
        $timestamp = mktime(14, 5, 3, 6, 15, 2024);
        $formatted = date(AppConstants::DATETIME_FORMAT, $timestamp);

        $this->assertEquals('2024-06-15 14:05:03', $formatted);
    }

    public function testDatetimeFormatMidnightIsZeroPadded(): void
    {
        $timestamp = mktime(0, 0, 0, 3, 7, 2025);
        $formatted = date(AppConstants::DATETIME_FORMAT, $timestamp);

        $this->assertEquals('2025-03-07 00:00:00', $formatted, 'Midnight (hour 0) must be 00');
    }

    public function testDatetimeFormatMatchesMysqlDatetimePattern(): void
    {
        $formatted = date(AppConstants::DATETIME_FORMAT);

        // MySQL DATETIME: YYYY-MM-DD HH:MM:SS with exactly 2-digit hour
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $formatted,
            'Format must produce exactly YYYY-MM-DD HH:MM:SS (2-digit hour required)'
        );
    }
}
