<?php

declare(strict_types=1);

use ElanRegistry\Car\CarImageProcessor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for Issue #2627: the Docker PHP upload limits must let one
 * car save post ELAN_IMAGE_MAX photos at the app per-file cap. If PHP rejects
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

    /**
     * The app per-file cap, read from the default of the $maxSize parameter,
     * so this test follows a change to the cap.
     */
    private function perFileCap(): int
    {
        $cap = (new ReflectionParameter([CarImageProcessor::class, 'validateFileUpload'], 'maxSize'))
            ->getDefaultValue();
        $this->assertIsInt($cap, 'validateFileUpload() $maxSize default must be an int');

        return $cap;
    }

    /**
     * One limit from the Docker ini, in bytes.
     */
    private function iniBytes(string $key): int
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
            $this->iniBytes('upload_max_filesize'),
            'upload_max_filesize must be at least the app per-file cap'
        );
    }

    public function testPostMaxSizeAllowsAllPhotosAtTheAppCapInOneSave(): void
    {
        $this->assertGreaterThanOrEqual(
            ELAN_IMAGE_MAX * $this->perFileCap(),
            $this->iniBytes('post_max_size'),
            'post_max_size must be at least ELAN_IMAGE_MAX x the app per-file cap'
        );
    }

    public function testPostMaxSizeIsAtLeastUploadMaxFilesize(): void
    {
        $this->assertGreaterThanOrEqual(
            $this->iniBytes('upload_max_filesize'),
            $this->iniBytes('post_max_size'),
            'post_max_size must stay >= upload_max_filesize'
        );
    }
}
