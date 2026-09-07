<?php

declare(strict_types=1);

use ElanRegistry\Car\CarImageProcessor;
use ElanRegistry\Resize;

/**
 * Shared filesystem fixture helpers for car-image integration tests.
 *
 * Every helper takes the image directory as an explicit parameter rather than
 * reading a single `$this->imageDir` property: merge tests operate on two
 * directories at once (a source car's and a target car's), so a directory
 * closed over by the trait would not serve them.
 *
 * This is a trait rather than an intermediate base class because the consuming
 * test classes need different setUp() shapes (one car vs. two, authenticated
 * vs. not) while both already extend IntegrationTestCase.
 *
 * Consumers must call initThumbnailSizes() in setUp() before using any helper
 * that generates or inspects resized variants, and must create the directories
 * they pass in themselves.
 */
trait CarImageFixtureTrait
{
    /**
     * Thumbnail sizes generated per upload, read from the same
     * ELAN_IMAGE_THUMBNAIL_SIZES constant app/api/cars/save.php's
     * uploadImages() uses (#1067 — was a $settings->elan_image_thumbnail_sizes
     * DB read prior to this), so a production config change can't silently
     * drift out of sync with these tests.
     *
     * @var list<int>
     */
    private array $thumbnailSizes;

    /**
     * Populate $thumbnailSizes from the ELAN_IMAGE_THUMBNAIL_SIZES constant.
     *
     * Call from setUp() before any helper below that touches resized variants.
     */
    private function initThumbnailSizes(): void
    {
        $this->thumbnailSizes = array_map('intval', array_map('trim', explode(',', ELAN_IMAGE_THUMBNAIL_SIZES)));
    }

    /**
     * Replicate uploadImages()'s real primitives: secure filename, base file on
     * disk, then one GD resize per configured thumbnail size.
     *
     * @param string $dir Absolute directory to write into, with a trailing slash
     *
     * @return string The base filename (never a resized variant name)
     */
    private function uploadOneTestImage(string $dir): string
    {
        $filename = CarImageProcessor::generateSecureFilename('jpg');
        $sourcePath = $dir . $filename;
        $this->makeTestJpeg($sourcePath);

        foreach ($this->thumbnailSizes as $size) {
            $resizeObj = new Resize($sourcePath);
            $resizeObj->resizeImage($size, $size, 'auto');
            $resizeObj->saveImage($this->variantPath($dir, $filename, $size), 80);
        }

        return $filename;
    }

    /**
     * Write a real, valid JPEG — GD and exif_imagetype() reject dummy content.
     *
     * @param string $path Absolute path of the JPEG to write
     */
    private function makeTestJpeg(string $path, int $width = 40, int $height = 30): void
    {
        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            $this->fail('GD could not create a truecolor image canvas');
        }

        $color = imagecolorallocate($img, 200, 30, 30);
        if ($color === false) {
            $this->fail('GD could not allocate a colour for the test image');
        }

        imagefill($img, 0, 0, $color);
        if (!imagejpeg($img, $path, 80)) {
            $this->fail("GD could not write the test JPEG to {$path}");
        }
    }

    /**
     * Absolute path of one resized variant for a base filename and target size.
     *
     * @param string $dir Absolute directory holding the variant, with a trailing slash
     */
    private function variantPath(string $dir, string $filename, int $size): string
    {
        $baseName = pathinfo($filename, PATHINFO_FILENAME);

        return $dir . $baseName . '-resized-' . $size . '.jpg';
    }

    /**
     * Absolute paths of the resized variants generated for a base filename.
     *
     * @param string $dir Absolute directory holding the variants, with a trailing slash
     *
     * @return list<string>
     */
    private function variantPaths(string $dir, string $filename): array
    {
        return array_map(fn (int $size) => $this->variantPath($dir, $filename, $size), $this->thumbnailSizes);
    }

    /**
     * Assert the base file and every resized variant exist in the given directory.
     *
     * @param string $dir Absolute directory to check, with a trailing slash
     */
    private function assertUploadedFilesExist(string $dir, string $filename): void
    {
        $this->assertFileExists($dir . $filename);
        foreach ($this->variantPaths($dir, $filename) as $path) {
            $this->assertFileExists($path);
        }
    }

    /**
     * Confirms each variant was actually resized to its target width, not just
     * copied as a same-size file with a "-resized-" name. The source JPEG from
     * makeTestJpeg() is landscape (40x30), so Resize's 'auto' mode holds width to
     * the target size and derives height as round(size * 30 / 40).
     *
     * @param string $dir Absolute directory holding the variants, with a trailing slash
     */
    private function assertVariantsAreActuallyResized(string $dir, string $filename): void
    {
        foreach ($this->thumbnailSizes as $size) {
            $path = $this->variantPath($dir, $filename, $size);
            $dimensions = getimagesize($path);
            $this->assertIsArray($dimensions, "Could not read image dimensions for {$path}");
            $this->assertSame($size, $dimensions[0], "Variant {$path} has the wrong width");
            $this->assertSame(
                (int) round($size * 30 / 40),
                $dimensions[1],
                "Variant {$path} has the wrong height"
            );
        }
    }

    /**
     * Recursively delete a directory tree, reporting failures to STDERR rather
     * than failing the test — used from tearDown(), where a cleanup failure
     * must not mask the test's own result.
     */
    private function recursiveRemoveDirectory(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_link($path) || !is_dir($path)) {
                if (!unlink($path)) {
                    fwrite(STDERR, "NOTE: tearDown() failed to unlink {$path}\n");
                }
            } else {
                $this->recursiveRemoveDirectory($path);
            }
        }
        if (!rmdir($dir)) {
            fwrite(STDERR, "NOTE: tearDown() failed to remove directory {$dir}\n");
        }
    }
}
