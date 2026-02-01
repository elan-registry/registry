<?php

declare(strict_types=1);

/**
 * Image Resize Class with EXIF Orientation Support
 * 
 * Handles image resizing, EXIF orientation correction, and privacy-preserving
 * metadata removal for the Elan Registry application.
 * 
 * @author Elan Registry Development Team
 * @version 2.7.1
 */
class Resize
{
    private $image;
    private int $width;
    private int $height;
    private $imageResized;

    public function __construct(string $fileName)
    {
        // *** Open up the file
        $this->image = $this->openImage($fileName);

        // *** Apply EXIF orientation correction if needed
        $this->image = $this->correctOrientation($fileName, $this->image);

        // *** Get width and height (after orientation correction)
        $this->width  = imagesx($this->image);
        $this->height = imagesy($this->image);
    }

    ## --------------------------------------------------------

    /**
     * Correct image orientation based on EXIF data
     * Handles all 8 EXIF orientation values and strips EXIF data for privacy
     */
    private function correctOrientation(string $fileName, GdImage $image): GdImage
    {
        // Only process JPEG files for EXIF orientation
        $extension = strtolower(strrchr($fileName, '.'));
        if ($extension !== '.jpg' && $extension !== '.jpeg') {
            return $image;
        }

        // Check if EXIF extension is available
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        // Read EXIF orientation data
        $exif = @exif_read_data($fileName);
        if (!$exif || !isset($exif['Orientation'])) {
            return $image;
        }

        $orientation = $exif['Orientation'];

        // Apply rotation/flipping based on EXIF orientation value
        switch ($orientation) {
            case 2:
                // Horizontal flip
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                // 180° rotation
                $image = imagerotate($image, 180, 0);
                break;
            case 4:
                // Vertical flip
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                // Horizontal flip + 90° CCW rotation
                imageflip($image, IMG_FLIP_HORIZONTAL);
                $image = imagerotate($image, -90, 0);
                break;
            case 6:
                // 90° CW rotation (most common mobile issue)
                $image = imagerotate($image, -90, 0);
                break;
            case 7:
                // Horizontal flip + 90° CW rotation
                imageflip($image, IMG_FLIP_HORIZONTAL);
                $image = imagerotate($image, 90, 0);
                break;
            case 8:
                // 90° CCW rotation
                $image = imagerotate($image, 90, 0);
                break;
            case 1:
            default:
                // Normal orientation, no correction needed
                break;
        }

        return $image;
    }

    ## --------------------------------------------------------

    private function openImage(string $file): GdImage|false
    {
        // *** Get extension
        $extension = strtolower(strrchr($file, '.'));

        switch ($extension) {
            case '.jpg':
            case '.jpeg':
                $img = @imagecreatefromjpeg($file);
                break;
            case '.gif':
                $img = @imagecreatefromgif($file);
                break;
            case '.png':
                $img = @imagecreatefrompng($file);
                break;
            default:
                $img = false;
                break;
        }
        return $img;
    }

    ## --------------------------------------------------------

    /**
     * Resize the image to the given dimensions using the specified method
     *
     * @param int $newWidth Target width in pixels
     * @param int $newHeight Target height in pixels
     * @param string $option Resize method: exact, portrait, landscape, auto, or crop
     * @return void
     */
    public function resizeImage(int $newWidth, int $newHeight, string $option = "auto"): void
    {
        // *** Get optimal width and height - based on $option
        $optionArray = $this->getDimensions($newWidth, $newHeight, $option);

        $optimalWidth  = $optionArray['optimalWidth'];
        $optimalHeight = $optionArray['optimalHeight'];


        // *** Resample - create image canvas of x, y size (ensure integers for GD functions)
        $width = $this->toInt($optimalWidth);
        $height = $this->toInt($optimalHeight);
        
        $this->imageResized = imagecreatetruecolor($width, $height);
        imagecopyresampled($this->imageResized, $this->image, 0, 0, 0, 0, $width, $height, $this->width, $this->height);


        // *** if option is 'crop', then crop too
        if ($option == 'crop') {
            $this->crop($optimalWidth, $optimalHeight, $newWidth, $newHeight);
        }
    }

    ## --------------------------------------------------------

    private function getDimensions(int $newWidth, int $newHeight, string $option): array
    {

        $optimalWidth = $newWidth;
        $optimalHeight = $newHeight;

        switch ($option) {
            case 'exact':
                $optimalWidth = $newWidth;
                $optimalHeight = $newHeight;
                break;
            case 'portrait':
                $optimalWidth = $this->getSizeByFixedHeight($newHeight);
                $optimalHeight = $newHeight;
                break;
            case 'landscape':
                $optimalWidth = $newWidth;
                $optimalHeight = $this->getSizeByFixedWidth($newWidth);
                break;
            case 'auto':
                $optionArray = $this->getSizeByAuto($newWidth, $newHeight);
                $optimalWidth = $optionArray['optimalWidth'];
                $optimalHeight = $optionArray['optimalHeight'];
                break;
            case 'crop':
                $optionArray = $this->getOptimalCrop($newWidth, $newHeight);
                $optimalWidth = $optionArray['optimalWidth'];
                $optimalHeight = $optionArray['optimalHeight'];
                break;
            default:
                break;
        }
        return array('optimalWidth' => $optimalWidth, 'optimalHeight' => $optimalHeight);
    }

    ## --------------------------------------------------------

    private function getSizeByFixedHeight(int $newHeight): float
    {
        $ratio = $this->width / $this->height;
        return $newHeight * $ratio;
    }

    private function getSizeByFixedWidth(int $newWidth): float
    {
        $ratio = $this->height / $this->width;
        return $newWidth * $ratio;
    }

    private function getSizeByAuto(int $newWidth, int $newHeight): array
    {
        if ($this->height < $this->width) {
            // *** Image to be resized is wider (landscape)
            $optimalWidth = $newWidth;
            $optimalHeight = $this->getSizeByFixedWidth($newWidth);
        } elseif ($this->height > $this->width) {
            // *** Image to be resized is taller (portrait)
            $optimalWidth = $this->getSizeByFixedHeight($newHeight);
            $optimalHeight = $newHeight;
        } else {
            // *** Image to be resizerd is a square
            if ($newHeight < $newWidth) {
                $optimalWidth = $newWidth;
                $optimalHeight = $this->getSizeByFixedWidth($newWidth);
            } elseif ($newHeight > $newWidth) {
                $optimalWidth = $this->getSizeByFixedHeight($newHeight);
                $optimalHeight = $newHeight;
            } else {
                // *** Sqaure being resized to a square
                $optimalWidth = $newWidth;
                $optimalHeight = $newHeight;
            }
        }

        return array('optimalWidth' => $optimalWidth, 'optimalHeight' => $optimalHeight);
    }

    ## --------------------------------------------------------

    private function getOptimalCrop(int $newWidth, int $newHeight): array
    {

        $heightRatio = $this->height / $newHeight;
        $widthRatio  = $this->width /  $newWidth;

        if ($heightRatio < $widthRatio) {
            $optimalRatio = $heightRatio;
        } else {
            $optimalRatio = $widthRatio;
        }

        $optimalHeight = $this->height / $optimalRatio;
        $optimalWidth  = $this->width  / $optimalRatio;

        return array('optimalWidth' => $optimalWidth, 'optimalHeight' => $optimalHeight);
    }

    ## --------------------------------------------------------

    private function crop(float $optimalWidth, float $optimalHeight, int $newWidth, int $newHeight): void
    {
        // *** Find center - this will be used for the crop (ensure integers for GD functions)
        $cropStartX = $this->toInt(($optimalWidth / 2) - ($newWidth / 2));
        $cropStartY = $this->toInt(($optimalHeight / 2) - ($newHeight / 2));

        $crop = $this->imageResized;

        // *** Now crop from center to exact requested size
        $this->imageResized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($this->imageResized, $crop, 0, 0, $cropStartX, $cropStartY, $newWidth, $newHeight, $newWidth, $newHeight);
    }

    ## --------------------------------------------------------

    /**
     * Save the resized image to the specified path
     *
     * @param string $savePath File path to save the image to
     * @param int $imageQuality Image quality from 0 to 100
     * @return void
     */
    public function saveImage(string $savePath, int $imageQuality = 100): void
    {
        // *** Get extension
        $extension = strrchr($savePath, '.');
        $extension = strtolower($extension);

        switch ($extension) {
            case '.jpg':
            case '.jpeg':
                if (imagetypes() & IMG_JPG) {
                    imagejpeg($this->imageResized, $savePath, $imageQuality);
                }
                break;

            case '.gif':
                if (imagetypes() & IMG_GIF) {
                    imagegif($this->imageResized, $savePath);
                }
                break;

            case '.png':
                // *** Scale quality from 0-100 to 0-9
                $scaleQuality = round(($imageQuality / 100) * 9);

                // *** Invert quality setting as 0 is best, not 9
                $invertScaleQuality = 9 - $scaleQuality;

                if (imagetypes() & IMG_PNG) {
                    imagepng($this->imageResized, $savePath, (int) $invertScaleQuality);
                }
                break;

                // ... etc

            default:
                // *** No extension - No save.
                break;
        }
    }

    ## --------------------------------------------------------

    /**
     * Safely convert float/numeric values to integers for GD functions
     * 
     * This method ensures PHP 8+ compatibility by properly handling
     * float-to-int conversions without deprecation warnings.
     * 
     * @param float|int $value The numeric value to convert
     * @return int The safely converted integer value
     */
    private function toInt(float|int $value): int
    {
        return (int) round((float) $value);
    }

    ## --------------------------------------------------------

}
