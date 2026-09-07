<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression test for issue #1931.
 *
 * tests/bootstrap-integration.php runs users/init.php in a deliberately
 * non-fatal try/catch — if init.php throws before reaching
 * usersc/includes/loader.php, usersc/includes/config.php never loads and the
 * application constants Car and BackupManager read are left undefined. This
 * test asserts the bootstrap's fallback load of config.php keeps those
 * constants defined regardless, catching the failure mode #1931 describes: a
 * suite that only tells the truth in one execution order.
 *
 * No test file loads config.php itself — the three per-file guards that did
 * were removed in #1931, and the bootstrap fallback replaced them. Do not
 * reintroduce one: it would define these constants as a side effect of test
 * discovery and let this test pass while the bootstrap fallback is broken.
 *
 * The rows cover what the removed guards were protecting: ELAN_IMAGE_DIR for
 * Car, the BACKUP_RETENTION_* trio for BackupManager::getRetentionDays(), the
 * warning/lookback windows it also reads, and BACKUP_BASE_DIR, which
 * BackupRestorabilityTest builds its path from.
 */
#[Group('integration')]
final class BootstrapConstantsTest extends IntegrationTestCase
{
    /**
     * @return array<string, array{string, mixed}>
     */
    public static function constantProvider(): array
    {
        return [
            'ELAN_IMAGE_DIR' => ['ELAN_IMAGE_DIR', 'userimages/'],
            'ELAN_IMAGE_MAX' => ['ELAN_IMAGE_MAX', 6],
            'ELAN_IMAGE_UPLOAD_MAX_SIZE' => ['ELAN_IMAGE_UPLOAD_MAX_SIZE', 3.00],
            'ELAN_IMAGE_DISPLAY_MAX_SIZE' => ['ELAN_IMAGE_DISPLAY_MAX_SIZE', 2048],
            'ELAN_IMAGE_THUMBNAIL_SIZES' => ['ELAN_IMAGE_THUMBNAIL_SIZES', '100,300,768,1024,2048'],
            'TRANSFER_REQUEST_EXPIRY_DAYS' => ['TRANSFER_REQUEST_EXPIRY_DAYS', 30],
            'EMAIL_SUBJECT_PREFIX' => ['EMAIL_SUBJECT_PREFIX', '[ELANREGISTRY]'],
            'BACKUP_BASE_DIR' => ['BACKUP_BASE_DIR', 'backups/'],
            'BACKUP_RETENTION_AUTOMATED' => ['BACKUP_RETENTION_AUTOMATED', 7],
            'BACKUP_RETENTION_MANUAL' => ['BACKUP_RETENTION_MANUAL', 30],
            'BACKUP_RETENTION_ROLLBACK' => ['BACKUP_RETENTION_ROLLBACK', 30],
            'BACKUP_WARNING_THRESHOLD_DAYS' => ['BACKUP_WARNING_THRESHOLD_DAYS', 7],
            'BACKUP_FAILURE_LOOKBACK_DAYS' => ['BACKUP_FAILURE_LOOKBACK_DAYS', 7],
        ];
    }

    #[DataProvider('constantProvider')]
    public function testConstantIsDefinedWithConfigValue(string $name, mixed $expected): void
    {
        $this->assertTrue(defined($name), "{$name} is not defined — bootstrap-integration.php's config.php fallback may have regressed");
        $this->assertSame($expected, constant($name));
    }
}
