<?php

declare(strict_types=1);

use ElanRegistry\Car\CarImageProcessor;
use ElanRegistry\Exceptions\CarConcurrentModificationException;
use ElanRegistry\Exceptions\CarDatabaseException;
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

    /**
     * Build a DB mock whose updateImage path returns the given outcome.
     *
     * removeImage() creates a CarRepository internally and calls updateImage(),
     * which uses only query(), error(), and count().  Tests that exercise the
     * update path must supply this mock instead of a real DB connection so the
     * outcome is deterministic and independent of real DB state.
     *
     * @param bool $error      Whether the DB query should simulate a failure
     * @param int  $rowsAffected  Rows reported by count() (1 = success, 0 = CAS conflict)
     * @return \PHPUnit\Framework\MockObject\MockObject&DB
     */
    private function makeDbMockForUpdate(bool $error = false, int $rowsAffected = 1): object
    {
        $db = $this->createMock(DB::class);
        $db->expects($this->once())->method('query')->willReturn(new QueryResult([]));
        $db->method('error')->willReturn($error);
        $db->method('count')->willReturn($rowsAffected);
        return $db;
    }

    public function testRemoveImageThrowsOnEmptyFilename(): void
    {
        $this->expectException(ImageProcessingException::class);

        $carData = (object) ['id' => 1, 'image' => '["test.jpg"]'];
        $db = DB::getInstance();
        $this->processor->removeImage($carData, '', $db);
    }

    public function testRemoveImageReturnsFalseWhenImageNotFound(): void
    {
        // Returns false before reaching the DB — no mock needed.
        $carData = (object) ['id' => 1, 'image' => '["other.jpg"]'];
        $db = DB::getInstance();
        $result = $this->processor->removeImage($carData, 'nonexistent.jpg', $db);
        $this->assertFalse($result);
    }

    public function testRemoveImageReturnsTrueWhenFound(): void
    {
        $carData = (object) ['id' => 1, 'image' => '["test.jpg","other.jpg"]'];
        $db = $this->makeDbMockForUpdate();
        $result = $this->processor->removeImage($carData, 'test.jpg', $db);
        $this->assertTrue($result);
    }

    public function testRemoveImageUpdatesCarData(): void
    {
        $carData = (object) ['id' => 1, 'image' => '["test.jpg","other.jpg"]'];
        $db = $this->makeDbMockForUpdate();
        $this->processor->removeImage($carData, 'test.jpg', $db);
        $this->assertEquals('["other.jpg"]', $carData->image);
    }

    public function testRemoveImageHandlesCsvFormat(): void
    {
        $carData = (object) ['id' => 1, 'image' => 'test.jpg,other.jpg'];
        $db = $this->makeDbMockForUpdate();
        $result = $this->processor->removeImage($carData, 'test.jpg', $db);
        $this->assertTrue($result);
    }

    /**
     * When updateImage() returns false (CAS conflict — another request modified
     * the image column between the read and the write), removeImage() must throw
     * CarConcurrentModificationException so save.php can route it to a retriable
     * response rather than a generic server error.
     */
    public function testRemoveImageThrowsCarConcurrentModificationExceptionOnCasConflict(): void
    {
        $carData = (object) ['id' => 1, 'image' => '["test.jpg"]'];
        // count=0: UPDATE matched 0 rows → CAS guard failed → updateImage() returns false
        $db = $this->makeDbMockForUpdate(false, 0);

        $this->expectException(CarConcurrentModificationException::class);
        $this->processor->removeImage($carData, 'test.jpg', $db);
    }
}
