<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for Issue #2627: the Docker PHP upload limits must let one
 * car save post ELAN_IMAGE_MAX photos at the server limit for each photo
 * (ELAN_IMAGE_SERVER_MAX_SIZE). If PHP rejects
 * the upload first, the owner gets no "Photo is too large" message.
 * See `docs/development/ENVIRONMENT.md`, "PHP upload and memory limits".
 *
 * @issue 2627
 * @link https://github.com/elan-registry/registry/issues/2627
 * @category regression
 */
#[Group('regression')]
final class Issue2627RegressionTest extends TestCase
{
    private const INI = __DIR__ . '/../../../docker/php.ini-overrides.ini';
    private const CONFIG = __DIR__ . '/../../../usersc/includes/config.php';

    /**
     * One numeric constant from usersc/includes/config.php. The unit bootstrap
     * defines its own copy and does not load config.php, so this test reads
     * the real file to follow a change there.
     */
    private function configValue(string $name): float
    {
        $src = file_get_contents(self::CONFIG);
        $this->assertIsString($src, 'usersc/includes/config.php must be readable');
        $found = preg_match("/define\\('" . $name . "',\\s*([0-9.]+)\\)/", $src, $m);
        $this->assertSame(1, $found, "usersc/includes/config.php must define {$name} as a number");

        return (float) $m[1];
    }

    /**
     * The server limit for each photo in bytes, from config.php.
     */
    private function perFileCap(): int
    {
        return (int) round($this->configValue('ELAN_IMAGE_SERVER_MAX_SIZE') * 1048576);
    }

    /**
     * One value from the Docker ini, with PHP's K/M/G shorthand applied.
     */
    private function iniQuantity(string $key): int
    {
        $ini = parse_ini_file(self::INI, false, INI_SCANNER_RAW);
        $this->assertIsArray($ini, 'docker/php.ini-overrides.ini must parse');
        $this->assertArrayHasKey($key, $ini, "docker/php.ini-overrides.ini must set {$key}");

        return ini_parse_quantity((string) $ini[$key]);
    }

    public function testUploadMaxFilesizeAllowsOnePhotoAtTheAppCap(): void
    {
        $this->assertGreaterThanOrEqual(
            $this->perFileCap(),
            $this->iniQuantity('upload_max_filesize'),
            'upload_max_filesize must be at least ELAN_IMAGE_SERVER_MAX_SIZE'
        );
    }

    public function testPostMaxSizeAllowsAllPhotosAtTheAppCapInOneSave(): void
    {
        $this->assertGreaterThanOrEqual(
            (int) $this->configValue('ELAN_IMAGE_MAX') * $this->perFileCap(),
            $this->iniQuantity('post_max_size'),
            'post_max_size must be at least ELAN_IMAGE_MAX x ELAN_IMAGE_SERVER_MAX_SIZE'
        );
    }

    public function testMaxFileUploadsAllowsAllPhotosInOneSave(): void
    {
        $this->assertGreaterThanOrEqual(
            (int) $this->configValue('ELAN_IMAGE_MAX'),
            $this->iniQuantity('max_file_uploads'),
            'max_file_uploads must be at least ELAN_IMAGE_MAX'
        );
    }

    public function testServerLimitIsNotAboveTheBrowserLimit(): void
    {
        $this->assertLessThanOrEqual(
            $this->configValue('ELAN_IMAGE_UPLOAD_MAX_SIZE'),
            $this->configValue('ELAN_IMAGE_SERVER_MAX_SIZE'),
            'ELAN_IMAGE_SERVER_MAX_SIZE must not be above ELAN_IMAGE_UPLOAD_MAX_SIZE'
        );
    }

    public function testPostMaxSizeIsAtLeastUploadMaxFilesize(): void
    {
        $this->assertGreaterThanOrEqual(
            $this->iniQuantity('upload_max_filesize'),
            $this->iniQuantity('post_max_size'),
            'post_max_size must stay >= upload_max_filesize'
        );
    }
}
