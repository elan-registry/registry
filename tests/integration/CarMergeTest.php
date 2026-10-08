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

#[Group('integration')]
final class CarMergeTest extends IntegrationTestCase
{
    use CarImageFixtureTrait;

    private $testCarId;
    private $testMergeCarId;
    private $testUserId;

    private string $imageTempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->initThumbnailSizes();

        $this->testUserId = $this->createTestUser();

        $this->loginAsTestUser($this->testUserId);

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
        // A test may leave the target dir at 0500; restore write access first or the temp dir leaks.
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

    private function imageDirFor(int $carId): string
    {
        return $this->imageTempRoot . $carId . '/';
    }

    /**
     * Plain UPDATE, not updateImage(), so the '' baseline does not depend on the code under test.
     */
    private function seedEmptyImageBaseline(int $carId): void
    {
        $result = $this->db->query('UPDATE cars SET image = ? WHERE id = ?', ['', $carId]);
        if ($result->error()) {
            $this->fail("Failed to seed empty image baseline for car {$carId}: " . $result->errorString());
        }
    }

    /**
     * Car::merge() has no injection point for the relocator, so tests call the
     * service directly to keep file moves out of the production userimages/ tree.
     */
    private function administrationServiceWithTempRelocator(): CarAdministrationService
    {
        return new CarAdministrationService(new CarImageRelocator(rtrim($this->imageTempRoot, '/')));
    }

    #[Group('fast')]
    public function testMergeCarSuccessWithValidOldCar(): void
    {
        $car = new Car($this->testCarId);
        $result = $car->merge($this->testMergeCarId, 'Test merge success', $this->testUserId);

        $this->assertTrue($result);
    }

    #[Group('fast')]
    public function testMergeCarFailsWhenTargetNotExists(): void
    {
        $this->expectException(CarNotFoundException::class);

        $car = new Car(99999);
        $car->merge($this->testMergeCarId, 'Test merge', $this->testUserId);
    }

    #[Group('fast')]
    public function testMergeCarFailsWhenSourceNotExists(): void
    {
        $this->expectException(CarNotFoundException::class);

        $car = new Car($this->testCarId);
        $car->merge(99999, 'Test merge', $this->testUserId);
    }

    #[Group('fast')]
    public function testMergeCarFailsWhenMergingSelf(): void
    {
        $this->expectException(CarValidationException::class);

        $car = new Car($this->testCarId);
        $car->merge($this->testCarId, 'Test merge', $this->testUserId);
    }

    #[Group('fast')]
    public function testMergeTransfersHistoryRecords(): void
    {
        $car = new Car($this->testCarId);
        $result = $car->merge($this->testMergeCarId, 'Test merge history transfer', $this->testUserId);

        $this->assertTrue($result);

        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'MERGE'",
            [$this->testCarId]
        );
        $this->assertGreaterThan(0, $historyQuery->count());
    }

    /**
     * #1887: er_email_events has no FK on car_id; the target owner must keep the bounce/suppression history.
     */
    #[Group('fast')]
    public function testMergeTransfersEmailEventHistoryToSurvivingCar(): void
    {
        $oldCarId = $this->testMergeCarId;
        $survivingCarId = $this->testCarId;

        $this->db->query(
            'INSERT INTO er_email_events (car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, NULL, ?, NOW())',
            [$oldCarId, 'merge-test@example.com', 'hard_bounce', 'merge-test-msg-1']
        );
        $this->assertFalse($this->db->error(), 'Test setup: failed to seed er_email_events row on source car');

        $car = new Car($survivingCarId);
        $result = $car->merge($oldCarId, 'Test merge email event transfer', $this->testUserId);
        $this->assertTrue($result);

        $onOldCar = $this->db->query('SELECT COUNT(*) AS cnt FROM er_email_events WHERE car_id = ?', [$oldCarId])->first();
        $this->assertSame(0, (int) $onOldCar->cnt, 'No er_email_events rows must remain on the merged-away source car id');

        $onSurvivingCar = $this->db->query(
            'SELECT * FROM er_email_events WHERE car_id = ? AND brevo_message_id = ?',
            [$survivingCarId, 'merge-test-msg-1']
        )->first();
        $this->assertIsObject($onSurvivingCar, 'The source car\'s event row must be reassigned to the surviving car, not lost');
        $this->assertSame('hard_bounce', $onSurvivingCar->event);
        $this->assertSame('merge-test@example.com', $onSurvivingCar->email);
    }

    #[Group('fast')]
    public function testMergeDeletesOldCar(): void
    {
        $oldCarId = $this->testMergeCarId;

        $car = new Car($this->testCarId);
        $result = $car->merge($oldCarId, 'Test merge deletes old car', $this->testUserId);

        $this->assertTrue($result);

        $query = $this->db->query('SELECT * FROM cars WHERE id = ?', [$oldCarId]);
        $this->assertEquals(0, $query->count());
    }

    #[Group('fast')]
    public function testMergeCreatesAuditTrail(): void
    {
        $car = new Car($this->testCarId);
        $result = $car->merge($this->testMergeCarId, 'Test merge audit trail', $this->testUserId);

        $this->assertTrue($result);

        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'MERGE'",
            [$this->testCarId]
        );
        $this->assertGreaterThan(0, $historyQuery->count());
    }

    #[Group('fast')]
    public function testMergeTransactionRollbackOnFailure(): void
    {
        $this->expectException(CarNotFoundException::class);

        $car = new Car($this->testCarId);

        try {
            $car->merge(99999, 'Test merge', $this->testUserId);
        } catch (CarNotFoundException $e) {
            $carReloaded = new Car((int) $car->data()->id);
            $this->assertTrue($carReloaded->exists());
            throw $e;
        }
    }

    /** #1311: findByIdForUpdate() path inside the merge transaction. */
    #[Group('fast')]
    public function testMergeAlreadyDeletedSourceCarThrowsCarNotFoundException(): void
    {
        $this->deleteTestCar($this->testMergeCarId);

        // The target car still exists; the source is gone — merge must throw
        $this->expectException(CarNotFoundException::class);
        $car = new Car($this->testCarId);
        $car->merge($this->testMergeCarId, 'Test merge after source deletion', $this->testUserId);
    }

    /**
     * A failure between transferHistory and deleteCar must leave the database consistent.
     */
    #[Group('fast')]
    public function testCarRepositoryTransactionRollbackPreservesCarAndOwnerAssignment(): void
    {
        if ($this->testCarId === null) {
            $this->markTestSkipped('No test cars available');
        }

        // createTestCar() purges hist rows; seed one so transferHistory() has an UPDATE to roll back.
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
            // The suite shares one connection; an open transaction would corrupt the next test.
            if ($this->db->inTransaction()) {
                $repo->rollback();
            }
        }

        $carExistsAfter = $this->db->query(
            'SELECT id FROM cars WHERE id = ?',
            [$this->testCarId]
        )->count();
        $this->assertEquals(
            $carExistsBefore,
            $carExistsAfter,
            'cars row must survive rollback'
        );

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
     * #1929: admin action. Uses images so merge() makes its one UPDATE to the surviving row.
     */
    #[Group('fast')]
    public function testMergeDoesNotChangeOwnerLastUpdatedOnSurvivingCar(): void
    {
        $sourceDir = $this->imageDirFor($this->testMergeCarId);
        mkdir($sourceDir, 0700, true);
        $filename = $this->uploadOneTestImage($sourceDir);

        $this->seedEmptyImageBaseline($this->testCarId);
        $this->seedEmptyImageBaseline($this->testMergeCarId);

        $repo = new CarRepository($this->db);
        $sourceJson = (new CarImageProcessor($repo))->encodeImages([$filename]);
        $this->assertTrue($repo->updateImage($this->testMergeCarId, $sourceJson, ''));

        $targetOld = date('Y-m-d H:i:s', strtotime('-2 years'));
        $this->seedOwnerLastUpdated($this->testCarId, $targetOld);

        $before = $this->getOwnerLastUpdated($this->testCarId);
        $this->assertSame($targetOld, $before, 'Precondition: the seeded value must be stored as written');

        $targetCarData = $repo->findById($this->testCarId);
        $this->assertIsObject($targetCarData);

        $this->administrationServiceWithTempRelocator()->merge(
            $targetCarData,
            $this->testMergeCarId,
            'Merge freshness test',
            $this->testUserId,
            $repo
        );
        $this->untrackCarId($this->testMergeCarId);

        $after = $this->db->query('SELECT image, owner_last_updated FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertIsObject($after);
        $this->assertSame([$filename], json_decode((string) $after->image, true), 'Precondition: merge() must have written cars.image on the surviving car');
        $this->assertSame($before, (string) $after->owner_last_updated, 'merge() must not change owner_last_updated on the surviving car');
    }

    /**
     * Exact order, not set equality: the first entry is the surviving car's card thumbnail.
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

    #[Group('fast')]
    public function testMergeRenamesCollidingFilenameWithoutOverwriting(): void
    {
        $sourceDir = $this->imageDirFor($this->testMergeCarId);
        $targetDir = $this->imageDirFor($this->testCarId);
        mkdir($sourceDir, 0700, true);
        mkdir($targetDir, 0700, true);

        $collidingFilename = 'collision_test_image.jpg';

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
     * Trap: MySQL PDO rowCount() counts rows changed, not matched, so a same-value
     * updateImage() reports 0 rows. merge() must skip the write when the JSON is
     * unchanged; a CarDatabaseException here means that guard was lost.
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

        // merge() is typed `: true` and throws on failure, so reaching the next line is the assertion.
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

        // Untrack the deleted row or tearDown() logs a spurious cleanup NOTE.
        $this->untrackCarId($this->testMergeCarId);

        $stored = $this->db->query('SELECT image FROM cars WHERE id = ?', [$this->testCarId]);
        $row = $stored->first();
        $this->assertIsObject($row);
        $this->assertSame($targetJson, $row->image, 'target images must be unchanged when the source had none');

        $this->assertUploadedFilesExist($targetDir, $targetFilename);

        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'MERGE'",
            [$this->testCarId]
        );
        $this->assertSame(1, $historyQuery->count(), 'the MERGE audit row must be written as usual');
    }

    /**
     * Skipped as root: root ignores chmod(0500), which would make this a false pass.
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
        // Create then lock the dir; a dir that relocate() creates itself may not block the move.
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
                // merge() wraps non-CarException errors (ImageProcessingException) in CarMergeException.
                $this->assertInstanceOf(
                    CarMergeException::class,
                    $e,
                    'merge() must wrap a non-CarException image move failure in CarMergeException'
                );
            }
            $this->assertTrue($threw, 'merge() must throw when the image move fails');
        } finally {
            // Restore write access before any assertion, or the locked temp dir leaks.
            if (is_dir($targetDir)) {
                chmod($targetDir, 0700);
            }
        }

        $sourceRow = $this->db->query('SELECT id FROM cars WHERE id = ?', [$this->testMergeCarId]);
        $this->assertSame(1, $sourceRow->count(), 'source car row must survive a rolled-back merge');

        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'MERGE'",
            [$this->testCarId]
        );
        $this->assertSame(0, $historyQuery->count(), 'no MERGE history row must be written when the merge rolls back');

        $this->assertUploadedFilesExist($sourceDir, $filename);
    }

    /**
     * The move fails on the SECOND file, after the first has moved, so only a
     * working restore() can pass. The locked-dir test above fails before any move.
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

        // moveFile() refuses to overwrite, so occupying this destination fails the second move.
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

        $sourceRow = $this->db->query('SELECT id FROM cars WHERE id = ?', [$this->testMergeCarId]);
        $this->assertSame(1, $sourceRow->count(), 'source car row must survive a rolled-back merge');

        // The first file is back only if restore() ran.
        $this->assertUploadedFilesExist($sourceDir, $firstFilename);
        $this->assertFileDoesNotExist(
            $targetDir . '/' . $firstFilename,
            'the already-relocated file must not be left behind in the target directory'
        );
    }

    /**
     * Exercises the null-safe `image <=> ?` CAS against a real NULL; `image = ?` never matches NULL.
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

        // merge() returns `true` always; the deleted source row proves the commit.
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
