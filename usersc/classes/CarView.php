<?php

declare(strict_types=1);

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
     * @return string HTML string for the image
     * @throws ImageProcessingException If image data is invalid
     */
    public static function loadPicture(array $image, ?bool $thumbnail = null): string
    {
        // Input validation to prevent SonarQube issues
        if (empty($image) || !isset($image['path']) || empty($image['path'])) {
            throw new ImageProcessingException('Invalid image data provided');
        }

        $thumbsize = self::THUMBNAIL_SIZE;
        $resize = '-resized-';
        $html = "<!--Start loadPicture -->";
        
        $path = pathinfo($image['path']);

        if ($thumbnail) {
            $html .= '<img src="' . $path['dirname'] . "/" .
                $path['filename'] . $resize . $thumbsize . "." .
                $path['extension'] . '" width="100" height="75" alt="elan" loading="lazy" class="img-fluid"> ';
        } else {
            $html = '<img loading="lazy" class="card-img-top" src="' .
                $path['dirname'] . "/" . $path['filename'] . $resize . $thumbsize . "." .$path['extension'] . '"';
            $html .= ' sizes="50vw" ';
            $html .= ' srcset="';
            $html .= $path['dirname'] . "/" . $path['filename'] . '-resized-' . self::THUMBNAIL_SIZE . '.' . $path['extension'] . ' ' . self::THUMBNAIL_SIZE . 'w,';
            $html .= $path['dirname'] . "/" . $path['filename'] . '-resized-' . self::IMAGE_SIZE_SMALL . '.' . $path['extension'] . ' ' . self::IMAGE_SIZE_SMALL . 'w,';
            $html .= $path['dirname'] . "/" . $path['filename'] . '-resized-' . self::IMAGE_SIZE_MEDIUM . '.' . $path['extension'] . ' ' . self::IMAGE_SIZE_MEDIUM . 'w,';
            $html .= $path['dirname'] . "/" . $path['filename'] . '-resized-' . self::IMAGE_SIZE_LARGE . '.' . $path['extension'] . ' ' . self::IMAGE_SIZE_LARGE . 'w"';
            $html .= 'alt="Elan" > ';
        }
        $html .= '<!--End loadPicture -->';

        return $html;
    }

    /**
     * Display a responsive carousel for car images
     *
     * @param Car $car Car object with image data
     * @return string HTML string for the carousel
     */
    public static function displayCarousel(Car $car): string
    {
        $carImages = $car->images();
        $html = "<!--Start displayCarousel -->";
        $carouselId = random_int(self::CAROUSEL_MIN_ID, self::CAROUSEL_MAX_ID); // Use cryptographically secure random

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
            $html .= self::loadPicture($carImages[0]);
        } else {
            $html .= '<div id="slider"><div id="myCarousel-' . $carouselId .
                '" class="carousel slide shadow"> <div class="carousel-inner"><div class="carousel-inner">';

            $class = 'carousel-item active';

            foreach ($carImages as $key => $image) {
                $html .= "<div class='" . $class . "' data-slide-number='" . $key . "'>";
                $html .= self::loadPicture($image);
                $html .= '</div>';
                $class = 'carousel-item';
            }
            
            $html .= '</div>
                    <a class="carousel-control-prev" href="#myCarousel-' . $carouselId . '" role="button" data-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        <span class="sr-only">Previous</span>
                    </a>
                    <a class="carousel-control-next" href="#myCarousel-' . $carouselId . '" role="button" data-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        <span class="sr-only">Next</span>
                    </a>
                </div>
                <ul class="carousel-indicators list-inline mx-auto border px-0">';
                
            foreach ($carImages as $key => $image) {
                $html .= '<li class="list-inline-item active">';
                $html .= '<a id="carousel-selector-' . $key . '" class="selected" data-slide-to="'
                    . $key . '" data-target="#myCarousel-' . $carouselId . '">';
                $html .= self::loadPicture($image, true);
                $html .= '</a> </li>';
            }
            $html .= '</ul></div></div>';
        }
        $html .= '<!--End displayCarousel -->';

        return $html;
    }
}