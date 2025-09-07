<?php



class Resize
{
    // *** Class variables
    private $image;
    private $width;
    private $height;
    private $imageResized;

    public function __construct($fileName)
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
    private function correctOrientation($fileName, $image)
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

    private function openImage($file)
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

    public function resizeImage($newWidth, $newHeight, $option = "auto")
    {
        // *** Get optimal width and height - based on $option
        $optionArray = $this->getDimensions($newWidth, $newHeight, $option);

        $optimalWidth  = $optionArray['optimalWidth'];
        $optimalHeight = $optionArray['optimalHeight'];


        // *** Resample - create image canvas of x, y size (ensure integers for GD functions)
        $this->imageResized = imagecreatetruecolor((int)round($optimalWidth), (int)round($optimalHeight));
        imagecopyresampled($this->imageResized, $this->image, 0, 0, 0, 0, (int)round($optimalWidth), (int)round($optimalHeight), $this->width, $this->height);


        // *** if option is 'crop', then crop too
        if ($option == 'crop') {
            $this->crop($optimalWidth, $optimalHeight, $newWidth, $newHeight);
        }
    }

    ## --------------------------------------------------------

    private function getDimensions($newWidth, $newHeight, $option)
    {

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

    private function getSizeByFixedHeight($newHeight)
    {
        $ratio = $this->width / $this->height;
        return $newHeight * $ratio;
    }

    private function getSizeByFixedWidth($newWidth)
    {
        $ratio = $this->height / $this->width;
        return $newWidth * $ratio;
    }

    private function getSizeByAuto($newWidth, $newHeight)
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

    private function getOptimalCrop($newWidth, $newHeight)
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

    private function crop($optimalWidth, $optimalHeight, $newWidth, $newHeight)
    {
        // *** Find center - this will be used for the crop (ensure integers for GD functions)
        $cropStartX = (int)round(($optimalWidth / 2) - ($newWidth / 2));
        $cropStartY = (int)round(($optimalHeight / 2) - ($newHeight / 2));

        $crop = $this->imageResized;

        // *** Now crop from center to exact requested size
        $this->imageResized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($this->imageResized, $crop, 0, 0, $cropStartX, $cropStartY, $newWidth, $newHeight, $newWidth, $newHeight);
    }

    ## --------------------------------------------------------

    public function saveImage($savePath, $imageQuality = "100")
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
                    imagepng($this->imageResized, $savePath, $invertScaleQuality);
                }
                break;

                // ... etc

            default:
                // *** No extension - No save.
                break;
        }

        imagedestroy($this->imageResized);
    }


    ## --------------------------------------------------------

}
