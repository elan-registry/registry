<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * TestJpeg - Write a real, small JPEG for a test
 *
 * GD and exif_imagetype() reject dummy content, so a car-image test needs a
 * real JPEG. Shared by the unit and integration tiers. A different colour
 * gives different bytes, for tests that compare file hashes.
 *
 * @package Tests\Support
 * @since v2.32.0
 * @see https://github.com/elan-registry/registry/issues/2644
 */
final class TestJpeg
{
    /**
     * Write a solid-colour JPEG. A GD failure fails the current test.
     *
     * @param string $path   Absolute path of the JPEG to write
     * @param int    $width  Width in pixels
     * @param int    $height Height in pixels
     * @param int    $red    Red value, 0-255
     * @param int    $green  Green value, 0-255
     * @param int    $blue   Blue value, 0-255
     */
    public static function write(
        string $path,
        int $width = 40,
        int $height = 30,
        int $red = 200,
        int $green = 30,
        int $blue = 30,
    ): void {
        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            Assert::fail('GD could not create a truecolor image canvas');
        }

        $color = imagecolorallocate($img, $red, $green, $blue);
        if ($color === false) {
            Assert::fail('GD could not allocate a colour for the test image');
        }

        imagefill($img, 0, 0, $color);
        if (!imagejpeg($img, $path, 80)) {
            Assert::fail("GD could not write the test JPEG to {$path}");
        }
    }
}
