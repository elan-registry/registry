<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1931: the bootstrap's fallback load of config.php keeps these constants
 * defined even when users/init.php throws before loader.php.
 *
 * Do not load config.php from a test file: it would hide a broken fallback.
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
            'CRON_TRANSPORT_INTERVAL_MINUTES' => ['CRON_TRANSPORT_INTERVAL_MINUTES', 10],
        ];
    }

    #[DataProvider('constantProvider')]
    public function testConstantIsDefinedWithConfigValue(string $name, mixed $expected): void
    {
        $this->assertTrue(defined($name), "{$name} is not defined — bootstrap-integration.php's config.php fallback may have regressed");
        $this->assertSame($expected, constant($name));
    }
}
