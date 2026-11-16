<?php

declare(strict_types=1);

use ElanRegistry\CarView;
use ElanRegistry\Exceptions\ImageProcessingException;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * CarView class tests
 *
 * Tests static utility methods for car display and image generation.
 * Focus: Input validation, exception handling, HTML structure.
 */
#[Group('fast')]
final class CarViewTest extends TestCase
{
    // ============================================================
    // loadPicture() TESTS
    // ============================================================

    public function testLoadPictureThrowsExceptionForEmptyArray(): void
    {
        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('Invalid image data provided');

        CarView::loadPicture([]);
    }

    public function testLoadPictureThrowsExceptionForMissingPath(): void
    {
        $this->expectException(ImageProcessingException::class);

        CarView::loadPicture(['name' => 'test.jpg']);
    }

    public function testLoadPictureThrowsExceptionForEmptyPath(): void
    {
        $this->expectException(ImageProcessingException::class);

        CarView::loadPicture(['path' => '']);
    }

    public function testLoadPictureReturnsThumbnailHtml(): void
    {
        $image = ['path' => '/userimages/cars/test-image.jpg'];

        $html = CarView::loadPicture($image, true);

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('width="100"', $html);
        $this->assertStringContainsString('height="75"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('-resized-100.jpg', $html);
    }

    public function testLoadPictureReturnsFullSizeHtml(): void
    {
        $image = ['path' => '/userimages/cars/test-image.jpg'];

        $html = CarView::loadPicture($image, false);

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('srcset="', $html);
        $this->assertStringContainsString('sizes="50vw"', $html);
    }

    public function testLoadPictureGeneratesResponsiveSrcset(): void
    {
        $image = ['path' => '/userimages/cars/test-image.jpg'];

        $html = CarView::loadPicture($image, false);

        // Verify all responsive sizes are present
        $this->assertStringContainsString('-resized-100.jpg 100w', $html);
        $this->assertStringContainsString('-resized-300.jpg 300w', $html);
        $this->assertStringContainsString('-resized-768.jpg 768w', $html);
        $this->assertStringContainsString('-resized-1024.jpg 1024w', $html);
    }

    public function testLoadPicturePrimaryImageDoesNotLazyLoad(): void
    {
        $image = ['path' => '/userimages/cars/test-image.jpg'];

        $html = CarView::loadPicture($image, false, true);

        // Primary image should NOT have loading="lazy"
        $this->assertStringNotContainsString('loading="lazy"', $html);
    }

    public function testLoadPictureNonPrimaryImageLazyLoads(): void
    {
        $image = ['path' => '/userimages/cars/test-image.jpg'];

        $html = CarView::loadPicture($image, false, false);

        $this->assertStringContainsString('loading="lazy"', $html);
    }

    public function testLoadPicturePreservesPathStructure(): void
    {
        $image = ['path' => '/userimages/cars/subdir/my-car.png'];

        $html = CarView::loadPicture($image, true);

        $this->assertStringContainsString('/userimages/cars/subdir/', $html);
        $this->assertStringContainsString('my-car-resized-100.png', $html);
    }

    // ============================================================
    // displayCarousel() TESTS
    // ============================================================

    public function testDisplayCarouselShowsPlaceholderForNoImages(): void
    {
        $car = $this->createStub(\ElanRegistry\Car\Car::class);
        $car->method('images')->willReturn([]);

        $html = CarView::displayCarousel($car);

        $this->assertStringContainsString('No Photos Available', $html);
        $this->assertStringContainsString('fa-camera', $html);
        $this->assertStringContainsString('photo-placeholder', $html);
    }

    public function testDisplayCarouselPlaceholderHasNoHeadingAndHidesIcon(): void
    {
        $car = $this->createStub(\ElanRegistry\Car\Car::class);
        $car->method('images')->willReturn([]);

        $html = CarView::displayCarousel($car);

        // Callers put the carousel under h2, h3 or h4, so a heading here skips a level.
        $this->assertDoesNotMatchRegularExpression('/<h[1-6][\s>]/', $html);
        $this->assertStringContainsString('<p class="h5 text-muted mb-2">No Photos Available</p>', $html);
        $this->assertMatchesRegularExpression('/<i class="fas fa-camera[^"]*"[^>]*aria-hidden="true"/', $html);
    }

    public function testDisplayCarouselShowsSingleImageWithoutControls(): void
    {
        $car = $this->createStub(\ElanRegistry\Car\Car::class);
        $car->method('images')->willReturn([
            ['path' => '/userimages/cars/test.jpg'],
        ]);

        $html = CarView::displayCarousel($car);

        // Should contain image but no carousel controls for single image
        $this->assertStringContainsString('<img', $html);
        // Carousel wrapper structure present
        $this->assertStringContainsString('<!--Start displayCarousel -->', $html);
        $this->assertStringContainsString('<!--End displayCarousel -->', $html);
    }

    public function testDisplayCarouselContainsCarouselStructure(): void
    {
        $car = $this->createStub(\ElanRegistry\Car\Car::class);
        $car->method('images')->willReturn([
            ['path' => '/userimages/cars/test.jpg'],
        ]);

        $html = CarView::displayCarousel($car);

        $this->assertStringContainsString('displayCarousel', $html);
    }

    public function testDisplayCarouselWithInstanceIdShowsPlaceholderForNoImages(): void
    {
        $car = $this->createStub(\ElanRegistry\Car\Car::class);
        $car->method('images')->willReturn([]);

        $html = CarView::displayCarousel($car, 42);

        $this->assertStringContainsString('No Photos Available', $html);
    }

    public function testDisplayCarouselWithSameInstanceIdProducesDeterministicOutput(): void
    {
        $car = $this->createStub(\ElanRegistry\Car\Car::class);
        $car->method('images')->willReturn([]);

        $html1 = CarView::displayCarousel($car, 99);
        $html2 = CarView::displayCarousel($car, 99);

        $this->assertSame($html1, $html2);
    }

    public function testDisplayCarouselInstanceIdAppearsInMultiImageCarouselDomIds(): void
    {
        $car = $this->createStub(\ElanRegistry\Car\Car::class);
        $car->method('images')->willReturn([
            ['path' => '/userimages/cars/img1.jpg'],
            ['path' => '/userimages/cars/img2.jpg'],
        ]);

        $html = CarView::displayCarousel($car, 42);

        $this->assertStringContainsString('id="myCarousel-42"', $html);
        $this->assertStringContainsString('id="carousel-selector-42-0"', $html);
        $this->assertStringContainsString('id="carousel-selector-42-1"', $html);
        $this->assertStringContainsString('data-bs-target="#myCarousel-42"', $html);
    }

    public function testLoadPictureEscapesSpecialCharsInPath(): void
    {
        $image = ['path' => '/userimages/cars/my-"test"<car>.jpg'];

        $html = CarView::loadPicture($image, true);

        $this->assertStringNotContainsString('"test"', $html);
        $this->assertStringContainsString('&quot;test&quot;', $html);
    }

    // ============================================================
    // buildCarSchema() TESTS (Issue #1371)
    // ============================================================

    private function makeCarData(array $overrides = []): object
    {
        return (object)array_merge([
            'year' => 1969,
            'series' => 'S4',
            'variant' => 'Roadster',
            'chassis' => '1234B',
            'color' => 'Racing Green',
        ], $overrides);
    }

    public function testBuildCarSchemaBasicFields(): void
    {
        $schema = CarView::buildCarSchema($this->makeCarData(), 'https://elanregistry.org/app/owner/cars/details.php?car_id=1');

        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame('Car', $schema['@type']);
        $this->assertSame('1969 Lotus Elan S4 (Roadster)', $schema['name']);
        $this->assertSame('1234B', $schema['vehicleIdentificationNumber']);
        $this->assertSame('1969', $schema['vehicleModelDate']);
        $this->assertSame('Elan', $schema['model']);
        $this->assertSame('https://elanregistry.org/app/owner/cars/details.php?car_id=1', $schema['url']);
        $this->assertSame('Racing Green', $schema['color']);
        $this->assertSame('Roadster', $schema['bodyType']);
        $this->assertArrayNotHasKey('vehicleConfiguration', $schema);
    }

    /**
     * Regression test: series values starting with "+2" (+2, +2S, +2S/130, +2S/130/5 —
     * confirmed against the live cars table) denote the Lotus Elan Plus 2, a distinct
     * model line. An earlier implementation hardcoded "Elan" for name/model regardless
     * of series, mislabeling ~470 Plus 2 cars in the published structured data.
     */
    public function testBuildCarSchemaPlus2SeriesUsesElanPlus2Model(): void
    {
        $schema = CarView::buildCarSchema($this->makeCarData(['series' => '+2', 'variant' => 'FHC']), '');

        $this->assertSame('Elan Plus 2', $schema['model']);
        $this->assertSame('1969 Lotus Elan Plus 2 (FHC)', $schema['name']);
    }

    public function testBuildCarSchemaPlus2SubSeriesStripsPlus2PrefixFromNameSeriesPortion(): void
    {
        $schema = CarView::buildCarSchema($this->makeCarData(['series' => '+2S/130', 'variant' => 'Roadster']), '');

        $this->assertSame('Elan Plus 2', $schema['model']);
        $this->assertSame('1969 Lotus Elan Plus 2 S/130 (Roadster)', $schema['name']);
    }

    public function testBuildCarSchemaMapsFhcToCoupe(): void
    {
        $schema = CarView::buildCarSchema($this->makeCarData(['variant' => 'FHC']), '');

        $this->assertSame('Coupe', $schema['bodyType']);
        $this->assertArrayNotHasKey('vehicleConfiguration', $schema);
    }

    public function testBuildCarSchemaTrimsVariantBeforeBodyTypeMapLookup(): void
    {
        // Proves trim() happens BEFORE the BODY_TYPE_MAP lookup, not after — a padded
        // but non-empty variant (unlike the all-whitespace case tested elsewhere) would
        // silently fail to match "FHC" in the map if the ordering were ever reversed.
        $schema = CarView::buildCarSchema($this->makeCarData(['variant' => ' FHC ']), '');

        $this->assertSame('Coupe', $schema['bodyType']);
        $this->assertArrayNotHasKey('vehicleConfiguration', $schema);
    }

    public function testBuildCarSchemaMapsDhcToConvertible(): void
    {
        $schema = CarView::buildCarSchema($this->makeCarData(['variant' => 'DHC']), '');

        $this->assertSame('Convertible', $schema['bodyType']);
        $this->assertArrayNotHasKey('vehicleConfiguration', $schema);
    }

    public function testBuildCarSchemaFhcPreairflowSetsBothBodyTypeAndVehicleConfiguration(): void
    {
        $schema = CarView::buildCarSchema($this->makeCarData(['variant' => 'FHC-preairflow']), '');

        $this->assertSame('Coupe', $schema['bodyType']);
        $this->assertSame('FHC-preairflow', $schema['vehicleConfiguration']);
    }

    public function testBuildCarSchemaFederalIsExcludedFromBodyTypeButPreservedAsVehicleConfiguration(): void
    {
        $schema = CarView::buildCarSchema($this->makeCarData(['variant' => 'Federal']), '');

        $this->assertArrayNotHasKey('bodyType', $schema);
        $this->assertSame('Federal', $schema['vehicleConfiguration']);
    }

    public function testBuildCarSchemaRaceIsExcludedFromBodyTypeButPreservedAsVehicleConfiguration(): void
    {
        $schema = CarView::buildCarSchema($this->makeCarData(['variant' => 'Race']), '');

        $this->assertArrayNotHasKey('bodyType', $schema);
        $this->assertSame('Race', $schema['vehicleConfiguration']);
    }

    public function testBuildCarSchemaEmptySeriesWithVariantProducesNoDoubleSpace(): void
    {
        // Regression test: an earlier implementation concatenated year/series/variant
        // with a fixed space between each, so an empty series produced "Lotus Elan  (FHC)"
        // (two spaces). Reachable today only via unvalidated legacy data (the car_models
        // reference table always pairs a non-empty series with variant for validated input).
        $schema = CarView::buildCarSchema($this->makeCarData(['series' => '', 'variant' => 'FHC']), '');

        $this->assertSame('1969 Lotus Elan (FHC)', $schema['name']);
        $this->assertStringNotContainsString('  ', $schema['name']);
    }

    public function testBuildCarSchemaTrimsWhitespaceInColorChassisSeries(): void
    {
        // Real-data check (Issue #1371) found legacy untrimmed whitespace in these
        // columns, predating the current CarValidator/InputSanitizer::normalize()
        // input pipeline, which already trims on write.
        $schema = CarView::buildCarSchema($this->makeCarData([
            'series' => ' S4 ',
            'chassis' => ' 1234B ',
            'color' => ' Racing Green ',
        ]), '');

        $this->assertSame('1969 Lotus Elan S4 (Roadster)', $schema['name']);
        $this->assertSame('1234B', $schema['vehicleIdentificationNumber']);
        $this->assertSame('Racing Green', $schema['color']);
    }

    public function testBuildCarSchemaOmitsColorWhenEmpty(): void
    {
        $schema = CarView::buildCarSchema($this->makeCarData(['color' => '']), '');

        $this->assertArrayNotHasKey('color', $schema);
    }

    public function testBuildCarSchemaOmitsColorWhenWhitespaceOnly(): void
    {
        $schema = CarView::buildCarSchema($this->makeCarData(['color' => '   ']), '');

        $this->assertArrayNotHasKey('color', $schema);
    }

    public function testBuildCarSchemaOmitsBodyTypeAndVehicleConfigurationWhenVariantEmpty(): void
    {
        $schema = CarView::buildCarSchema($this->makeCarData(['variant' => '']), '');

        $this->assertArrayNotHasKey('bodyType', $schema);
        $this->assertArrayNotHasKey('vehicleConfiguration', $schema);
        $this->assertStringNotContainsString('(', $schema['name']);
    }

    public function testBuildCarSchemaWhitespaceOnlyVariantTreatedAsEmpty(): void
    {
        $schema = CarView::buildCarSchema($this->makeCarData(['variant' => '   ']), '');

        $this->assertArrayNotHasKey('bodyType', $schema);
        $this->assertArrayNotHasKey('vehicleConfiguration', $schema);
    }

    public function testBuildCarSchemaUrlEqualsArgument(): void
    {
        $url = 'https://elanregistry.org/app/owner/cars/details.php?car_id=952';

        $schema = CarView::buildCarSchema($this->makeCarData(), $url);

        $this->assertSame($url, $schema['url']);
    }

    // ============================================================
    // buildPageTitle() / buildPageDescription() TESTS (Issue #1528)
    // ============================================================

    private function makeTitleCarData(array $overrides = []): object
    {
        return (object)array_merge([
            'id' => 952,
            'year' => 1967,
            'series' => 'S3 SE',
            'variant' => '',
            'chassis' => '26/1234',
        ], $overrides);
    }

    public function testBuildPageTitleMatchesIssueExample(): void
    {
        $this->assertSame(
            '1967 Lotus Elan S3 SE, Chassis 26/1234',
            CarView::buildPageTitle($this->makeTitleCarData(), true)
        );
    }

    public function testBuildPageDescriptionMatchesIssueExample(): void
    {
        $this->assertSame(
            'Registry record for a 1967 Lotus Elan S3 SE, chassis 26/1234: ownership history, factory build data and photos.',
            CarView::buildPageDescription($this->makeTitleCarData(), true)
        );
    }

    public function testBuildPageTitleIncludesSeriesAndVariantWhenModelKnown(): void
    {
        $carData = $this->makeTitleCarData(['year' => 1969, 'series' => 'S4', 'variant' => 'DHC', 'chassis' => '36/7890']);

        $this->assertSame('1969 Lotus Elan S4 (DHC), Chassis 36/7890', CarView::buildPageTitle($carData, true));
    }

    public function testBuildPageTitlePlus2SeriesUsesElanPlus2Model(): void
    {
        $carData = $this->makeTitleCarData(['year' => 1971, 'series' => '+2S/130', 'variant' => 'FHC', 'chassis' => '7101010001']);

        $this->assertSame('1971 Lotus Elan Plus 2 S/130 (FHC), Chassis 7101010001', CarView::buildPageTitle($carData, true));
    }

    public function testBuildPageTitleLeavesOutSeriesAndVariantWhenModelUnknown(): void
    {
        $carData = $this->makeTitleCarData(['series' => 'S4', 'variant' => 'DHC']);

        $this->assertSame('1967 Lotus Elan, Chassis 26/1234', CarView::buildPageTitle($carData, false));
        $this->assertSame(
            'Registry record for a 1967 Lotus Elan, chassis 26/1234: ownership history, factory build data and photos.',
            CarView::buildPageDescription($carData, false)
        );
    }

    public function testBuildPageTitleUsesCarIdWhenChassisInvalid(): void
    {
        $carData = $this->makeTitleCarData(['chassis' => '']);

        $this->assertSame('1967 Lotus Elan S3 SE, Registry car #952', CarView::buildPageTitle($carData, true));
        $this->assertSame(
            'Registry record for a 1967 Lotus Elan S3 SE, Registry car #952: ownership history, factory build data and photos.',
            CarView::buildPageDescription($carData, true)
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidCarIdProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
            'zero string' => ['0'],
            'padded string' => ['0952'],
            'string with suffix' => ['952-spam'],
            'null' => [null],
            'float' => [952.0],
        ];
    }

    #[DataProvider('invalidCarIdProvider')]
    public function testBuildPageTitleLeavesOutInvalidCarId(mixed $id): void
    {
        $carData = $this->makeTitleCarData(['chassis' => '', 'id' => $id]);

        $this->assertSame('1967 Lotus Elan S3 SE', CarView::buildPageTitle($carData, true));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidYearProvider(): array
    {
        return [
            'before 1963' => [1962],
            'after 1974' => [1975],
            'zero' => [0],
            'padded string' => ['01969'],
            'string out of range' => ['1975'],
            'string with suffix' => ['1969abc'],
            'null' => [null],
            'float' => [1969.0],
            'bool' => [true],
            'array' => [[]],
        ];
    }

    #[DataProvider('invalidYearProvider')]
    public function testBuildPageTitleLeavesOutInvalidYear(mixed $year): void
    {
        $carData = $this->makeTitleCarData(['year' => $year]);

        $this->assertSame('Lotus Elan S3 SE, Chassis 26/1234', CarView::buildPageTitle($carData, true));
        $this->assertSame(
            'Registry record for a Lotus Elan S3 SE, chassis 26/1234: ownership history, factory build data and photos.',
            CarView::buildPageDescription($carData, true)
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function boundaryYearProvider(): array
    {
        return [
            'first year' => [1963],
            'last year' => [1974],
        ];
    }

    public function testBuildPageTitleAcceptsDigitStringsFromDatabaseRow(): void
    {
        // The UserSpice DB class returns int columns as strings (#1960 render test).
        $carData = $this->makeTitleCarData(['year' => '1969', 'chassis' => '', 'id' => '952']);

        $this->assertSame('1969 Lotus Elan S3 SE, Registry car #952', CarView::buildPageTitle($carData, true));
    }

    #[DataProvider('boundaryYearProvider')]
    public function testBuildPageTitleKeepsBoundaryYear(int $year): void
    {
        $carData = $this->makeTitleCarData(['year' => $year]);

        $this->assertStringStartsWith($year . ' Lotus Elan', CarView::buildPageTitle($carData, true));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidChassisProvider(): array
    {
        return [
            'int' => [261234],
            'null' => [null],
            'array' => [['26/1234']],
            'object' => [(object)['chassis' => '26/1234']],
            'too short' => ['ab'],
            '16 characters' => ['1234567890123456'],
            'script tag' => ['<script>alert(1)</script>'],
            'double quote' => ['a"b'],
            'newline' => ["a\nb"],
            'html entity' => ['&amp;'],
        ];
    }

    #[DataProvider('invalidChassisProvider')]
    public function testBuildPageTitleLeavesOutInvalidChassis(mixed $chassis): void
    {
        $carData = $this->makeTitleCarData(['chassis' => $chassis]);

        $this->assertSame('1967 Lotus Elan S3 SE, Registry car #952', CarView::buildPageTitle($carData, true));
        $this->assertSame(
            'Registry record for a 1967 Lotus Elan S3 SE, Registry car #952: ownership history, factory build data and photos.',
            CarView::buildPageDescription($carData, true)
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function boundaryChassisProvider(): array
    {
        return [
            '3 characters' => ['abc'],
            '15 characters' => ['123456789012345'],
        ];
    }

    #[DataProvider('boundaryChassisProvider')]
    public function testBuildPageTitleKeepsBoundaryChassis(string $chassis): void
    {
        $carData = $this->makeTitleCarData(['chassis' => $chassis]);

        $this->assertSame('1967 Lotus Elan S3 SE, Chassis ' . $chassis, CarView::buildPageTitle($carData, true));
    }

    public function testBuildPageTitleTrimsChassis(): void
    {
        $carData = $this->makeTitleCarData(['chassis' => '  26/1234 ']);

        $this->assertSame('1967 Lotus Elan S3 SE, Chassis 26/1234', CarView::buildPageTitle($carData, true));
    }

    public function testBuildPageTitleAndDescriptionContainNoMarkupWhenEveryFieldHasMarkup(): void
    {
        $markup = '<script>alert("x\'&")</script>';
        $carData = (object)[
            'id' => $markup,
            'year' => $markup,
            'series' => $markup,
            'variant' => $markup,
            'chassis' => $markup,
            'color' => $markup,
            'nickname' => $markup,
        ];

        // $modelKnown true is the worst case: series and variant are not left out for that reason.
        $title = CarView::buildPageTitle($carData, true);
        $description = CarView::buildPageDescription($carData, true);

        $this->assertSame('Lotus Elan', $title);
        $this->assertSame(
            'Registry record for a Lotus Elan: ownership history, factory build data and photos.',
            $description
        );
        foreach (['<', '>', '"', "'", '&'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $title);
            $this->assertStringNotContainsString($unsafe, $description);
        }
    }

    public function testBuildPageTitleLeavesOutSeriesAndVariantWithUnsafeCharactersWhenModelKnown(): void
    {
        $carData = $this->makeTitleCarData(['series' => 'S3"><b>', 'variant' => "FHC'"]);

        $this->assertSame('1967 Lotus Elan, Chassis 26/1234', CarView::buildPageTitle($carData, true));
    }

    public function testBuildPageTitleDiffersForTwoCars(): void
    {
        $first = $this->makeTitleCarData();
        $second = $this->makeTitleCarData(['id' => 736, 'year' => 1970, 'series' => 'S4', 'chassis' => '45/0736']);

        $this->assertNotSame(CarView::buildPageTitle($first, true), CarView::buildPageTitle($second, true));
        $this->assertNotSame(CarView::buildPageDescription($first, true), CarView::buildPageDescription($second, true));
    }

    public function testBuildPageTitleDiffersByCarIdForSameModelWithNoValidChassis(): void
    {
        $first = $this->makeTitleCarData(['id' => 952, 'chassis' => '']);
        $second = $this->makeTitleCarData(['id' => 736, 'chassis' => '<b>']);

        $this->assertSame('1967 Lotus Elan S3 SE, Registry car #952', CarView::buildPageTitle($first, true));
        $this->assertSame('1967 Lotus Elan S3 SE, Registry car #736', CarView::buildPageTitle($second, true));
        $this->assertNotSame(CarView::buildPageDescription($first, true), CarView::buildPageDescription($second, true));
    }

    public function testBuildPageTitleForEmptyObjectGivesBaseTitleWithoutWarning(): void
    {
        // PHPUnit only reports a warning and does not fail the test, so collect them here.
        $errors = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$errors): bool {
            $errors[] = $errstr;
            return true;
        });
        try {
            $title = CarView::buildPageTitle(new \stdClass(), true);
            $description = CarView::buildPageDescription(new \stdClass(), true);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $errors);
        $this->assertSame('Lotus Elan', $title);
        $this->assertSame(
            'Registry record for a Lotus Elan: ownership history, factory build data and photos.',
            $description
        );
    }
}
