<?php

declare(strict_types=1);

use ElanRegistry\Admin\MaintenanceStatusLabels;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit tests for MaintenanceStatusLabels (#1225).
 *
 * Pins the 3-way precedence (unknown > failure > cleanup-needed) used by
 * both maintenance.php's header warning chip and its backup-attention
 * alert — extracted from inline match(true) expressions specifically so
 * this branch logic is directly testable instead of only reachable via a
 * full page render.
 */
#[Group('fast')]
#[Group('unit')]
#[Group('admin')]
final class MaintenanceStatusLabelsTest extends TestCase
{
    /** @return array<string, array{bool, bool, string}> */
    public static function chipLabelProvider(): array
    {
        return [
            'unknown takes precedence over failure' => [true, true, 'Backup Status Unavailable'],
            'unknown alone'                         => [true, false, 'Backup Status Unavailable'],
            'failure alone'                         => [false, true, 'Backup Failure Detected'],
            'neither — cleanup needed default'      => [false, false, 'Backup Cleanup Needed'],
        ];
    }

    #[DataProvider('chipLabelProvider')]
    public function testChipLabel(bool $unknown, bool $failureDetected, string $expected): void
    {
        $this->assertSame($expected, MaintenanceStatusLabels::chipLabel($unknown, $failureDetected));
    }

    /** @return array<string, array{bool, bool, string}> */
    public static function alertHeadingProvider(): array
    {
        return [
            'unknown takes precedence over failure' => [true, true, 'Backup Status Unavailable'],
            'unknown alone'                         => [true, false, 'Backup Status Unavailable'],
            'failure alone'                         => [false, true, 'Backup Failure Detected'],
            'neither — cleanup recommended default' => [false, false, 'Backup Cleanup Recommended'],
        ];
    }

    #[DataProvider('alertHeadingProvider')]
    public function testAlertHeading(bool $unknown, bool $failureDetected, string $expected): void
    {
        $this->assertSame($expected, MaintenanceStatusLabels::alertHeading($unknown, $failureDetected));
    }
}
