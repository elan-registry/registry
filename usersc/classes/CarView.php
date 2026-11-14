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

    // Schema.org Car JSON-LD constants (#1371) — see buildCarSchema() docblock
    // for why FHC-preairflow appears in BODY_TYPE_MAP but not in
    // VARIANTS_WITHOUT_VEHICLE_CONFIGURATION.
    private const BODY_TYPE_MAP = [
        'FHC' => 'Coupe',
        'FHC-preairflow' => 'Coupe',
        'DHC' => 'Convertible',
        'Roadster' => 'Roadster',
    ];
    private const VARIANTS_WITHOUT_VEHICLE_CONFIGURATION = ['FHC', 'DHC', 'Roadster'];

    // Per-car page title and description (#1528). Upstream header1_must_include.php
    // echoes $pageTitle without escaping, so each part must match a closed
    // character set, and the final string is filtered again. The year range is
    // the one CarValidator enforces (Lotus Elan production years).
    private const TITLE_YEAR_MIN = 1963;
    private const TITLE_YEAR_MAX = 1974;
    private const TITLE_CHASSIS_PATTERN = '~^[A-Za-z0-9/ .-]{3,15}\z~';
    private const TITLE_MODEL_PART_PATTERN = '~^[A-Za-z0-9+/ .-]{1,20}\z~';
    private const TITLE_UNSAFE_CHARACTERS = '~[^A-Za-z0-9 /.+,()#:-]~';

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
            $html .= '<i class="fas fa-camera text-muted mb-3" style="font-size: 4rem;" aria-hidden="true"></i>';
            // Not a heading: callers put the carousel under h2, h3 or h4, so any
            // fixed level would skip a level on some page.
            $html .= '<p class="h5 text-muted mb-2">No Photos Available</p>';
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

    /**
     * Build a Schema.org Car JSON-LD payload for a car detail page (#1371).
     *
     * `bodyType` is only set for genuine physical body shapes (Roadster/FHC/DHC,
     * including the FHC-preairflow sub-configuration) — the `variant` column also
     * holds non-body-shape values like "Federal" (US-market emissions/safety spec)
     * and "Race" (factory race cars), confirmed against the live `cars` table.
     * Those are preserved via `vehicleConfiguration` instead of `bodyType`, so
     * `bodyType` stays semantically correct rather than publishing a wrong value.
     *
     * FHC-preairflow additionally keeps `vehicleConfiguration` even though it also
     * gets a `bodyType` (unlike FHC/DHC/Roadster, which get `bodyType` only) — it's
     * a distinct Series 1-3 dash sub-configuration worth preserving as free text
     * alongside the shared Coupe body shape, which is why it's absent from
     * VARIANTS_WITHOUT_VEHICLE_CONFIGURATION despite being present in BODY_TYPE_MAP.
     *
     * `chassis`/`color`/`series` are trimmed defensively — legacy data (predating
     * the current CarValidator/InputSanitizer::normalize() input pipeline, which
     * already trims on write) has untrimmed whitespace in these columns.
     *
     * `series` values starting with "+2" (`+2`, `+2S`, `+2S/130`, `+2S/130/5`, per
     * the live `cars` table) denote the Lotus Elan **Plus 2**, a distinct model
     * line — `StatisticsDataService::getSeriesCounts()` already uses the same
     * `series LIKE '+2%'` detection. Both `name` and `model` reflect "Elan Plus 2"
     * for these cars rather than plain "Elan"; the "+2" prefix is stripped from the
     * series portion of `name` since "Plus 2" already conveys it (e.g. series
     * "+2S/130" becomes "... Elan Plus 2 S/130 ...", not "... Plus 2 +2S/130 ...").
     *
     * @param object $carData Car data object (year, series, variant, chassis, color, ...)
     * @param string $currentUrl Canonical URL of the current page
     * @return array<string, string> Schema.org Car properties, ready for json_encode()
     */
    public static function buildCarSchema(object $carData, string $currentUrl): array
    {
        $normalizedVariant = trim($carData->variant ?? '');
        $normalizedColor = trim($carData->color ?? '');
        $normalizedSeries = trim($carData->series ?? '');

        ['model' => $model, 'series' => $seriesForName] = self::modelAndSeries($normalizedSeries);

        $bodyType = self::BODY_TYPE_MAP[$normalizedVariant] ?? null;
        $vehicleConfiguration = (
            $normalizedVariant !== '' && !in_array($normalizedVariant, self::VARIANTS_WITHOUT_VEHICLE_CONFIGURATION, true)
        ) ? $normalizedVariant : null;

        $nameParts = array_filter(
            [
                // year is a SMALLINT column, not free text — no trim() needed
                // (unlike chassis/color/series below, which are).
                (string)($carData->year ?? ''),
                'Lotus ' . $model,
                $seriesForName,
                $normalizedVariant !== '' ? '(' . $normalizedVariant . ')' : '',
            ],
            static fn (string $part): bool => $part !== ''
        );

        $carSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Car',
            'name' => implode(' ', $nameParts),
            'vehicleIdentificationNumber' => trim($carData->chassis ?? ''),
            'vehicleModelDate' => (string)($carData->year ?? ''),
            'model' => $model,
            'url' => $currentUrl,
        ];
        if ($normalizedColor !== '') {
            $carSchema['color'] = $normalizedColor;
        }
        if ($bodyType !== null) {
            $carSchema['bodyType'] = $bodyType;
        }
        if ($vehicleConfiguration !== null) {
            $carSchema['vehicleConfiguration'] = $vehicleConfiguration;
        }

        return $carSchema;
    }

    /**
     * Build the per-car page title for details.php (#1528).
     *
     * Examples: "1967 Lotus Elan S3 SE, Chassis 26/1234", and with a variant
     * "1969 Lotus Elan S4 (DHC), Chassis 36/7890". The upstream header
     * adds the site name. A car with no valid chassis gets "Registry car #<id>"
     * instead, so that two cars do not share a title.
     *
     * The output contains only letters, digits, space and / . - + , ( ) # :
     * It never contains < > " ' or &, so it is safe in <title> and in an attribute.
     *
     * @param object $carData   Car data object (id, year, series, variant, chassis)
     * @param bool   $modelKnown True when CarModel::exists() accepts the series and variant.
     *                          When false, the title leaves them out.
     * @return string Page title, without the site name
     */
    public static function buildPageTitle(object $carData, bool $modelKnown): string
    {
        $parts = self::pageTitleParts($carData, $modelKnown);

        return self::stripUnsafeTitleCharacters($parts['car'] . self::carReference($parts, 'Chassis'));
    }

    /**
     * Build the per-car meta description for details.php (#1528).
     *
     * Example: "Registry record for a 1967 Lotus Elan S3 SE, chassis 26/1234:
     * ownership history, factory build data and photos." With a variant, the
     * car part is "1969 Lotus Elan S4 (DHC)". It uses the same
     * validated parts and the same character set as buildPageTitle().
     *
     * @param object $carData   Car data object (id, year, series, variant, chassis)
     * @param bool   $modelKnown True when CarModel::exists() accepts the series and variant
     * @return string Meta description
     */
    public static function buildPageDescription(object $carData, bool $modelKnown): string
    {
        $parts = self::pageTitleParts($carData, $modelKnown);
        $description = 'Registry record for a ' . $parts['car'] . self::carReference($parts, 'chassis')
            . ': ownership history, factory build data and photos.';

        return self::stripUnsafeTitleCharacters($description);
    }

    /**
     * The chassis tells cars apart. When it is not valid, the registry ID
     * does that, so two cars never share a title.
     *
     * @param array{car: string, chassis: ?string, id: ?int} $parts
     * @param string $chassisLabel 'Chassis' in the title, 'chassis' in the description
     */
    private static function carReference(array $parts, string $chassisLabel): string
    {
        if ($parts['chassis'] !== null) {
            return ', ' . $chassisLabel . ' ' . $parts['chassis'];
        }

        return $parts['id'] !== null ? ', Registry car #' . $parts['id'] : '';
    }

    /**
     * Pick the model line from the series. See buildCarSchema() for why a
     * "+2" series prefix means the Elan Plus 2.
     *
     * @param string $normalizedSeries Trimmed series value
     * @return array{model: string, series: string} Model name, and the series without the "+2" prefix
     */
    private static function modelAndSeries(string $normalizedSeries): array
    {
        $isPlus2 = str_starts_with($normalizedSeries, '+2');

        return [
            'model' => $isPlus2 ? 'Elan Plus 2' : 'Elan',
            'series' => $isPlus2 ? trim(substr($normalizedSeries, 2)) : $normalizedSeries,
        ];
    }

    /**
     * Validate each car field for the page title and description. A field
     * with the wrong type or an unsafe value is left out, not escaped.
     *
     * @return array{car: string, chassis: ?string, id: ?int}
     */
    private static function pageTitleParts(object $carData, bool $modelKnown): array
    {
        $year = $carData->year ?? null;
        $series = $carData->series ?? null;
        $variant = $carData->variant ?? null;
        $chassis = $carData->chassis ?? null;
        $id = $carData->id ?? null;

        // Elan and Elan Plus 2 are the only model names, so the model line is
        // safe even when $modelKnown is false.
        ['model' => $model, 'series' => $seriesForName] = self::modelAndSeries(
            is_string($series) ? trim($series) : ''
        );
        $variant = is_string($variant) ? trim($variant) : '';

        $carParts = [];
        $year = self::positiveIntOrNull($year);
        if ($year !== null && $year >= self::TITLE_YEAR_MIN && $year <= self::TITLE_YEAR_MAX) {
            $carParts[] = (string) $year;
        }
        $carParts[] = 'Lotus ' . $model;
        if ($modelKnown && preg_match(self::TITLE_MODEL_PART_PATTERN, $seriesForName) === 1) {
            $carParts[] = $seriesForName;
        }
        if ($modelKnown && preg_match(self::TITLE_MODEL_PART_PATTERN, $variant) === 1) {
            $carParts[] = '(' . $variant . ')';
        }

        $chassis = is_string($chassis) ? trim($chassis) : '';

        return [
            'car' => implode(' ', $carParts),
            'chassis' => preg_match(self::TITLE_CHASSIS_PATTERN, $chassis) === 1 ? $chassis : null,
            'id' => self::positiveIntOrNull($id),
        ];
    }

    /**
     * The UserSpice DB class returns integer columns as strings, so a string
     * of digits with no leading zero is also an integer here. Any other type
     * or format gives null.
     */
    private static function positiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Last check after validation of each part: remove any character outside
     * the safe set, so a later change to a part cannot add markup.
     */
    private static function stripUnsafeTitleCharacters(string $text): string
    {
        return (string) preg_replace(self::TITLE_UNSAFE_CHARACTERS, '', $text);
    }
}
