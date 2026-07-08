<?php

declare(strict_types=1);

namespace ElanRegistry;

use ElanRegistry\Car\Car;
use ElanRegistry\Exceptions\ImageProcessingException;

/**
 * CarView - Static utility class for car display and view functions
 *
 * This class provides static methods for car display, image processing,
 * and HTML generation operations used across multiple pages.
 * Separated from data operations following proper MVC patterns.
 */
class CarView
{
    // Image size constants to avoid magic numbers
    private const THUMBNAIL_SIZE = 100;
    private const IMAGE_SIZE_SMALL = 300;
    private const IMAGE_SIZE_MEDIUM = 768;
    private const IMAGE_SIZE_LARGE = 1024;
    
    // Carousel configuration constants
    private const CAROUSEL_MIN_ID = 1000;
    private const CAROUSEL_MAX_ID = 9999;
    /**
     * Load and display a car picture with responsive image sizing
     *
     * @param array $image Image data array with path information
     * @param bool|null $thumbnail Whether to display as thumbnail (null = full size)
     * @param bool $isPrimary Whether this is the primary (first) image - if true, eager load instead of lazy
     * @return string HTML string for the image
     * @throws ImageProcessingException If image data is invalid
     */
    public static function loadPicture(array $image, ?bool $thumbnail = null, bool $isPrimary = false): string
    {
        if (empty($image) || !isset($image['path']) || empty($image['path'])) {
            throw new ImageProcessingException('Invalid image data provided');
        }

        $thumbsize = self::THUMBNAIL_SIZE;
        $resize = '-resized-';
        $html = "<!--Start loadPicture -->";

        $path = pathinfo($image['path']);
        $dir  = htmlspecialchars((string) $path['dirname'], ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars((string) $path['filename'], ENT_QUOTES, 'UTF-8');
        $ext  = htmlspecialchars((string) $path['extension'], ENT_QUOTES, 'UTF-8');

        if ($thumbnail) {
            $html .= '<img src="' . $dir . '/' .
                $name . $resize . $thumbsize . '.' .
                $ext . '" width="100" height="75" alt="elan" loading="lazy" class="img-fluid"> ';
        } else {
            // Only lazy load images that are not the primary (first) image in a carousel
            $loadingAttr = $isPrimary ? '' : 'loading="lazy" ';
            $html = '<img ' . $loadingAttr . 'class="card-img-top" src="' .
                $dir . '/' . $name . $resize . $thumbsize . '.' . $ext . '"';
            $html .= ' sizes="50vw" ';
            $html .= ' width="100" height="100" ';
            $html .= ' srcset="';
            $html .= $dir . '/' . $name . '-resized-' . self::THUMBNAIL_SIZE . '.' . $ext . ' ' . self::THUMBNAIL_SIZE . 'w,';
            $html .= $dir . '/' . $name . '-resized-' . self::IMAGE_SIZE_SMALL . '.' . $ext . ' ' . self::IMAGE_SIZE_SMALL . 'w,';
            $html .= $dir . '/' . $name . '-resized-' . self::IMAGE_SIZE_MEDIUM . '.' . $ext . ' ' . self::IMAGE_SIZE_MEDIUM . 'w,';
            $html .= $dir . '/' . $name . '-resized-' . self::IMAGE_SIZE_LARGE . '.' . $ext . ' ' . self::IMAGE_SIZE_LARGE . 'w"';
            $html .= 'alt="Elan" > ';
        }
        $html .= '<!--End loadPicture -->';

        return $html;
    }

    /**
     * Display a responsive carousel for car images
     *
     * @param Car $car Car object with image data
     * @param int|null $instanceId Stable ID for carousel DOM IDs. Pass a page-unique integer
     *                             (e.g. the car ID) when rendering multiple carousels on one page
     *                             to prevent DOM ID collisions. Defaults to a random int when null.
     * @return string HTML string for the carousel
     */
    public static function displayCarousel(Car $car, ?int $instanceId = null): string
    {
        $carImages = $car->images();
        $html = "<!--Start displayCarousel -->";
        $carouselId = $instanceId ?? random_int(self::CAROUSEL_MIN_ID, self::CAROUSEL_MAX_ID);

        $count = count($carImages);
        if ($count === 0 || $carImages[0] == '') {
            // No images or image name is blank - display placeholder
            $html .= '<div class="photo-placeholder text-center py-5 rounded d-flex flex-column justify-content-center">';
            $html .= '<i class="fas fa-camera text-muted mb-3" style="font-size: 4rem;"></i>';
            $html .= '<h5 class="text-muted mb-2">No Photos Available</h5>';
            $html .= '<p class="text-muted small mb-0">Photos for this car have not been uploaded yet.</p>';
            $html .= '</div>';
            $html .= '<!--End displayCarousel -->';
            return $html;
        }

        if ($count === 1) {
            $html .= self::loadPicture($carImages[0], false, true);
        } else {
            $html .= '<div><div id="myCarousel-' . $carouselId .
                '" class="carousel slide shadow"> <div class="carousel-inner"><div class="carousel-inner">';

            $class = 'carousel-item active';

            foreach ($carImages as $key => $image) {
                $html .= "<div class='" . $class . "' data-slide-number='" . $key . "'>";
                // First image is primary (above the fold) - eager load it
                $html .= self::loadPicture($image, false, $key === 0);
                $html .= '</div>';
                $class = 'carousel-item';
            }
            
            $html .= '</div>
                    <button class="carousel-control-prev" type="button" data-bs-target="#myCarousel-' . $carouselId . '" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Previous</span>
                    </button>
                    <button class="carousel-control-next" type="button" data-bs-target="#myCarousel-' . $carouselId . '" data-bs-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Next</span>
                    </button>
                </div>
                <ul class="carousel-indicators list-inline mx-auto border px-0">';
                
            foreach ($carImages as $key => $image) {
                $html .= '<li class="list-inline-item active">';
                $html .= '<a id="carousel-selector-' . $carouselId . '-' . $key . '" class="selected" data-bs-slide-to="'
                    . $key . '" data-bs-target="#myCarousel-' . $carouselId . '">';
                $html .= self::loadPicture($image, true);
                $html .= '</a> </li>';
            }
            $html .= '</ul></div></div>';
        }
        $html .= '<!--End displayCarousel -->';

        return $html;
    }
}