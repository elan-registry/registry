<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/CarImageFixtureTrait.php';

use ElanRegistry\Car\CarPhotoRecovery;
use ElanRegistry\Car\CarPhotoRecoveryResult;
use ElanRegistry\Car\CarPhotoRecoveryRow;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\DetachedHow;
use ElanRegistry\DatabaseInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TestJpeg;

/**
 * #2644: CarPhotoRecovery against real MySQL and the real cars triggers.
 *
 * History is built with real `UPDATE cars SET image`, so the triggers write
 * `cars_hist`. Expected values are literals (file names, JSON), not values
 * that the test computes the way the code does.
 *
 * The photo files live in a temporary image root, passed to the constructor.
 * Nothing is written under `userimages/`. The endpoint test
 * (AdminCarPhotosTabAuthTest) covers the default root.
 */
#[Group('integration')]
#[Group('cars')]
final class CarPhotoRecoveryIntegrationTest extends IntegrationTestCase
{
    use CarImageFixtureTrait;

    private string $root = '';

    private CarPhotoRecovery $recovery;

    private int $userId = 0;

    /** @var list<int> Cars that the test inserted with raw SQL */
    private array $rawCarIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->initThumbnailSizes();
        $this->rawCarIds = [];

        $this->root = rtrim(sys_get_temp_dir(), '/') . '/carphoto-recovery-' . bin2hex(random_bytes(6)) . '/';
        $this->assertTrue(mkdir($this->root, 0755, true), 'Test setup: temporary image root');
        $this->recovery = new CarPhotoRecovery($this->db, $this->root);
        $this->userId = $this->createTestUser(['permissions' => 1]);
    }

    protected function tearDown(): void
    {
        $this->recursiveRemoveDirectory(rtrim($this->root, '/'));
        if ($this->databaseConnected) {
            foreach ($this->rawCarIds as $carId) {
                $this->db->query('DELETE FROM cars WHERE id = ?', [$carId]);
                $this->db->query('DELETE FROM cars_hist WHERE car_id = ?', [$carId]);
            }
            $this->db->query('DELETE FROM logs WHERE user_id = ?', [$this->userId]);
        }
        parent::tearDown();
    }

    // ---- helpers ----------------------------------------------------------

    private function newCar(): int
    {
        return $this->createTestCar($this->userId);
    }

    /** Write a real JPEG. $seed gives different bytes, so the hashes differ. */
    private function photo(int $carId, string $name, int $seed): void
    {
        $dir = $this->root . $carId . '/';
        if (!is_dir($dir)) {
            $this->assertTrue(mkdir($dir, 0755, true), 'Test setup: car directory');
        }
        TestJpeg::write($dir . $name, 40, 30, ($seed * 37) % 256, (255 - $seed * 29) % 256, ($seed * 71) % 256);
    }

    /** @param list<string>|null $names null stores SQL NULL */
    private function setImage(int $carId, ?array $names): void
    {
        $value = $names === null ? null : json_encode($names);
        $this->db->query('UPDATE cars SET image = ? WHERE id = ?', [$value, $carId]);
        $this->assertFalse($this->db->error(), 'Test setup: update image: ' . $this->db->errorString());
    }

    private function currentImage(int $carId): ?string
    {
        $row = $this->db->query('SELECT image FROM cars WHERE id = ?', [$carId])->first();
        $this->assertIsObject($row);
        return $row->image === null ? null : (string) $row->image;
    }

    /** @return list<array{id: int, image: ?string, timestamp: string}> */
    private function history(int $carId): array
    {
        $this->db->query('SELECT id, image, timestamp FROM cars_hist WHERE car_id = ? ORDER BY id', [$carId]);
        $this->assertFalse($this->db->error(), $this->db->errorString());
        $out = [];
        foreach ($this->db->results() as $row) {
            $out[] = ['id' => (int) $row->id, 'image' => $row->image === null ? null : (string) $row->image, 'timestamp' => (string) $row->timestamp];
        }
        return $out;
    }

    /** The version token of the car's `cars.image` now, as the page sends it. */
    private function seen(int $carId): string
    {
        return CarPhotoRecovery::versionToken($this->currentImage($carId));
    }

    private function actionLogCount(int $carId): int
    {
        return $this->countMatchingLogs('CarActions', "Car Photos: car {$carId},%", $this->userId);
    }

    private function onlyRow(int $carId): CarPhotoRecoveryRow
    {
        $rows = $this->recovery->findRows([$carId]);
        $this->assertCount(1, $rows, 'Expected one row for the car');
        return $rows[0];
    }

    /** @return list<string> */
    private function detachedNames(CarPhotoRecoveryRow $row): array
    {
        return $row->detachedNames();
    }

    // ---- find: history written by the triggers -----------------------------

    public function testFindRowsReplacedPhotoUsesTriggerRowAndItsTime(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->setImage($carId, ['a.jpg']);
        $this->setImage($carId, ['b.jpg']);

        $history = array_values(array_filter($this->history($carId), static fn(array $r): bool => $r['image'] !== null));
        $this->assertCount(1, $history);
        $this->assertSame('["a.jpg"]', $history[0]['image'], 'The trigger wrote the OLD list');

        $row = $this->onlyRow($carId);
        $this->assertSame(['b.jpg'], $row->currentNames);
        $this->assertSame(['a.jpg'], $this->detachedNames($row));
        $this->assertSame(DetachedHow::Replaced, $row->detached[0]->howLeft);
        $this->assertSame($history[0]['timestamp'], $row->detached[0]->leftAt);
        $this->assertNull($row->earlierOrder);
    }

    public function testFindRowsInsertTriggerRowIsAStateOfTheCar(): void
    {
        $this->db->insert('cars', [
            'user_id' => $this->userId, 'year' => 1973, 'model' => 'Elan S4', 'series' => 'S4', 'variant' => 'SE',
            'type' => 'FHC', 'chassis' => 'T' . substr(uniqid(), -10), 'color' => 'Red',
            'ctime' => date('Y-m-d H:i:s'), 'image' => '["first.jpg"]',
        ]);
        $this->assertFalse($this->db->error(), $this->db->errorString());
        $carId = (int) $this->db->lastId();
        $this->rawCarIds[] = $carId;
        $this->db->query('DELETE FROM cars_hist WHERE car_id = ? AND image IS NULL', [$carId]);

        $afterInsert = $this->history($carId);
        $this->assertCount(1, $afterInsert, 'The insert trigger wrote one row');
        $this->assertSame('["first.jpg"]', $afterInsert[0]['image'], 'The insert trigger wrote the NEW list');

        $this->photo($carId, 'first.jpg', 1);
        $this->photo($carId, 'second.jpg', 2);
        $this->setImage($carId, ['second.jpg']);

        $row = $this->onlyRow($carId);
        $this->assertSame(['first.jpg'], $this->detachedNames($row));
        $this->assertSame(DetachedHow::Replaced, $row->detached[0]->howLeft);
        $updateRow = $this->history($carId)[1];
        $this->assertSame($updateRow['timestamp'], $row->detached[0]->leftAt, 'The time is the time of the update row');
    }

    public function testFindRowsRemoveAllFlowReportsRemoved(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->setImage($carId, ['a.jpg', 'b.jpg']);
        $this->setImage($carId, []);

        $row = $this->onlyRow($carId);
        $this->assertSame([], $row->currentNames);
        $this->assertSame(['a.jpg', 'b.jpg'], $this->detachedNames($row));
        foreach ($row->detached as $photo) {
            $this->assertSame(DetachedHow::Removed, $photo->howLeft);
        }
    }

    public function testFindRowsNullImageReadsAsNoPhotos(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->setImage($carId, ['a.jpg']);
        $this->setImage($carId, null);
        $this->assertNull($this->currentImage($carId));

        $row = $this->onlyRow($carId);
        $this->assertSame([], $row->currentNames);
        $this->assertSame(['a.jpg'], $this->detachedNames($row));
        $this->assertSame(DetachedHow::Removed, $row->detached[0]->howLeft);
    }

    public function testFindRowsPartialFlowReportsPartlyRemoved(): void
    {
        $carId = $this->newCar();
        foreach (['a.jpg', 'b.jpg', 'c.jpg'] as $i => $name) {
            $this->photo($carId, $name, $i + 1);
        }
        $this->setImage($carId, ['a.jpg', 'b.jpg', 'c.jpg']);
        $this->setImage($carId, ['a.jpg', 'c.jpg']);

        $row = $this->onlyRow($carId);
        $this->assertSame(['b.jpg'], $this->detachedNames($row));
        $this->assertSame(DetachedHow::PartlyRemoved, $row->detached[0]->howLeft);
    }

    public function testFindRowsDropInMiddleOfSeveralSavesGetsItsOwnSaveTime(): void
    {
        $carId = $this->newCar();
        foreach (['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg'] as $i => $name) {
            $this->photo($carId, $name, $i + 1);
        }
        $this->setImage($carId, ['a.jpg', 'b.jpg']);
        $this->setImage($carId, ['c.jpg']);
        $this->setImage($carId, ['d.jpg']);

        // The triggers stamp whole seconds, so give the two rows known, different times.
        $history = array_values(array_filter($this->history($carId), static fn(array $r): bool => $r['image'] !== null));
        $this->assertCount(2, $history);
        $this->assertSame('["a.jpg","b.jpg"]', $history[0]['image']);
        $this->assertSame('["c.jpg"]', $history[1]['image']);
        $this->db->query('UPDATE cars_hist SET timestamp = ? WHERE id = ?', ['2026-01-01 10:00:00', $history[0]['id']]);
        $this->db->query('UPDATE cars_hist SET timestamp = ? WHERE id = ?', ['2026-02-02 11:30:00', $history[1]['id']]);
        $this->assertFalse($this->db->error(), $this->db->errorString());

        $row = $this->onlyRow($carId);
        $byName = [];
        foreach ($row->detached as $photo) {
            $byName[$photo->name] = $photo;
        }
        $this->assertSame(['a.jpg', 'b.jpg', 'c.jpg'], array_keys($byName));
        $this->assertSame('2026-01-01 10:00:00', $byName['a.jpg']->leftAt);
        $this->assertSame('2026-01-01 10:00:00', $byName['b.jpg']->leftAt);
        $this->assertSame('2026-02-02 11:30:00', $byName['c.jpg']->leftAt);
        $this->assertSame(DetachedHow::Replaced, $byName['a.jpg']->howLeft);
        $this->assertSame(DetachedHow::Replaced, $byName['c.jpg']->howLeft);
    }

    // ---- find: exclusions -------------------------------------------------

    public function testFindRowsOrphanFileIsNotShown(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->photo($carId, 'orphan.jpg', 3);
        $this->setImage($carId, ['a.jpg']);
        $this->setImage($carId, ['b.jpg']);

        $this->assertSame(['a.jpg'], $this->detachedNames($this->onlyRow($carId)));
    }

    /** @return array<string, array{string}> */
    public static function resizedNameProvider(): array
    {
        return [
            'resized-100' => ['a-resized-100.jpg'],
            'resized-500' => ['a-resized-500.jpg'],
        ];
    }

    #[DataProvider('resizedNameProvider')]
    public function testFindRowsResizedCopyInHistoryIsNotShown(string $resizedName): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, $resizedName, 4);
        $this->photo($carId, 'b.jpg', 2);
        $this->setImage($carId, ['a.jpg', $resizedName]);
        $this->setImage($carId, ['b.jpg']);

        $this->assertSame(['a.jpg'], $this->detachedNames($this->onlyRow($carId)));
    }

    public function testFindRowsNonImageFilesAreNotShown(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $dir = $this->root . $carId . '/';
        file_put_contents($dir . 'notes.txt', 'text');
        file_put_contents($dir . 'fake.jpg', 'this is not a jpeg');
        $this->setImage($carId, ['a.jpg', 'notes.txt', 'fake.jpg']);
        $this->setImage($carId, ['b.jpg']);

        $this->assertSame(['a.jpg'], $this->detachedNames($this->onlyRow($carId)));
    }

    public function testFindRowsFileWithSameBytesAsAttachedPhotoIsNotShown(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        copy($this->root . $carId . '/b.jpg', $this->root . $carId . '/b-copy.jpg');
        $this->setImage($carId, ['a.jpg', 'b-copy.jpg']);
        $this->setImage($carId, ['b.jpg']);

        $row = $this->onlyRow($carId);
        $this->assertSame(['a.jpg'], $this->detachedNames($row), 'b-copy.jpg has the bytes of attached b.jpg');
    }

    public function testFindRowsDuplicateCandidatesCollapseToOne(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'z.jpg', 2);
        copy($this->root . $carId . '/a.jpg', $this->root . $carId . '/a2.jpg');
        $this->setImage($carId, ['a.jpg', 'a2.jpg']);
        $this->setImage($carId, ['z.jpg']);

        $names = $this->detachedNames($this->onlyRow($carId));
        $this->assertCount(1, $names);
        $this->assertSame(['a.jpg'], $names, 'Same state and safe names: the first name in sort order stays');
    }

    public function testFindRowsOtherCarsDoNotLeakIntoAFilteredCall(): void
    {
        $carA = $this->newCar();
        $carB = $this->newCar();
        foreach ([$carA, $carB] as $carId) {
            $this->photo($carId, 'a.jpg', 1);
            $this->photo($carId, 'b.jpg', 2);
            $this->setImage($carId, ['a.jpg']);
            $this->setImage($carId, ['b.jpg']);
        }

        $filtered = $this->recovery->findRows([$carA]);
        $this->assertCount(1, $filtered);
        $this->assertSame($carA, $filtered[0]->carId);

        $all = array_map(static fn(CarPhotoRecoveryRow $r): int => $r->carId, $this->recovery->findRows());
        $this->assertContains($carA, $all);
        $this->assertContains($carB, $all);
        $this->assertSame([], $this->recovery->findRows([]));
    }

    public function testFindRowsCarWithoutDirectoryHasNoRow(): void
    {
        $carId = $this->newCar();
        $this->setImage($carId, ['a.jpg']);
        $this->setImage($carId, ['b.jpg']);

        $this->assertSame([], $this->recovery->findRows([$carId]));
    }

    public function testFindRowsReversedOrderIsOfferedWithTheHistoryTime(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->setImage($carId, ['a.jpg', 'b.jpg']);
        $this->setImage($carId, ['b.jpg', 'a.jpg']);

        $row = $this->onlyRow($carId);
        $this->assertSame(['a.jpg', 'b.jpg'], $row->earlierOrder);
        $this->assertSame([], $row->detached);
        $history = array_values(array_filter($this->history($carId), static fn(array $r): bool => $r['image'] !== null));
        $this->assertSame($history[0]['timestamp'], $row->earlierOrderAt);
    }

    // ---- apply ------------------------------------------------------------

    public function testApplyReattachWritesKnownJsonOneHistRowAndOneLogRow(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->setImage($carId, ['a.jpg']);
        $this->setImage($carId, ['b.jpg']);
        $histBefore = count($this->history($carId));
        $this->assertSame(0, $this->actionLogCount($carId));

        $result = $this->recovery->apply($carId, ['a.jpg'], false, $this->userId, $this->seen($carId));

        $this->assertSame(CarPhotoRecoveryResult::Applied, $result);
        $this->assertSame('["b.jpg","a.jpg"]', $this->currentImage($carId));
        $history = $this->history($carId);
        $this->assertCount($histBefore + 1, $history, 'The trigger wrote one more row');
        $this->assertSame('["b.jpg"]', $history[count($history) - 1]['image'], 'The new row holds the list from before the apply');
        $this->assertSame(1, $this->actionLogCount($carId));
        $this->assertSame([], $this->recovery->findRows([$carId]), 'Nothing is detached after the apply');
    }

    public function testApplyRestoreThenReattachWritesRestoredOrderFirst(): void
    {
        $carId = $this->newCar();
        foreach (['a.jpg', 'b.jpg', 'c.jpg'] as $i => $name) {
            $this->photo($carId, $name, $i + 1);
        }
        $this->setImage($carId, ['a.jpg', 'b.jpg']);
        $this->setImage($carId, ['a.jpg', 'b.jpg', 'c.jpg']);
        $this->setImage($carId, ['b.jpg', 'a.jpg']);

        $result = $this->recovery->apply($carId, ['c.jpg'], true, $this->userId, $this->seen($carId));

        $this->assertSame(CarPhotoRecoveryResult::Applied, $result);
        $this->assertSame('["a.jpg","b.jpg","c.jpg"]', $this->currentImage($carId));
        $this->assertSame(1, $this->actionLogCount($carId));
    }

    public function testApplyRestoreOnlyWritesReversedList(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->setImage($carId, ['a.jpg', 'b.jpg']);
        $this->setImage($carId, ['b.jpg', 'a.jpg']);

        $this->assertSame(CarPhotoRecoveryResult::Applied, $this->recovery->apply($carId, [], true, $this->userId, $this->seen($carId)));
        $this->assertSame('["a.jpg","b.jpg"]', $this->currentImage($carId));
    }

    public function testApplyWithBothFlagsOffWritesNothing(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->setImage($carId, ['a.jpg']);
        $this->setImage($carId, ['b.jpg']);
        $histBefore = count($this->history($carId));

        $result = $this->recovery->apply($carId, [], false, $this->userId, $this->seen($carId));

        $this->assertSame(CarPhotoRecoveryResult::NothingToDo, $result);
        $this->assertSame('["b.jpg"]', $this->currentImage($carId));
        $this->assertCount($histBefore, $this->history($carId));
        $this->assertSame(0, $this->actionLogCount($carId));
    }

    public function testApplyReattachOfCarWithNothingDetachedIsNothingToDo(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->setImage($carId, ['a.jpg']);
        $histBefore = count($this->history($carId));

        $this->assertSame(CarPhotoRecoveryResult::NothingToDo, $this->recovery->apply($carId, ['a.jpg'], true, $this->userId, $this->seen($carId)));
        $this->assertCount($histBefore, $this->history($carId));
        $this->assertSame(0, $this->actionLogCount($carId));
    }

    public function testApplyNeverAttachesAnUnsafeName(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'my photo.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->setImage($carId, ['my photo.jpg']);
        $this->setImage($carId, ['b.jpg']);
        $histBefore = count($this->history($carId));

        $row = $this->onlyRow($carId);
        $this->assertSame(['my photo.jpg'], $this->detachedNames($row));
        $this->assertFalse($row->detached[0]->safe);

        $this->assertSame(CarPhotoRecoveryResult::NothingToDo, $this->recovery->apply($carId, ['my photo.jpg'], false, $this->userId, $this->seen($carId)));
        $this->assertSame('["b.jpg"]', $this->currentImage($carId));
        $this->assertCount($histBefore, $this->history($carId));
    }

    public function testApplyReattachOnNullImageWritesTheDetachedList(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->setImage($carId, ['a.jpg']);
        $this->setImage($carId, null);

        $this->assertSame(CarPhotoRecoveryResult::Applied, $this->recovery->apply($carId, ['a.jpg'], false, $this->userId, $this->seen($carId)));
        $this->assertSame('["a.jpg"]', $this->currentImage($carId));
    }

    public function testApplyCompareAndSetMissWritesNothingAndLogsNothing(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->photo($carId, 'c.jpg', 3);
        $this->setImage($carId, ['a.jpg']);
        $this->setImage($carId, ['b.jpg']);

        // The competing save lands after apply() read the car and before its
        // write. The real updateImage() compare-and-set then runs unchanged.
        $db = $this->db;
        $racingRepository = new class ($db, $carId) extends CarRepository {
            public function __construct(private DatabaseInterface $racingDb, private int $racingCarId)
            {
                parent::__construct($racingDb);
            }

            public function updateImage(int $carId, string $newJson, ?string $expectedJson, ?string $ownerLastUpdated = null): bool
            {
                $this->racingDb->query('UPDATE cars SET image = ? WHERE id = ?', ['["c.jpg"]', $this->racingCarId]);
                return parent::updateImage($carId, $newJson, $expectedJson, $ownerLastUpdated);
            }
        };
        $recovery = new CarPhotoRecovery($this->db, $this->root, $racingRepository);

        $result = $recovery->apply($carId, ['a.jpg'], false, $this->userId, $this->seen($carId));

        $this->assertSame(CarPhotoRecoveryResult::Changed, $result);
        $this->assertSame('["c.jpg"]', $this->currentImage($carId), 'The competing value stays');
        $historyAfter = $this->history($carId);
        $this->assertSame('["b.jpg"]', $historyAfter[count($historyAfter) - 1]['image'], 'Only the competing save wrote a hist row');
        $this->assertSame(0, $this->actionLogCount($carId));
    }

    // ---- helpers for ticked photos, ignore and order restore -----------------

    /** A car with three detached photos x1, x2, x3 (stored in that order) and the current photo keep.jpg. */
    private function carWithThreeDetached(): int
    {
        $carId = $this->newCar();
        foreach (['x1.jpg', 'x2.jpg', 'x3.jpg', 'keep.jpg'] as $i => $name) {
            $this->photo($carId, $name, $i + 1);
        }
        $this->setImage($carId, ['x1.jpg', 'x2.jpg', 'x3.jpg']);
        $this->setImage($carId, ['keep.jpg']);
        return $carId;
    }

    /** A car with a replaced photo: current b.jpg, detached a.jpg. */
    private function carWithOneDetached(): int
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->setImage($carId, ['a.jpg']);
        $this->setImage($carId, ['b.jpg']);
        return $carId;
    }

    /** A car whose current list [b, a] is the reverse of an earlier list [a, b]. */
    private function carWithReversedOrder(): int
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        $this->setImage($carId, ['a.jpg', 'b.jpg']);
        $this->setImage($carId, ['b.jpg', 'a.jpg']);
        return $carId;
    }

    /**
     * The decoded metadata of the CarActions log rows of a car, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function decisionMetadata(int $carId): array
    {
        $this->db->query(
            "SELECT metadata FROM logs WHERE logtype = 'CarActions' AND user_id = ? AND (lognote LIKE ? OR lognote LIKE ?) ORDER BY id",
            [$this->userId, "Car Photos: car {$carId},%", "Car Photos: car {$carId} ignored with%"]
        );
        $this->assertFalse($this->db->error(), $this->db->errorString());
        $out = [];
        foreach ($this->db->results() as $row) {
            $decoded = json_decode((string) $row->metadata, true);
            $this->assertIsArray($decoded);
            $out[] = $decoded;
        }
        return $out;
    }

    // ---- apply: ticked photos --------------------------------------------

    public function testApplyTickedSubsetAttachesOnlyThoseInStoredOrderAndLeavesTheRestDetached(): void
    {
        $carId = $this->carWithThreeDetached();

        $result = $this->recovery->apply($carId, ['x3.jpg', 'x1.jpg'], false, $this->userId, $this->seen($carId), $ignored);

        $this->assertSame(CarPhotoRecoveryResult::Applied, $result);
        $this->assertSame('["keep.jpg","x1.jpg","x3.jpg"]', $this->currentImage($carId), 'Stored order, not tick order');
        $this->assertSame([], $ignored);
        $this->assertSame(['x2.jpg'], $this->detachedNames($this->onlyRow($carId)), 'The unticked photo stays detached');
    }

    public function testApplyForgedAndUnsafeNamesAreReturnedAsIgnoredAndNeverAttached(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'my photo.jpg', 1);
        $this->photo($carId, 'a.jpg', 2);
        $this->photo($carId, 'b.jpg', 3);
        $this->setImage($carId, ['my photo.jpg', 'a.jpg']);
        $this->setImage($carId, ['b.jpg']);

        $result = $this->recovery->apply($carId, ['a.jpg', 'evil.jpg', 'my photo.jpg', 'a.jpg'], false, $this->userId, $this->seen($carId), $ignored);

        $this->assertSame(CarPhotoRecoveryResult::Applied, $result);
        $this->assertSame('["b.jpg","a.jpg"]', $this->currentImage($carId));
        $this->assertSame(['evil.jpg', 'my photo.jpg'], $ignored);
    }

    public function testApplyChangedReturnsNoIgnoredNamesAndWritesNothing(): void
    {
        $carId = $this->carWithOneDetached();
        $staleToken = $this->seen($carId);
        $this->setImage($carId, ['b.jpg', 'x.jpg']);
        $histBefore = count($this->history($carId));

        $result = $this->recovery->apply($carId, ['a.jpg', 'evil.jpg'], false, $this->userId, $staleToken, $ignored);

        $this->assertSame(CarPhotoRecoveryResult::Changed, $result);
        $this->assertSame([], $ignored, 'The page saw other data, so no name is judged');
        $this->assertCount($histBefore, $this->history($carId));
        $this->assertSame(0, $this->actionLogCount($carId));
    }

    public function testApplyStaleTokenWhenTheCarNoLongerHasARowReturnsChanged(): void
    {
        $carId = $this->carWithOneDetached();
        $staleToken = $this->seen($carId);
        // Another session reattaches a.jpg: nothing is detached, so the car has no row.
        $this->setImage($carId, ['b.jpg', 'a.jpg']);
        $this->assertSame([], $this->recovery->findRows([$carId]), 'Test setup: no row now');

        $this->assertSame(CarPhotoRecoveryResult::Changed, $this->recovery->apply($carId, ['a.jpg'], false, $this->userId, $staleToken));
        $this->assertSame('["b.jpg","a.jpg"]', $this->currentImage($carId));
        $this->assertSame(0, $this->actionLogCount($carId));
    }

    public function testApplyCurrentTokenWhenTheCarHasNoRowReturnsNothingToDoAndNoIgnoredNames(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->setImage($carId, ['a.jpg']);

        $result = $this->recovery->apply($carId, ['evil.jpg'], false, $this->userId, $this->seen($carId), $ignored);

        $this->assertSame(CarPhotoRecoveryResult::NothingToDo, $result);
        $this->assertSame([], $ignored, 'No row was checked, so no ticked name is proven false');
    }

    // ---- apply log row and the token_after rule ---------------------------

    public function testApplyLogRowHoldsActionCarIdAndTokenAfterOfTheListItWrote(): void
    {
        $carId = $this->carWithOneDetached();

        $this->assertSame(CarPhotoRecoveryResult::Applied, $this->recovery->apply($carId, ['a.jpg'], false, $this->userId, $this->seen($carId)));

        $metadata = $this->decisionMetadata($carId);
        $this->assertCount(1, $metadata);
        $this->assertSame('car_photos_apply', $metadata[0]['action']);
        $this->assertSame($carId, $metadata[0]['car_id']);
        $this->assertSame(['a.jpg'], $metadata[0]['reattached']);
        $this->assertFalse($metadata[0]['order_restored']);
        $this->assertSame(hash('sha256', 'string:["b.jpg","a.jpg"]'), $metadata[0]['token_after']);
    }

    public function testRestoreHidesTheRestoreOfferUntilALaterSaveChangesTheList(): void
    {
        $carId = $this->carWithReversedOrder();
        $this->assertNotNull($this->onlyRow($carId)->earlierOrder, 'Test setup: a restore is offered');

        $this->assertSame(CarPhotoRecoveryResult::Applied, $this->recovery->apply($carId, [], true, $this->userId, $this->seen($carId)));

        // The cars_update trigger wrote [b, a], so the list [a, b] is again the reverse of a history list.
        $history = $this->history($carId);
        $this->assertSame('["b.jpg","a.jpg"]', $history[count($history) - 1]['image']);
        $this->assertSame([], (new CarPhotoRecovery($this->db, $this->root))->findRows([$carId]), 'A new instance reads the log: no row');

        // A later save puts [b, a] in place by hand. The token differs from token_after.
        $this->setImage($carId, ['b.jpg', 'a.jpg']);
        $this->assertSame(['a.jpg', 'b.jpg'], $this->onlyRow($carId)->earlierOrder, 'The offer returns');
    }

    public function testReattachOnlyApplyDoesNotHideALaterRestoreOffer(): void
    {
        $carId = $this->newCar();
        foreach (['a.jpg', 'b.jpg', 'c.jpg'] as $i => $name) {
            $this->photo($carId, $name, $i + 1);
        }
        $this->setImage($carId, ['c.jpg', 'a.jpg', 'b.jpg']);
        $this->setImage($carId, ['a.jpg', 'b.jpg']);
        $this->setImage($carId, ['b.jpg', 'a.jpg']);
        $row = $this->onlyRow($carId);
        $this->assertSame(['c.jpg'], $this->detachedNames($row), 'Test setup: c.jpg is detached');
        $this->assertSame(['a.jpg', 'b.jpg'], $row->earlierOrder, 'Test setup: a restore is offered');

        $this->assertSame(CarPhotoRecoveryResult::Applied, $this->recovery->apply($carId, ['c.jpg'], false, $this->userId, $this->seen($carId)));

        // Current [b, a, c]: the reverse [c, a, b] is a history list, and no restore wrote this list.
        $this->assertSame(['c.jpg', 'a.jpg', 'b.jpg'], $this->onlyRow($carId)->earlierOrder);
    }

    // ---- sort order --------------------------------------------------------

    public function testFindRowsPutsRowsWithAnEarlierOrderFirstThenTheRestEachInCarIdOrder(): void
    {
        $detachedLow = $this->carWithOneDetached();
        $reversedMid = $this->carWithReversedOrder();
        $detachedHigh = $this->carWithOneDetached();
        $reversedHigh = $this->carWithReversedOrder();
        $this->assertLessThan($reversedMid, $detachedLow, 'Test setup: IDs ascend');

        $rows = $this->recovery->findRows([$reversedHigh, $detachedHigh, $reversedMid, $detachedLow]);

        $this->assertSame(
            [$reversedMid, $reversedHigh, $detachedLow, $detachedHigh],
            array_map(static fn(CarPhotoRecoveryRow $row): int => $row->carId, $rows)
        );
    }

    // ---- ignore -------------------------------------------------------------

    public function testIgnoreWritesOneLogRowAndChangesNeitherCarsImageNorCarsHist(): void
    {
        $carId = $this->carWithOneDetached();
        $imageBefore = $this->currentImage($carId);
        $histBefore = count($this->history($carId));
        $token = $this->seen($carId);

        $result = $this->recovery->ignore($carId, $this->userId, $token);

        $this->assertSame(CarPhotoRecoveryResult::Applied, $result);
        $this->assertSame($imageBefore, $this->currentImage($carId));
        $this->assertCount($histBefore, $this->history($carId));
        $metadata = $this->decisionMetadata($carId);
        $this->assertCount(1, $metadata);
        $this->assertSame('car_photos_ignore', $metadata[0]['action']);
        $this->assertSame($carId, $metadata[0]['car_id']);
        $this->assertSame($token, $metadata[0]['token']);
        $this->assertSame(['a.jpg'], $metadata[0]['detached']);
        $this->assertSame(1, $this->countMatchingLogs('CarActions', "Car Photos: car {$carId} ignored with 1 detached photo(s)", $this->userId));

        // A new instance has no cache and reads the log.
        $fresh = new CarPhotoRecovery($this->db, $this->root);
        $row = $this->onlyRow($carId);
        $this->assertTrue($fresh->isIgnored($row));
        $by = $fresh->ignoredBy($row);
        $this->assertNotNull($by);
        $this->assertSame($this->userId, $by['userId']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $by['at']);
    }

    public function testIgnoreAgainOfAnIgnoredRowIsNothingToDoAndWritesNoSecondLogRow(): void
    {
        $carId = $this->carWithOneDetached();
        $this->recovery->ignore($carId, $this->userId, $this->seen($carId));

        $this->assertSame(CarPhotoRecoveryResult::NothingToDo, $this->recovery->ignore($carId, $this->userId, $this->seen($carId)));
        $this->assertCount(1, $this->decisionMetadata($carId));
    }

    public function testIgnoreWithStaleTokenReturnsChangedAndWritesNoLogRow(): void
    {
        $carId = $this->carWithOneDetached();
        $staleToken = $this->seen($carId);
        $this->photo($carId, 'c.jpg', 3);
        $this->setImage($carId, ['c.jpg']);

        $this->assertSame(CarPhotoRecoveryResult::Changed, $this->recovery->ignore($carId, $this->userId, $staleToken));
        $this->assertSame([], $this->decisionMetadata($carId));
        $this->assertFalse($this->recovery->isIgnored($this->onlyRow($carId)));
    }

    public function testIgnoreOfOneCarDoesNotHideAnotherCar(): void
    {
        $carA = $this->carWithOneDetached();
        $carB = $this->carWithOneDetached();

        $this->recovery->ignore($carA, $this->userId, $this->seen($carA));

        $fresh = new CarPhotoRecovery($this->db, $this->root);
        $this->assertTrue($fresh->isIgnored($this->onlyRow($carA)));
        $this->assertFalse($fresh->isIgnored($this->onlyRow($carB)), 'A decision belongs to its car');
    }

    public function testIgnoredRowShowsAgainWhenTheStoredValueChangesAlone(): void
    {
        $carId = $this->carWithOneDetached();
        $this->recovery->ignore($carId, $this->userId, $this->seen($carId));
        $this->assertTrue($this->recovery->isIgnored($this->onlyRow($carId)), 'Test setup');

        // The same photo and the same detached name, with other stored text: only the token differs.
        $this->db->query('UPDATE cars SET image = ? WHERE id = ?', ['[ "b.jpg" ]', $carId]);

        $row = $this->onlyRow($carId);
        $this->assertSame(['a.jpg'], $this->detachedNames($row));
        $this->assertFalse((new CarPhotoRecovery($this->db, $this->root))->isIgnored($row));
    }

    public function testIgnoredRowShowsAgainWhenTheDetachedPhotosChange(): void
    {
        $carId = $this->carWithOneDetached();
        $this->recovery->ignore($carId, $this->userId, $this->seen($carId));
        $this->photo($carId, 'c.jpg', 3);
        $this->setImage($carId, ['c.jpg']);

        $row = $this->onlyRow($carId);
        $this->assertSame(['a.jpg', 'b.jpg'], $this->detachedNames($row));
        $this->assertFalse((new CarPhotoRecovery($this->db, $this->root))->isIgnored($row));
    }

    // ---- undo ignore ---------------------------------------------------------

    public function testUndoIgnoreWritesANewerLogRowAndNewestDecisionWins(): void
    {
        $carId = $this->carWithOneDetached();
        $token = $this->seen($carId);
        $this->recovery->ignore($carId, $this->userId, $token);

        $this->assertSame(CarPhotoRecoveryResult::Applied, $this->recovery->undoIgnore($carId, $this->userId, $token));
        $this->assertSame(['car_photos_ignore', 'car_photos_unignore'], array_column($this->decisionMetadata($carId), 'action'));
        $this->assertFalse((new CarPhotoRecovery($this->db, $this->root))->isIgnored($this->onlyRow($carId)), 'The newest decision is the undo');
        $this->assertSame('["b.jpg"]', $this->currentImage($carId));

        $this->assertSame(CarPhotoRecoveryResult::Applied, $this->recovery->ignore($carId, $this->userId, $token));
        $this->assertTrue((new CarPhotoRecovery($this->db, $this->root))->isIgnored($this->onlyRow($carId)), 'A third decision wins over the undo');
    }

    public function testUndoIgnoreOfARowThatIsNotIgnoredIsNothingToDoWithNoLogRow(): void
    {
        $carId = $this->carWithOneDetached();

        $this->assertSame(CarPhotoRecoveryResult::NothingToDo, $this->recovery->undoIgnore($carId, $this->userId, $this->seen($carId)));
        $this->assertSame([], $this->decisionMetadata($carId));
    }

    public function testUndoIgnoreWithStaleTokenReturnsChangedAndWritesNoLogRow(): void
    {
        $carId = $this->carWithOneDetached();
        $token = $this->seen($carId);
        $this->recovery->ignore($carId, $this->userId, $token);

        $this->assertSame(CarPhotoRecoveryResult::Changed, $this->recovery->undoIgnore($carId, $this->userId, str_repeat('0', 64)));
        $this->assertCount(1, $this->decisionMetadata($carId));
        $this->assertTrue((new CarPhotoRecovery($this->db, $this->root))->isIgnored($this->onlyRow($carId)));
    }

    // ---- unreadable image root -------------------------------------------------

    /** @return array<string, array{string}> */
    public static function rootCallProvider(): array
    {
        return ['findRows' => ['findRows'], 'apply' => ['apply'], 'ignore' => ['ignore'], 'undoIgnore' => ['undoIgnore']];
    }

    #[DataProvider('rootCallProvider')]
    public function testUnreadableImageRootThrowsImageProcessingExceptionAndWritesNothing(string $call): void
    {
        $carId = $this->carWithOneDetached();
        $token = $this->seen($carId);
        $broken = new CarPhotoRecovery($this->db, $this->root . 'missing-directory');

        try {
            match ($call) {
                'findRows'   => $broken->findRows([$carId]),
                'apply'      => $broken->apply($carId, ['a.jpg'], false, $this->userId, $token),
                'ignore'     => $broken->ignore($carId, $this->userId, $token),
                'undoIgnore' => $broken->undoIgnore($carId, $this->userId, $token),
                default      => throw new InvalidArgumentException("Unknown call {$call}"),
            };
            $this->fail("{$call}() did not throw");
        } catch (ElanRegistry\Exceptions\ImageProcessingException $e) {
            $this->assertStringContainsString('is not a readable directory', $e->getMessage());
        }
        $this->assertSame('["b.jpg"]', $this->currentImage($carId));
        $this->assertSame([], $this->decisionMetadata($carId));
    }

    public function testIgnoredRowShowsAgainWhenANewDetachedFileAppearsAndTheTokenStaysTheSame(): void
    {
        $carId = $this->newCar();
        $this->photo($carId, 'a.jpg', 1);
        $this->photo($carId, 'b.jpg', 2);
        // The history holds c.jpg, but the file is not on disk yet.
        $this->setImage($carId, ['a.jpg', 'c.jpg']);
        $this->setImage($carId, ['b.jpg']);
        $this->assertSame(['a.jpg'], $this->detachedNames($this->onlyRow($carId)), 'Test setup: only a.jpg is detached');
        $this->recovery->ignore($carId, $this->userId, $this->seen($carId));
        $this->assertTrue((new CarPhotoRecovery($this->db, $this->root))->isIgnored($this->onlyRow($carId)), 'Test setup: ignored');

        $this->photo($carId, 'c.jpg', 3);

        $row = $this->onlyRow($carId);
        $this->assertSame(['a.jpg', 'c.jpg'], $this->detachedNames($row));
        $this->assertSame('["b.jpg"]', $this->currentImage($carId), 'The token did not change');
        $this->assertFalse((new CarPhotoRecovery($this->db, $this->root))->isIgnored($row), 'A new detached file shows the row');
    }
}
