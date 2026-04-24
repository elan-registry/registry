<?php

declare(strict_types=1);

use ElanRegistry\Car\CarImageProcessor;
use ElanRegistry\Exceptions\ImageProcessingException;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for CarImageProcessor service class
 */
#[Group('fast')]
final class CarImageProcessorTest extends TestCase
{
    private CarImageProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new CarImageProcessor();
    }

    // ============================================================
    // encodeImages tests
    // ============================================================

    public function testEncodeImagesReturnsJsonString(): void
    {
        $images = ['image1.jpg', 'image2.png'];
        $result = $this->processor->encodeImages($images);
        $this->assertJson($result);
        $this->assertEquals('["image1.jpg","image2.png"]', $result);
    }

    public function testEncodeImagesHandlesEmptyArray(): void
    {
        $result = $this->processor->encodeImages([]);
        $this->assertEquals('[]', $result);
    }

    // ============================================================
    // decodeAndProcessImages tests
    // ============================================================

    public function testDecodeAndProcessImagesHandlesNull(): void
    {
        $result = $this->processor->decodeAndProcessImages(null, '/images/1/', '/', '/var/www/');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDecodeAndProcessImagesHandlesEmptyString(): void
    {
        $result = $this->processor->decodeAndProcessImages('', '/images/1/', '/', '/var/www/');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ============================================================
    // removeImage tests (requires mock DB)
    // ============================================================

    public function testRemoveImageThrowsOnEmptyFilename(): void
    {
        $this->expectException(ImageProcessingException::class);

        $carData = (object) ['id' => 1, 'image' => '["test.jpg"]'];
        $db = DB::getInstance();
        $this->processor->removeImage($carData, '', $db);
    }

    public function testRemoveImageReturnsFalseWhenImageNotFound(): void
    {
        $carData = (object) ['id' => 1, 'image' => '["other.jpg"]'];
        $db = DB::getInstance();
        $result = $this->processor->removeImage($carData, 'nonexistent.jpg', $db);
        $this->assertFalse($result);
    }

    public function testRemoveImageReturnsTrueWhenFound(): void
    {
        $carData = (object) ['id' => 1, 'image' => '["test.jpg","other.jpg"]'];
        $db = DB::getInstance();
        $result = $this->processor->removeImage($carData, 'test.jpg', $db);
        $this->assertTrue($result);
    }

    public function testRemoveImageUpdatesCarData(): void
    {
        $carData = (object) ['id' => 1, 'image' => '["test.jpg","other.jpg"]'];
        $db = DB::getInstance();
        $this->processor->removeImage($carData, 'test.jpg', $db);
        $this->assertEquals('["other.jpg"]', $carData->image);
    }

    public function testRemoveImageHandlesCsvFormat(): void
    {
        $carData = (object) ['id' => 1, 'image' => 'test.jpg,other.jpg'];
        $db = DB::getInstance();
        $result = $this->processor->removeImage($carData, 'test.jpg', $db);
        $this->assertTrue($result);
    }
}
