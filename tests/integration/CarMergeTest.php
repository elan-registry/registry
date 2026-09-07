<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/CarImageFixtureTrait.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarAdministrationService;
use ElanRegistry\Car\CarImageProcessor;
use ElanRegistry\Car\CarImageRelocator;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarMergeException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarValidationException;

use PHPUnit\Framework\Attributes\Group;

/**
 * Test cases for Car merge functionality
 *
 * Tests cover car merging operations with history transfer, deletion,
 * transaction handling, and validation.
 */
#[Group('integration')]
final class CarMergeTest extends IntegrationTestCase
{
    use CarImageFixtureTrait;

    private $testCarId;
    private $testMergeCarId;
    private $testUserId;

    /**
     * Random root for the two per-car image directories used by the
     * merge-image tests below. Named by car ID under one tempRoot per test
     * method, mirroring CarImageLifecycleTest's single-car convention.
     */
    private string $imageTempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->initThumbnailSizes();

        $this->testUserId = $this->createTestUser();

        // Set up authenticated user context for merge operations
        $this->loginAsTestUser($this->testUserId);

        // Create unique test cars for this test
        try {
            $this->testCarId = $this->createTestCar($this->testUserId, [
                'chassis' => 'MG' . uniqid()
            ]);
            $this->testMergeCarId = $this->createTestCar($this->testUserId, [
                'chassis' => 'MG' . uniqid()
            ]);
        } catch (RuntimeException $e) {
            $this->markTestSkipped('Could not create test cars: ' . $e->getMessage());
        }

        $this->imageTempRoot = sys_get_temp_dir() . '/elanregistry-mergeimgtest-' . bin2hex(random_bytes(8)) . '/';
        if (!mkdir($this->imageTempRoot, 0700)) {
            $this->fail("Could not create temp image root: {$this->imageTempRoot}");
        }
    }

    protected function tearDown(): void
    {
        // Guarded by is_dir() and run unconditionally, before parent::tearDown():
        // a test that chmod'd the target directory to 0500 must restore write
        // access before recursiveRemoveDirectory() runs, or the temp dir leaks
        // silently (recursiveRemoveDirectory() only logs to STDERR on failure)
        // across the rest of this processIsolation="false" suite run.
        if (isset($this->imageTempRoot) && is_dir($this->imageTempRoot)) {
            foreach ([$this->testCarId, $this->testMergeCarId] as $carId) {
                $dir = $this->imageTempRoot . $carId;
                if (is_dir($dir)) {
                    @chmod($dir, 0700);
                }
            }
            $this->recursiveRemoveDirectory(rtrim($this->imageTempRoot, '/'));
        }

        parent::tearDown();
    }

    /**
     * Absolute per-car image directory path under this test's tempRoot,
     * with a trailing slash to match CarImageFixtureTrait's convention.
     */
    private function imageDirFor(int $carId): string
    {
        return $this->imageTempRoot . $carId . '/';
    }

    /**
     * Establish a non-NULL `cars.image` baseline for a freshly created test
     * car, via a direct (non-CAS) UPDATE.
     *
     * createTestCar() never sets `image`, so the column starts out NULL.
     * updateImage()'s CAS guard uses the null-safe `image <=> ?`, so a NULL
     * column IS matchable — this baseline is not required for correctness the
     * way it would be under a plain `image = ?`. It is kept because these
     * tests assert against a known, uniform starting value: both merge
     * participants are created once, up front, in setUp() for every test in
     * this class, including the ones with no image fixtures at all. A plain
     * UPDATE (not the CAS-guarded updateImage()) establishes that '' baseline
     * without depending on the code under test.
     */
    private function seedEmptyImageBaseline(int $carId): void
    {
        $result = $this->db->query('UPDATE cars SET image = ? WHERE id = ?', ['', $carId]);
        if ($result->error()) {
            $this->fail("Failed to seed empty image baseline for car {$carId}: " . $result->errorString());
        }
    }

    /**
     * Build a CarAdministrationService wired to a CarImageRelocator rooted at
     * this test's temp image directory, so merge()'s filesystem moves are
     * exercised against real (but disposable) directories rather than the
     * production userimages/ tree.
     *
     * Car::merge() always constructs its own CarAdministrationService with no
     * injection point (see getAdministrationService()), so these tests call
     * the service directly to inject the temp-dir-backed relocator.
     */
    private function administrationServiceWithTempRelocator(): CarAdministrationService
    {
        return new CarAdministrationService(new CarImageRelocator(rtrim($this->imageTempRoot, '/')));
    }

    /**
     * Test successful car merge with valid source car
     */
    #[Group('fast')]
    public function testMergeCarSuccessWithValidOldCar(): void
    {
        $car = new Car($this->testCarId);
        $result = $car->merge($this->testMergeCarId, 'Test merge success', $this->testUserId);

        $this->assertTrue($result);
    }

    /**
     * Test car merge fails when target car does not exist
     */
    #[Group('fast')]
    public function testMergeCarFailsWhenTargetNotExists(): void
    {
        $this->expectException(CarNotFoundException::class);

        $car = new Car(99999);
        $car->merge($this->testMergeCarId, 'Test merge', $this->testUserId);
    }

    /**
     * Test car merge fails when source car does not exist
     */
    #[Group('fast')]
    public function testMergeCarFailsWhenSourceNotExists(): void
    {
        $this->expectException(CarNotFoundException::class);

        $car = new Car($this->testCarId);
        $car->merge(99999, 'Test merge', $this->testUserId);
    }

    /**
     * Test car merge fails when merging car with itself
     */
    #[Group('fast')]
    public function testMergeCarFailsWhenMergingSelf(): void
    {
        $this->expectException(CarValidationException::class);

        $car = new Car($this->testCarId);
        $car->merge($this->testCarId, 'Test merge', $this->testUserId);
    }

    /**
     * Test car merge transfers history records
     */
    #[Group('fast')]
    public function testMergeTransfersHistoryRecords(): void
    {
        $car = new Car($this->testCarId);
        $result = $car->merge($this->testMergeCarId, 'Test merge history transfer', $this->testUserId);

        $this->assertTrue($result);

        // Verify history records were transferred to surviving car
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'MERGE'",
            [$this->testCarId]
        );
        $this->assertGreaterThan(0, $historyQuery->count());
    }

    /**
     * Test car merge deletes old car
     */
    #[Group('fast')]
    public function testMergeDeletesOldCar(): void
    {
        $oldCarId = $this->testMergeCarId;

        $car = new Car($this->testCarId);
        $result = $car->merge($oldCarId, 'Test merge deletes old car', $this->testUserId);

        $this->assertTrue($result);

        // Verify old car no longer exists
        $query = $this->db->query('SELECT * FROM cars WHERE id = ?', [$oldCarId]);
        $this->assertEquals(0, $query->count());
    }

    /**
     * Test car merge creates audit trail
     */
    #[Group('fast')]
    public function testMergeCreatesAuditTrail(): void
    {
        $car = new Car($this->testCarId);
        $result = $car->merge($this->testMergeCarId, 'Test merge audit trail', $this->testUserId);

        $this->assertTrue($result);

        // Verify audit trail record exists with MERGE operation
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'MERGE'",
            [$this->testCarId]
        );
        $this->assertGreaterThan(0, $historyQuery->count());
    }

    /**
     * Test car merge transaction rollback on failure
     */
    #[Group('fast')]
    public function testMergeTransactionRollbackOnFailure(): void
    {
        $this->expectException(CarNotFoundException::class);

        $car = new Car($this->testCarId);

        try {
            // Attempt to merge non-existent car
            $car->merge(99999, 'Test merge', $this->testUserId);
        } catch (CarNotFoundException $e) {
            // After failed merge, original car should still exist
            $carReloaded = new Car((int) $car->data()->id);
            $this->assertTrue($carReloaded->exists());
            throw $e;
        }
    }

    /**
     * Test that merging an already-deleted source car throws CarNotFoundException.
     *
     * This exercises the findByIdForUpdate() path added in issue #1311: after the
     * source car is deleted, the SELECT ... FOR UPDATE inside the merge transaction
     * returns no row, which the service translates into CarNotFoundException.
     */
    #[Group('fast')]
    public function testMergeAlreadyDeletedSourceCarThrowsCarNotFoundException(): void
    {
        // Permanently remove the source car; deleteTestCar() also removes it from
        // the tearDown tracking list so cleanup does not double-attempt the delete.
        $this->deleteTestCar($this->testMergeCarId);

        // The target car still exists; the source is gone — merge must throw
        $this->expectException(CarNotFoundException::class);
        $car = new Car($this->testCarId);
        $car->merge($this->testMergeCarId, 'Test merge after source deletion', $this->testUserId);
    }

    /**
     * Test that CarRepository::rollback() reverts all merge steps when the
     * admin page aborts a car merge midway through.
     *
     * WHY THIS TEST EXISTS: The admin car-merge page performs two DB steps inside a
     * transaction — (1) transferHistory, (2) deleteCar.  If a failure occurs after
     * step 1 but before step 2, rollback() must undo all completed steps so the
     * database is left in a consistent state.  This test simulates that scenario by
     * executing step 1, then calling rollback() instead of proceeding to step 2,
     * and asserting full state recovery.
     */
    #[Group('fast')]
    public function testCarRepositoryTransactionRollbackPreservesCarAndOwnerAssignment(): void
    {
        if ($this->testCarId === null) {
            $this->markTestSkipped('No test cars available');
        }

        // Seed a cars_hist row so transferHistory() has a real UPDATE to roll back.
        // createTestCar() purges any stale hist rows, so the table starts empty.
        $carRow = $this->db->query(
            'SELECT * FROM cars WHERE id = ?',
            [$this->testCarId]
        )->first();

        $histSeeded = $this->db->insert('cars_hist', [
            'car_id'    => $this->testCarId,
            'operation' => 'TEST',
            'model'     => $carRow->model,
            'series'    => $carRow->series,
            'variant'   => $carRow->variant,
            'year'      => $carRow->year,
            'type'      => $carRow->type,
            'chassis'   => $carRow->chassis,
        ]);
        $this->assertTrue($histSeeded, 'Precondition: should be able to seed a cars_hist row');

        // Snapshot counts before the transaction
        $carExistsBefore = $this->db->query(
            'SELECT id FROM cars WHERE id = ?',
            [$this->testCarId]
        )->count();

        $histCountBefore = $this->db->query(
            'SELECT * FROM cars_hist WHERE car_id = ?',
            [$this->testCarId]
        )->count();

        $this->assertGreaterThan(0, $carExistsBefore, 'Precondition: test car must exist');
        $this->assertGreaterThan(0, $histCountBefore, 'Precondition: cars_hist row must exist');

        // Simulate a mid-merge abort: steps 1 and 2 run, but step 3 (deleteCar) never fires
        $repo = new CarRepository($this->db);
        $repo->beginTransaction();
        try {
            $this->assertTrue(
                $repo->transferHistory($this->testCarId, $this->testMergeCarId),
                'Precondition: transferHistory must succeed within transaction'
            );
            // Mid-transaction: hist rows must now point to the merge target (visible within same connection)
            $histMid = $this->db->query(
                'SELECT * FROM cars_hist WHERE car_id = ?',
                [$this->testMergeCarId]
            )->count();
            $this->assertGreaterThan(0, $histMid, 'mid-transaction: transferHistory must have moved hist rows to merge target');
        } finally {
            // Never leak an open transaction into the next test if an assertion above
            // fails — the suite shares one connection across all 481 tests
            // (phpunit-integration.xml's processIsolation="false"), so an unrolled-back
            // transaction here silently corrupts whichever test runs next.
            if ($this->db->inTransaction()) {
                $repo->rollback();
            }
        }

        // Assertions: every in-transaction change must be fully reverted

        // 1. The cars row must still exist (was never touched, but confirms no side-effects)
        $carExistsAfter = $this->db->query(
            'SELECT id FROM cars WHERE id = ?',
            [$this->testCarId]
        )->count();
        $this->assertEquals(
            $carExistsBefore,
            $carExistsAfter,
            'cars row must survive rollback'
        );

        // 2. The cars_hist rows must still belong to testCarId (transferHistory UPDATE was rolled back)
        $histCountAfter = $this->db->query(
            'SELECT * FROM cars_hist WHERE car_id = ?',
            [$this->testCarId]
        )->count();
        $this->assertEquals(
            $histCountBefore,
            $histCountAfter,
            'cars_hist rows must remain on testCarId after rollback'
        );
    }

    /**
     * Test merge works with an explicit actingUserId even when global $user is unset.
     * Verifies that Car::merge() does not fall back to currentUserId() internally.
     */
    #[Group('fast')]
    public function testMergeHonorsExplicitActingUserIdWithoutGlobalUser(): void
    {
        // Car::__construct() needs a global $user (via getSettings()), so construct before
        // unsetting it — only merge() itself must not fall back to a global $user internally.
        $car = new Car($this->testCarId);

        $savedUser = $GLOBALS['user'] ?? null;
        unset($GLOBALS['user']);

        try {
            $result = $car->merge($this->testMergeCarId, 'Explicit actingUserId test', $this->testUserId);
            $this->assertTrue($result);
        } finally {
            if ($savedUser !== null) {
                $GLOBALS['user'] = $savedUser;
            }
        }
    }

    /**
     * Source-only images: the source car has images, the target has none.
     * After merge(), the target directory holds the source's files, the
     * source directory is gone, and cars.image records the source's base
     * filenames in their original order.
     */
    #[Group('fast')]
    public function testMergeRelocatesSourceOnlyImagesToTargetDirectory(): void
    {
        $sourceDir = $this->imageDirFor($this->testMergeCarId);
        $targetDir = $this->imageDirFor($this->testCarId);
        mkdir($sourceDir, 0700, true);

        $filenameA = $this->uploadOneTestImage($sourceDir);
        $filenameB = $this->uploadOneTestImage($sourceDir);

        $this->seedEmptyImageBaseline($this->testCarId);
        $this->seedEmptyImageBaseline($this->testMergeCarId);

        $repo = new CarRepository($this->db);
        $processor = new CarImageProcessor($repo);
        $sourceJson = $processor->encodeImages([$filenameA, $filenameB]);
        $this->assertTrue($repo->updateImage($this->testMergeCarId, $sourceJson, ''));

        $targetCarData = $repo->findById($this->testCarId);
        $this->assertIsObject($targetCarData);

        $this->administrationServiceWithTempRelocator()->merge(
            $targetCarData,
            $this->testMergeCarId,
            'Source-only image relocation test',
            $this->testUserId,
            $repo
        );
        $this->untrackCarId($this->testMergeCarId);

        $this->assertDirectoryDoesNotExist($sourceDir, 'source image directory must be removed after merge');
        $this->assertUploadedFilesExist($targetDir, $filenameA);
        $this->assertUploadedFilesExist($targetDir, $filenameB);

        $stored = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        $row = $stored->first();
        $this->assertIsObject($row);
        $decoded = json_decode($row->image, true);
        $this->assertSame([$filenameA, $filenameB], $decoded, 'cars.image must list the source filenames in original order');
    }

    /**
     * The crux case: both cars have images. The target's existing base
     * filenames must come first, then the source's — exact order, not set
     * equality, because the first entry renders as the surviving car's card
     * thumbnail.
     */
    #[Group('fast')]
    public function testMergeOrdersTargetImagesBeforeSourceImages(): void
    {
        $sourceDir = $this->imageDirFor($this->testMergeCarId);
        $targetDir = $this->imageDirFor($this->testCarId);
        mkdir($sourceDir, 0700, true);
        mkdir($targetDir, 0700, true);

        $targetFilename = $this->uploadOneTestImage($targetDir);
        $sourceFilename = $this->uploadOneTestImage($sourceDir);

        $this->seedEmptyImageBaseline($this->testCarId);
        $this->seedEmptyImageBaseline($this->testMergeCarId);

        $repo = new CarRepository($this->db);
        $processor = new CarImageProcessor($repo);

        $targetJson = $processor->encodeImages([$targetFilename]);
        $this->assertTrue($repo->updateImage($this->testCarId, $targetJson, ''));

        $sourceJson = $processor->encodeImages([$sourceFilename]);
        $this->assertTrue($repo->updateImage($this->testMergeCarId, $sourceJson, ''));

        $targetCarData = $repo->findById($this->testCarId);
        $this->assertIsObject($targetCarData);

        $this->administrationServiceWithTempRelocator()->merge(
            $targetCarData,
            $this->testMergeCarId,
            'Both-have-images ordering test',
            $this->testUserId,
            $repo
        );
        $this->untrackCarId($this->testMergeCarId);

        $stored = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        $row = $stored->first();
        $this->assertIsObject($row);
        $decoded = json_decode($row->image, true);
        $this->assertSame(
            [$targetFilename, $sourceFilename],
            $decoded,
            'target images must come first, source images appended — exact order, not set equality'
        );
    }

    /**
     * Collision: both directories are pre-seeded with an identical hand-picked
     * filename (not relying on two real random_bytes(16) calls colliding).
     * The incoming file must be renamed rather than overwrite the existing
     * target file — both original contents must survive under distinct
     * names, and cars.image (read back from the DB) must list the renamed
     * file, not the original.
     */
    #[Group('fast')]
    public function testMergeRenamesCollidingFilenameWithoutOverwriting(): void
    {
        $sourceDir = $this->imageDirFor($this->testMergeCarId);
        $targetDir = $this->imageDirFor($this->testCarId);
        mkdir($sourceDir, 0700, true);
        mkdir($targetDir, 0700, true);

        $collidingFilename = 'collision_test_image.jpg';

        // Distinct dimensions so the two files are distinguishable by content,
        // not just by having different bytes.
        $this->makeTestJpeg($targetDir . $collidingFilename, 40, 30);
        $this->makeTestJpeg($sourceDir . $collidingFilename, 20, 15);

        $targetContentsBefore = file_get_contents($targetDir . $collidingFilename);
        $sourceContentsBefore = file_get_contents($sourceDir . $collidingFilename);
        $this->assertNotSame($targetContentsBefore, $sourceContentsBefore, 'precondition: the two seeded files must actually differ');

        $this->seedEmptyImageBaseline($this->testCarId);
        $this->seedEmptyImageBaseline($this->testMergeCarId);

        $repo = new CarRepository($this->db);
        $processor = new CarImageProcessor($repo);

        $targetJson = $processor->encodeImages([$collidingFilename]);
        $this->assertTrue($repo->updateImage($this->testCarId, $targetJson, ''));

        $sourceJson = $processor->encodeImages([$collidingFilename]);
        $this->assertTrue($repo->updateImage($this->testMergeCarId, $sourceJson, ''));

        $targetCarData = $repo->findById($this->testCarId);
        $this->assertIsObject($targetCarData);

        $this->administrationServiceWithTempRelocator()->merge(
            $targetCarData,
            $this->testMergeCarId,
            'Collision rename test',
            $this->testUserId,
            $repo
        );
        $this->untrackCarId($this->testMergeCarId);

        $stored = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        $row = $stored->first();
        $this->assertIsObject($row);
        $decoded = json_decode($row->image, true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded, 'both the original target file and the renamed source file must be listed');
        $this->assertSame($collidingFilename, $decoded[0], 'the target file keeps its original name');

        $renamedFilename = $decoded[1];
        $this->assertNotSame($collidingFilename, $renamedFilename, 'the incoming (source) file must be renamed, not overwrite the target file');

        // No overwrite: both original contents survive under distinct names.
        $this->assertFileExists($targetDir . $collidingFilename);
        $this->assertSame($targetContentsBefore, file_get_contents($targetDir . $collidingFilename), 'target file contents must be untouched');
        $this->assertFileExists($targetDir . $renamedFilename);
        $this->assertSame($sourceContentsBefore, file_get_contents($targetDir . $renamedFilename), 'renamed file must carry the source file\'s original contents');
    }

    /**
     * Every `-resized-{size}` variant of a moved base file must exist at the
     * new path, and cars.image must list only base filenames — no
     * `-resized-` entries leak into the JSON.
     */
    #[Group('fast')]
    public function testMergeMovesVariantsWithBaseAndExcludesThemFromImageJson(): void
    {
        $sourceDir = $this->imageDirFor($this->testMergeCarId);
        $targetDir = $this->imageDirFor($this->testCarId);
        mkdir($sourceDir, 0700, true);

        $filename = $this->uploadOneTestImage($sourceDir);
        $this->assertUploadedFilesExist($sourceDir, $filename);

        $this->seedEmptyImageBaseline($this->testCarId);
        $this->seedEmptyImageBaseline($this->testMergeCarId);

        $repo = new CarRepository($this->db);
        $processor = new CarImageProcessor($repo);
        $sourceJson = $processor->encodeImages([$filename]);
        $this->assertTrue($repo->updateImage($this->testMergeCarId, $sourceJson, ''));

        $targetCarData = $repo->findById($this->testCarId);
        $this->assertIsObject($targetCarData);

        $this->administrationServiceWithTempRelocator()->merge(
            $targetCarData,
            $this->testMergeCarId,
            'Variant relocation test',
            $this->testUserId,
            $repo
        );
        $this->untrackCarId($this->testMergeCarId);

        $this->assertUploadedFilesExist($targetDir, $filename);
        $this->assertVariantsAreActuallyResized($targetDir, $filename);

        $stored = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        $row = $stored->first();
        $this->assertIsObject($row);
        $decoded = json_decode($row->image, true);
        $this->assertSame([$filename], $decoded);
        foreach ($decoded as $storedFilename) {
            $this->assertStringNotContainsString('-resized-', $storedFilename, 'only base filenames belong in cars.image');
        }
    }

    /**
     * A source car with no image directory at all is intended to merge
     * exactly as it did before this feature existed: no throw, the target's
     * images untouched, and the cars_hist MERGE row written normally.
     *
     * This case guards a CAS trap found while writing it. When the source has
     * no images, relocate() returns an empty rename map, so the target's
     * merged image JSON is byte-identical to its pre-merge value.
     * CarRepository::updateImage() reports success via
     * PDOStatement::rowCount(), which MySQL's PDO driver reports as *rows
     * changed*, not *rows matched*, absent PDO::MYSQL_ATTR_FOUND_ROWS (not set
     * in users/classes/DB.php). A same-value UPDATE therefore reports 0
     * affected rows even though the WHERE clause matched exactly one row.
     * merge() avoids this by skipping the write entirely when the JSON is
     * unchanged, so a merge whose source has no images must SUCCEED here.
     * A CarDatabaseException from this test means that guard was lost.
     */
    #[Group('fast')]
    public function testMergeSucceedsWhenSourceHasNoImageDirectory(): void
    {
        $targetDir = $this->imageDirFor($this->testCarId);
        mkdir($targetDir, 0700, true);
        $targetFilename = $this->uploadOneTestImage($targetDir);

        $this->seedEmptyImageBaseline($this->testCarId);

        $repo = new CarRepository($this->db);
        $processor = new CarImageProcessor($repo);
        $targetJson = $processor->encodeImages([$targetFilename]);
        $this->assertTrue($repo->updateImage($this->testCarId, $targetJson, ''));

        // testMergeCarId's image directory is deliberately never created.
        $this->assertDirectoryDoesNotExist($this->imageDirFor($this->testMergeCarId));

        $targetCarData = $repo->findById($this->testCarId);
        $this->assertIsObject($targetCarData);

        // A source car with no image directory must merge exactly as it did
        // before #1867 — the relocator no-ops and the image column is left
        // alone. merge() skips the write entirely when the merged JSON is
        // unchanged, so the no-op UPDATE that MySQL would report as 0 rows
        // changed (PDO::MYSQL_ATTR_FOUND_ROWS is unset) is never issued.
        // merge() is typed `: true`, so reaching the next line at all is the
        // assertion — it throws on every failure path.
        $this->administrationServiceWithTempRelocator()->merge(
            $targetCarData,
            $this->testMergeCarId,
            'No source image directory test',
            $this->testUserId,
            $repo
        );

        $this->assertFalse(
            (new Car($this->testMergeCarId))->exists(),
            'the source car must be deleted by a successful merge'
        );

        // The merge succeeded, so the source row is gone — untrack it or
        // tearDown() logs a spurious cleanup NOTE for an already-deleted row.
        $this->untrackCarId($this->testMergeCarId);

        // The target's own images are untouched: nothing was relocated in.
        $stored = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        $row = $stored->first();
        $this->assertIsObject($row);
        $this->assertSame($targetJson, $row->image, 'target images must be unchanged when the source had none');

        $this->assertUploadedFilesExist($targetDir, $targetFilename);

        // The audit trail is written normally.
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'MERGE'",
            [$this->testCarId]
        );
        $this->assertSame(1, $historyQuery->count(), 'the MERGE audit row must be written as usual');
    }

    /**
     * A move failure (target directory made unwritable) must roll back the
     * entire merge: the throw propagates, the source cars row still exists,
     * no MERGE row lands in cars_hist, and the source's files are still in
     * place (the compensating restore() undid the partial move).
     *
     * Skipped when running as root: root bypasses permission bits, which
     * would turn chmod(0500) into a silent false pass rather than a real
     * failure test.
     */
    #[Group('fast')]
    public function testMergeRollsBackWhenImageMoveFails(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Running as root bypasses filesystem permission bits, making this test meaningless.');
        }

        $sourceDir = $this->imageDirFor($this->testMergeCarId);
        $targetDir = $this->imageDirFor($this->testCarId);
        mkdir($sourceDir, 0700, true);
        // The target directory must already exist so relocate() attempts to
        // move a file into it rather than creating it fresh (a freshly
        // created 0755 dir would not necessarily inherit the chmod below in
        // a way that blocks the move on all platforms) — create then lock it.
        mkdir($targetDir, 0700, true);

        $filename = $this->uploadOneTestImage($sourceDir);

        $this->seedEmptyImageBaseline($this->testCarId);
        $this->seedEmptyImageBaseline($this->testMergeCarId);

        $repo = new CarRepository($this->db);
        $processor = new CarImageProcessor($repo);
        $sourceJson = $processor->encodeImages([$filename]);
        $this->assertTrue($repo->updateImage($this->testMergeCarId, $sourceJson, ''));

        $targetCarData = $repo->findById($this->testCarId);
        $this->assertIsObject($targetCarData);

        // Read-only + no-execute: rename() into this directory must fail.
        chmod($targetDir, 0500);

        try {
            $threw = false;
            try {
                $this->administrationServiceWithTempRelocator()->merge(
                    $targetCarData,
                    $this->testMergeCarId,
                    'Move failure rollback test',
                    $this->testUserId,
                    $repo
                );
            } catch (\Throwable $e) {
                $threw = true;
                // ImageProcessingException extends ElanRegistryException, not CarException,
                // so merge()'s catch block (which only re-throws CarException subtypes as-is)
                // wraps it in CarMergeException — this assertion follows that documented
                // exception-mapping behavior rather than the raw relocator exception.
                $this->assertInstanceOf(
                    CarMergeException::class,
                    $e,
                    'merge() must wrap a non-CarException image move failure in CarMergeException'
                );
            }
            $this->assertTrue($threw, 'merge() must throw when the image move fails');
        } finally {
            // Restore write access unconditionally before any further assertions
            // or cleanup, per the plan's hazard list — a locked temp dir leaks
            // silently across the rest of this processIsolation="false" run
            // otherwise (recursiveRemoveDirectory() only logs to STDERR).
            if (is_dir($targetDir)) {
                chmod($targetDir, 0700);
            }
        }

        // Source cars row must still exist — the DB transaction was rolled back.
        $sourceRow = $this->db->query('SELECT id FROM cars WHERE id = ?', [$this->testMergeCarId]);
        $this->assertSame(1, $sourceRow->count(), 'source car row must survive a rolled-back merge');

        // No MERGE audit row on the target car.
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'MERGE'",
            [$this->testCarId]
        );
        $this->assertSame(0, $historyQuery->count(), 'no MERGE history row must be written when the merge rolls back');

        // The source's files must still be in place — restore() moved them back.
        $this->assertUploadedFilesExist($sourceDir, $filename);
    }

    /**
     * The compensating restore(), verified at the integration layer with files
     * that actually moved first.
     *
     * testMergeRollsBackWhenImageMoveFails() locks the target directory before
     * anything moves, so relocate() fails on the FIRST file and there is
     * nothing to compensate — its final file assertion passes because the file
     * never left, not because restore() worked. Mutation testing confirmed the
     * gap: replacing restore() with an unconditional `return []` left every
     * integration test green.
     *
     * Here two base files are relocated and the SECOND one's destination is
     * pre-occupied, so the move fails only after the first file (and its
     * variants) have already been moved into the target directory. Passing
     * therefore requires restore() to actually move them back.
     */
    #[Group('fast')]
    public function testMergeRestoresAlreadyMovedFilesWhenALaterMoveFails(): void
    {
        $sourceDir = $this->imageDirFor($this->testMergeCarId);
        $targetDir = $this->imageDirFor($this->testCarId);
        mkdir($sourceDir, 0700, true);
        mkdir($targetDir, 0700, true);

        $firstFilename = $this->uploadOneTestImage($sourceDir);
        $secondFilename = $this->uploadOneTestImage($sourceDir);
        $this->assertNotSame($firstFilename, $secondFilename);

        // Occupy one of the SECOND file's variant destinations. moveFile()
        // refuses to overwrite an existing file, so the move of the second
        // base fails after the first base has already been relocated.
        $blockedVariant = $this->variantPaths($targetDir, $secondFilename)[0] ?? null;
        $this->assertIsString($blockedVariant, 'fixture must produce at least one resized variant');
        file_put_contents($blockedVariant, 'pre-existing orphan variant');

        $this->seedEmptyImageBaseline($this->testCarId);
        $this->seedEmptyImageBaseline($this->testMergeCarId);

        $repo = new CarRepository($this->db);
        $processor = new CarImageProcessor($repo);
        $sourceJson = $processor->encodeImages([$firstFilename, $secondFilename]);
        $this->assertTrue($repo->updateImage($this->testMergeCarId, $sourceJson, ''));

        $targetCarData = $repo->findById($this->testCarId);
        $this->assertIsObject($targetCarData);

        $threw = false;
        try {
            $this->administrationServiceWithTempRelocator()->merge(
                $targetCarData,
                $this->testMergeCarId,
                'Partial move compensation test',
                $this->testUserId,
                $repo
            );
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'merge() must throw when a later image move fails');

        // The DB rolled back.
        $sourceRow = $this->db->query('SELECT id FROM cars WHERE id = ?', [$this->testMergeCarId]);
        $this->assertSame(1, $sourceRow->count(), 'source car row must survive a rolled-back merge');

        // The load-bearing assertion: the FIRST file had already been moved
        // into the target directory, so it is back only if restore() ran.
        $this->assertUploadedFilesExist($sourceDir, $firstFilename);
        $this->assertFileDoesNotExist(
            $targetDir . '/' . $firstFilename,
            'the already-relocated file must not be left behind in the target directory'
        );
    }

    /**
     * A target car whose `image` column is genuinely NULL must merge, exercising
     * the null-safe `image <=> ?` CAS against a real NULL rather than the ''
     * baseline every other test in this class seeds.
     *
     * createTestCar() leaves `image` NULL, so this test deliberately skips
     * seedEmptyImageBaseline() for the target. Under the previous `image = ?`
     * predicate the CAS could never match, and no test passed NULL as the
     * expected value.
     */
    #[Group('fast')]
    public function testMergeSucceedsWhenTargetImageColumnIsNull(): void
    {
        $sourceDir = $this->imageDirFor($this->testMergeCarId);
        mkdir($sourceDir, 0700, true);
        $filename = $this->uploadOneTestImage($sourceDir);

        // Only the SOURCE gets a baseline; the target keeps its NULL image.
        $this->seedEmptyImageBaseline($this->testMergeCarId);

        $repo = new CarRepository($this->db);
        $processor = new CarImageProcessor($repo);
        $this->assertTrue(
            $repo->updateImage($this->testMergeCarId, $processor->encodeImages([$filename]), '')
        );

        $targetBefore = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        $this->assertNull(
            $targetBefore->first()->image,
            'this test is only meaningful while the target image column is NULL'
        );

        $targetCarData = $repo->findById($this->testCarId);
        $this->assertIsObject($targetCarData);

        // merge() is typed `: true`, so its return value asserts nothing — the
        // source row's disappearance is what proves the merge committed.
        $this->administrationServiceWithTempRelocator()->merge(
            $targetCarData,
            $this->testMergeCarId,
            'NULL image column CAS test',
            $this->testUserId,
            $repo
        );

        $sourceRow = $this->db->query('SELECT id FROM cars WHERE id = ?', [$this->testMergeCarId]);
        $this->assertSame(0, $sourceRow->count(), 'the source car row must be gone after a committed merge');

        $targetAfter = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        $this->assertSame(
            [$filename],
            json_decode((string) $targetAfter->first()->image, true),
            'the source filename must be written onto a target whose image column started NULL'
        );
    }

    /**
     * Direct CarRepository::updateImage() CAS live-DB verification: a call
     * with a deliberately wrong expectedJson must return false without
     * throwing and without mutating the row. Mirrors the style of
     * CarImageLifecycleTest::testRemoveImageCasConflictThrowsConcurrentModificationException,
     * but at the repository layer merge() itself calls.
     */
    #[Group('fast')]
    public function testUpdateImageCasReturnsFalseOnConflictWithoutMutatingRow(): void
    {
        $this->seedEmptyImageBaseline($this->testCarId);

        $repo = new CarRepository($this->db);
        $processor = new CarImageProcessor($repo);

        $originalJson = $processor->encodeImages(['original_image.jpg']);
        $this->assertTrue($repo->updateImage($this->testCarId, $originalJson, ''));

        $wrongExpectedJson = $processor->encodeImages(['not_the_current_value.jpg']);
        $newJson = $processor->encodeImages(['attempted_overwrite.jpg']);

        $result = $repo->updateImage($this->testCarId, $newJson, $wrongExpectedJson);
        $this->assertFalse($result, 'updateImage() must return false, not throw, on a CAS conflict');

        $stored = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        $row = $stored->first();
        $this->assertIsObject($row);
        $this->assertSame($originalJson, $row->image, 'a rejected CAS write must not mutate the row');
    }
}
