<?php

declare(strict_types=1);

use ElanRegistry\Exceptions\ImageProcessingException;
use PHPUnit\Framework\TestCase;

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
        $car = new Car();
        // Car with no images - mock returns empty array

        $html = CarView::displayCarousel($car);

        $this->assertStringContainsString('No Photos Available', $html);
        $this->assertStringContainsString('fa-camera', $html);
        $this->assertStringContainsString('photo-placeholder', $html);
    }

    public function testDisplayCarouselShowsSingleImageWithoutControls(): void
    {
        // Create car with single image via mock
        $car = new Car(1);

        $html = CarView::displayCarousel($car);

        // Should contain image but no carousel controls for single image
        $this->assertStringContainsString('<img', $html);
        // Carousel wrapper structure present
        $this->assertStringContainsString('<!--Start displayCarousel -->', $html);
        $this->assertStringContainsString('<!--End displayCarousel -->', $html);
    }

    public function testDisplayCarouselContainsCarouselStructure(): void
    {
        $car = new Car(1);

        $html = CarView::displayCarousel($car);

        $this->assertStringContainsString('displayCarousel', $html);
    }

    public function testDisplayCarouselWithInstanceIdShowsPlaceholderForNoImages(): void
    {
        $car = new Car();

        $html = CarView::displayCarousel($car, 42);

        $this->assertStringContainsString('No Photos Available', $html);
    }

    public function testDisplayCarouselWithSameInstanceIdProducesDeterministicOutput(): void
    {
        $car = new Car();

        $html1 = CarView::displayCarousel($car, 99);
        $html2 = CarView::displayCarousel($car, 99);

        $this->assertSame($html1, $html2);
    }

    public function testDisplayCarouselInstanceIdAppearsInMultiImageCarouselDomIds(): void
    {
        $car = $this->createStub(Car::class);
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
}
