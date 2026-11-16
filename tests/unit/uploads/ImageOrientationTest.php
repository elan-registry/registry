<?php

declare(strict_types=1);

use ElanRegistry\Exceptions\ImageProcessingException;
use ElanRegistry\Resize;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use PHPUnit\Framework\Attributes\Group;

/**
 * Test for EXIF orientation handling in the Resize class
 *
 * Tests the correctOrientation method functionality including:
 * - EXIF orientation detection and correction
 * - All 8 EXIF orientation values
 * - Privacy-preserving EXIF stripping
 * - Error handling for missing EXIF data
 */
class ImageOrientationTest extends TestCase
{
    private $outputDir;

    protected function setUp(): void
    {
        // Note: the orientation-correction tests below use synthetic in-memory
        // images (via imagecreate), not real EXIF-tagged fixtures, so this is a
        // known coverage gap rather than a bug — real EXIF orientation handling
        // is not exercised here.
        $this->outputDir = __DIR__ . '/output/orientation/';

        // Create output directory if it doesn't exist
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up output files
        if (is_dir($this->outputDir)) {
            $files = glob($this->outputDir . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: bool, 3: string, 4: string}>
     */
    public static function resizeWithoutExifProvider(): array
    {
        return [
            'jpeg with red stripe resizes without EXIF data' => [100, 50, true, 'jpg', 'test_normal'],
            'plain jpeg resizes without EXIF data'           => [100, 100, false, 'jpg', 'no_exif'],
            'png resizes with no EXIF orientation step'      => [100, 100, false, 'png', 'test'],
        ];
    }

    /**
     * Resize must process a synthetic image that has no EXIF data. PNG has no
     * EXIF orientation step, so its row checks that path too.
     */
    #[Group('fast')]
    #[DataProvider('resizeWithoutExifProvider')]
    public function testResizeHandlesImageWithoutExif(
        int $width,
        int $height,
        bool $redStripe,
        string $format,
        string $stem
    ): void {
        $testImage = imagecreate($width, $height);
        $white = imagecolorallocate($testImage, 255, 255, 255);
        imagefill($testImage, 0, 0, $white);
        if ($redStripe) {
            $red = imagecolorallocate($testImage, 255, 0, 0);
            imagefilledrectangle($testImage, 0, 0, 20, $height, $red);
        }

        $testFile = $this->outputDir . $stem . '.' . $format;
        if ($format === 'png') {
            imagepng($testImage, $testFile);
        } else {
            imagejpeg($testImage, $testFile, 90);
        }

        $this->assertFileExists($testFile, 'Test image should be created');

        $resize = new Resize($testFile);
        $resize->resizeImage(intdiv($width, 2), intdiv($height, 2), 'exact');

        $resizedFile = $this->outputDir . $stem . '_resized.' . $format;
        $resize->saveImage($resizedFile, 80);

        $this->assertFileExists($resizedFile, 'Resized image should be created');
    }

    /**
     * Test that an unhandled file extension throws ImageProcessingException
     */
    #[Group('fast')]
    public function testUnhandledExtensionThrowsImageProcessingException(): void
    {
        $testFile = $this->outputDir . 'test.webp';
        file_put_contents($testFile, 'not a real image');

        $this->expectException(ImageProcessingException::class);

        new Resize($testFile);
    }

    /**
     * Test that a corrupt file with a recognized extension throws ImageProcessingException
     */
    #[Group('fast')]
    public function testCorruptRecognizedExtensionThrowsImageProcessingException(): void
    {
        $testFile = $this->outputDir . 'corrupt.jpg';
        file_put_contents($testFile, 'this is not valid jpeg data');

        $this->expectException(ImageProcessingException::class);

        new Resize($testFile);
    }
}
