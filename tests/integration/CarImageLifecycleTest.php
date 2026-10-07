<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/CarImageFixtureTrait.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarAdministrationService;
use ElanRegistry\Car\CarImageProcessor;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\CarConcurrentModificationException;
use ElanRegistry\Exceptions\CarNotFoundException;

use PHPUnit\Framework\Attributes\Group;

/**
 * Car image lifecycle against a real database. All files go under
 * sys_get_temp_dir(), never userimages/: its car-ID folders are shared by the
 * dev and test databases and could collide with a test car ID.
 */
#[Group('integration')]
final class CarImageLifecycleTest extends IntegrationTestCase
{
    use CarImageFixtureTrait;

    private int $testUserId;
    private int $testCarId;
    private string $tempRoot;
    private string $imageDir;
    private CarRepository $repo;
    private CarImageProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->initThumbnailSizes();

        $this->testUserId = $this->createTestUser();

        // image => '' (not NULL) so the first CAS write's expectedJson baseline matches:
        // MySQL's `WHERE image = ''` never matches a NULL column.
        //
        // No try/catch: the DB is reachable, so a failure here is a real regression.
        $this->testCarId = $this->createTestCar($this->testUserId, ['image' => '']);

        // random_bytes(), not uniqid(): the directory name must not be guessable by
        // another local process racing to pre-create it as a symlink.
        $this->tempRoot = sys_get_temp_dir() . '/elanregistry-imgtest-' . bin2hex(random_bytes(8)) . '/';
        $this->imageDir = $this->tempRoot . $this->testCarId . '/';
        if (!mkdir($this->tempRoot, 0700) || !mkdir($this->imageDir, 0700)) {
            $this->fail("Could not create temp image directory: {$this->imageDir}");
        }

        $this->repo = new CarRepository($this->db);
        $this->processor = new CarImageProcessor($this->repo);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempRoot) && is_dir($this->tempRoot)) {
            $this->recursiveRemoveDirectory($this->tempRoot);
        }

        parent::tearDown();
    }

    /** The image column holds only the base filename; all resized variants are on disk. */
    #[Group('fast')]
    public function testUploadWritesVariantsAndUpdatesImageJson(): void
    {
        $filename = $this->uploadOneTestImage($this->imageDir);

        $this->assertUploadedFilesExist($this->imageDir, $filename);
        $this->assertVariantsAreActuallyResized($this->imageDir, $filename);

        $imageJson = $this->processor->encodeImages([$filename]);
        $this->assertTrue(
            $this->repo->updateImage($this->testCarId, $imageJson, ''),
            'CAS update against the empty-string baseline must affect exactly one row'
        );

        $stored = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        if ($stored->error()) {
            $this->fail("Verification query failed for car {$this->testCarId}: " . $stored->errorString());
        }
        $row = $stored->first();
        $this->assertIsObject($row);
        $this->assertSame($imageJson, $row->image);

        $decoded = json_decode($imageJson, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertIsString($decoded[0]);
        $this->assertStringNotContainsString(
            '-resized-',
            $decoded[0],
            'Only the base filename is persisted — resized variants are derived, never stored'
        );
    }

    #[Group('fast')]
    public function testDecodeAndProcessImagesRoundTripsWrittenFiles(): void
    {
        $filename = $this->uploadOneTestImage($this->imageDir);
        $imageJson = $this->processor->encodeImages([$filename]);

        $imageDirRelative = '/' . $this->testCarId . '/';
        $decoded = $this->processor->decodeAndProcessImages(
            $imageJson,
            $imageDirRelative,
            '',
            rtrim($this->tempRoot, '/')
        );

        $this->assertCount(1, $decoded);
        $this->assertSame($filename, $decoded[0]['basename']);
        $this->assertSame($imageDirRelative . $filename, $decoded[0]['path']);
        $this->assertGreaterThan(0, $decoded[0]['size']);
        $this->assertSame('jpeg', $decoded[0]['type']);
        $this->assertSame('image/jpeg', $decoded[0]['mime']);
    }

    #[Group('fast')]
    public function testDeleteCarRemovesDbRowButLeavesFilesOnDisk(): void
    {
        $filename = $this->uploadOneTestImage($this->imageDir);
        $this->assertUploadedFilesExist($this->imageDir, $filename);

        $carData = $this->repo->findById($this->testCarId);
        $this->assertIsObject($carData);

        $result = (new CarAdministrationService())->delete(
            $carData,
            'Integration test image lifecycle',
            $this->testUserId,
            $this->repo
        );
        $this->assertTrue($result);

        $remaining = $this->db->query('SELECT id FROM cars WHERE id = ?', [$this->testCarId]);
        if ($remaining->error()) {
            $this->fail("Verification query failed for car {$this->testCarId}: " . $remaining->errorString());
        }
        $this->assertSame(0, $remaining->count(), 'cars row must be gone after delete()');

        // Untracking skips tearDown's cleanup, so remove the trigger's cars_hist row here.
        $histCleanup = $this->db->query('DELETE FROM cars_hist WHERE car_id = ?', [$this->testCarId]);
        if ($histCleanup->error()) {
            $this->fail("Failed to clean up cars_hist for car {$this->testCarId}: " . $histCleanup->errorString());
        }
        $this->untrackCarId($this->testCarId);

        // KNOWN GAP (#1629): documents current behavior. Flip to
        // assertFileDoesNotExist() when #1629 is fixed.
        $this->assertUploadedFilesExist($this->imageDir, $filename);
    }

    /** A stale car object must fail loudly, not clobber a concurrent writer's list. */
    #[Group('fast')]
    public function testRemoveImageCasConflictThrowsConcurrentModificationException(): void
    {
        $filename = $this->uploadOneTestImage($this->imageDir);
        $imageJson = $this->processor->encodeImages([$filename]);
        $this->assertTrue($this->repo->updateImage($this->testCarId, $imageJson, ''));

        $staleCarData = $this->repo->findById($this->testCarId);
        $this->assertIsObject($staleCarData);
        $this->assertSame($imageJson, $staleCarData->image);

        $concurrent = $this->db->query(
            'UPDATE cars SET image = ? WHERE id = ?',
            [json_encode(['img_b_fake.jpg']), $this->testCarId]
        );
        if ($concurrent->error()) {
            $this->fail("Concurrent update failed for car {$this->testCarId}: " . $concurrent->errorString());
        }

        $this->expectException(CarConcurrentModificationException::class);
        $this->processor->removeImage($staleCarData, $filename);
    }

    /** #1629 gap at a second call site: removeImage() leaves the files on disk. */
    #[Group('fast')]
    public function testRemoveImageSucceedsButLeavesFilesOnDisk(): void
    {
        $filename = $this->uploadOneTestImage($this->imageDir);
        $imageJson = $this->processor->encodeImages([$filename]);
        $this->assertTrue($this->repo->updateImage($this->testCarId, $imageJson, ''));

        $carData = $this->repo->findById($this->testCarId);
        $this->assertIsObject($carData);

        $this->assertTrue($this->processor->removeImage($carData, $filename));

        $stored = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        if ($stored->error()) {
            $this->fail("Verification query failed for car {$this->testCarId}: " . $stored->errorString());
        }
        $row = $stored->first();
        $this->assertIsObject($row);
        $this->assertSame('', $row->image, 'Removing the only image must leave the empty-list sentinel');

        // KNOWN GAP (#1629): removeImage() updates only the DB. Documents current behavior.
        $this->assertUploadedFilesExist($this->imageDir, $filename);
    }

    /**
     * #1452: removeImages() is mvTmpImages()'s cleanup after a failed rename().
     * mvTmpImages() needs a full users/init.php bootstrap, so the test checks its
     * postcondition directly: cars.image no longer lists the unmoved files.
     */
    #[Group('fast')]
    public function testRemoveImagesStripsUnmovedFilenameAfterSimulatedRenameFailure(): void
    {
        $movedFilename = $this->uploadOneTestImage($this->imageDir);
        $unmovedFilename = CarImageProcessor::generateSecureFilename('jpg');

        // Both names are in cars.image; only $movedFilename exists on disk.
        $imageJson = $this->processor->encodeImages([$movedFilename, $unmovedFilename]);
        $this->assertTrue($this->repo->updateImage($this->testCarId, $imageJson, ''));

        $carData = $this->repo->findById($this->testCarId);
        $this->assertIsObject($carData);

        $result = $this->processor->removeImages($carData, [$unmovedFilename]);
        $this->assertSame(['updated' => true, 'casConflict' => false], $result);

        $stored = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        if ($stored->error()) {
            $this->fail("Verification query failed for car {$this->testCarId}: " . $stored->errorString());
        }
        $row = $stored->first();
        $this->assertIsObject($row);

        $decoded = json_decode($row->image, true);
        $this->assertIsArray($decoded);
        $this->assertSame([$movedFilename], $decoded, 'Only the unmoved filename must be stripped; the moved one must remain');

        // mvTmpImages() reads $car->data()->image after the call.
        $this->assertSame($row->image, $carData->image);
    }

    /** mvTmpImages()'s mkdir()-failure branch: no file moved, so the column becomes ''. */
    #[Group('fast')]
    public function testRemoveImagesClearsImageColumnWhenAllFilenamesUnmovedAfterSimulatedMkdirFailure(): void
    {
        $unmovedA = CarImageProcessor::generateSecureFilename('jpg');
        $unmovedB = CarImageProcessor::generateSecureFilename('jpg');

        $imageJson = $this->processor->encodeImages([$unmovedA, $unmovedB]);
        $this->assertTrue($this->repo->updateImage($this->testCarId, $imageJson, ''));

        $carData = $this->repo->findById($this->testCarId);
        $this->assertIsObject($carData);

        $result = $this->processor->removeImages($carData, [$unmovedA, $unmovedB]);
        $this->assertSame(['updated' => true, 'casConflict' => false], $result);

        $stored = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        if ($stored->error()) {
            $this->fail("Verification query failed for car {$this->testCarId}: " . $stored->errorString());
        }
        $row = $stored->first();
        $this->assertIsObject($row);
        $this->assertSame('', $row->image, 'Clearing every listed filename must leave the empty-list sentinel, not an empty JSON array');
        $this->assertSame('', $carData->image);
    }

    /**
     * Unlike removeImage(), a CAS conflict is returned, not thrown: the
     * mvTmpImages() cleanup path must not interrupt the addCar() response.
     */
    #[Group('fast')]
    public function testRemoveImagesReportsCasConflictWithoutThrowing(): void
    {
        $filename = $this->uploadOneTestImage($this->imageDir);
        $imageJson = $this->processor->encodeImages([$filename]);
        $this->assertTrue($this->repo->updateImage($this->testCarId, $imageJson, ''));

        $staleCarData = $this->repo->findById($this->testCarId);
        $this->assertIsObject($staleCarData);
        $this->assertSame($imageJson, $staleCarData->image);

        $concurrent = $this->db->query(
            'UPDATE cars SET image = ? WHERE id = ?',
            [json_encode(['img_b_fake.jpg']), $this->testCarId]
        );
        if ($concurrent->error()) {
            $this->fail("Concurrent update failed for car {$this->testCarId}: " . $concurrent->errorString());
        }

        $result = $this->processor->removeImages($staleCarData, [$filename]);
        $this->assertSame(['updated' => false, 'casConflict' => true], $result);

        $this->assertSame($imageJson, $staleCarData->image);

        $stored = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        if ($stored->error()) {
            $this->fail("Verification query failed for car {$this->testCarId}: " . $stored->errorString());
        }
        $row = $stored->first();
        $this->assertIsObject($row);
        $this->assertSame(
            json_encode(['img_b_fake.jpg']),
            $row->image,
            'The concurrent writer\'s value must survive — the CAS conflict must not be silently overwritten'
        );
    }

    /** Mixed failure reasons in one request are stripped in a single CAS write. */
    #[Group('fast')]
    public function testRemoveImagesStripsMixedProvenanceFilenamesInSingleCasWrite(): void
    {
        $movedFilename = $this->uploadOneTestImage($this->imageDir);
        // Stands in for mvTmpImages()'s legacy-format-skip branch (save.php:876-887).
        $legacySkipFilename = 'legacy_format_name.jpg';
        // Stands in for mvTmpImages()'s rename()-failure branch (save.php:890-897).
        $renameFailureFilename = CarImageProcessor::generateSecureFilename('jpg');

        $imageJson = $this->processor->encodeImages([$movedFilename, $legacySkipFilename, $renameFailureFilename]);
        $this->assertTrue($this->repo->updateImage($this->testCarId, $imageJson, ''));

        $carData = $this->repo->findById($this->testCarId);
        $this->assertIsObject($carData);

        $result = $this->processor->removeImages($carData, [$legacySkipFilename, $renameFailureFilename]);
        $this->assertSame(['updated' => true, 'casConflict' => false], $result);

        $stored = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        if ($stored->error()) {
            $this->fail("Verification query failed for car {$this->testCarId}: " . $stored->errorString());
        }
        $row = $stored->first();
        $this->assertIsObject($row);

        $decoded = json_decode($row->image, true);
        $this->assertIsArray($decoded);
        $this->assertSame(
            [$movedFilename],
            $decoded,
            'Both the legacy-skip and rename-failure filenames must be stripped; only the successfully moved one remains'
        );
        $this->assertSame($row->image, $carData->image);
    }

    /**
     * removeImages() resets the cached _images to null so the next images()
     * call decodes again. Reflection is needed: images() returns [] for both
     * "not loaded" and "loaded, empty".
     */
    #[Group('fast')]
    public function testCarRemoveImagesClearsCachedImagesOnSuccess(): void
    {
        $filename = $this->uploadOneTestImage($this->imageDir);
        $imageJson = $this->processor->encodeImages([$filename]);
        $this->assertTrue($this->repo->updateImage($this->testCarId, $imageJson, ''));

        $car = new Car($this->testCarId);

        $imagesProperty = new \ReflectionProperty(Car::class, '_images');
        $this->assertNotNull(
            $imagesProperty->getValue($car),
            'find() in the constructor must populate _images (even as an empty array), not leave it null'
        );

        $result = $car->removeImages([$filename]);
        $this->assertSame(['updated' => true, 'casConflict' => false], $result);

        $this->assertNull(
            $imagesProperty->getValue($car),
            'removeImages() must reset _images to null so the next images() call forces a fresh reload rather than serving cached data'
        );
        $this->assertSame([], $car->images(), 'images() must not throw on the cleared cache — it falls back to an empty array until reloaded');

        $stored = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        if ($stored->error()) {
            $this->fail("Verification query failed for car {$this->testCarId}: " . $stored->errorString());
        }
        $row = $stored->first();
        $this->assertIsObject($row);
        $this->assertSame('', $row->image, 'Removing the only image must leave the empty-list sentinel');
    }

    /** Same guard as removeImage(): a never-loaded car throws CarNotFoundException. */
    #[Group('fast')]
    public function testCarRemoveImagesThrowsCarNotFoundExceptionWhenCarDoesNotExist(): void
    {
        $car = new Car(0);
        $this->assertFalse($car->exists());

        $this->expectException(CarNotFoundException::class);
        $car->removeImages(['whatever.jpg']);
    }

    /** A filename not in the list is a no-op, reported by return value. */
    #[Group('fast')]
    public function testRemoveImageReturnsFalseWhenFilenameNotInList(): void
    {
        $filename = $this->uploadOneTestImage($this->imageDir);
        $imageJson = $this->processor->encodeImages([$filename]);
        $this->assertTrue($this->repo->updateImage($this->testCarId, $imageJson, ''));

        $carData = $this->repo->findById($this->testCarId);
        $this->assertIsObject($carData);

        $this->assertFalse(
            $this->processor->removeImage($carData, 'this-filename-was-never-uploaded.jpg'),
            'Removing a filename that is not in the list must return false, not throw'
        );

        $stored = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        if ($stored->error()) {
            $this->fail("Verification query failed for car {$this->testCarId}: " . $stored->errorString());
        }
        $row = $stored->first();
        $this->assertIsObject($row);
        $this->assertSame($imageJson, $row->image, 'A no-op removal must leave the stored image list untouched');
        $this->assertSame($imageJson, $carData->image, 'A no-op removal must leave the in-memory car object untouched');
    }
}
