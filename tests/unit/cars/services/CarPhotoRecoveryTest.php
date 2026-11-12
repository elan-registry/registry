<?php

declare(strict_types=1);

use ElanRegistry\Car\CarPhotoRecovery;
use ElanRegistry\Car\CarPhotoRecoveryResult;
use ElanRegistry\Car\CarPhotoRecoveryRow;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\DetachedHow;
use ElanRegistry\Car\DetachedPhoto;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ImageProcessingException;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDatabase;
use Tests\Support\TestJpeg;

/**
 * Unit tests for CarPhotoRecovery (#2644).
 *
 * The database is a FakeDatabase with canned `cars` and `cars_hist` rows. The
 * disk is a real temp directory with real JPEGs, because the class reads
 * exif_imagetype() and file hashes. apply() runs on the real CarRepository.
 *
 * History rows follow the triggers: an update row holds the OLD list, with the
 * time of the save that changed it.
 */
#[Group('fast')]
#[Group('unit')]
#[Group('cars')]
final class CarPhotoRecoveryTest extends TestCase
{
    private const CAR = 7;

    private const OTHER_CAR = 8;

    private string $root;

    /** @var list<string> Paths that a test made unreadable. tearDown() restores them. */
    private array $locked = [];

    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];
        $this->root = sys_get_temp_dir() . '/elan_photo_recovery_' . bin2hex(random_bytes(8));
        mkdir($this->root . '/' . self::CAR, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->locked as $path) {
            chmod($path, 0755);
        }
        $this->removeDirectory($this->root);
    }

    // ------------------------------------------------------------------
    // findRows(): what is a detached photo
    // ------------------------------------------------------------------

    public function testFindRowsPhotoInHistoryNotInCurrentReturnsDetachedPhoto(): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $db = $this->db('["a.jpg"]', [[10, '["a.jpg","b.jpg"]', '2026-01-01 10:00:00']]);

        $rows = $this->recovery($db)->findRows();

        $this->assertCount(1, $rows);
        $this->assertSame(self::CAR, $rows[0]->carId);
        $this->assertSame(['a.jpg'], $rows[0]->currentNames);
        $this->assertEquals(
            [new DetachedPhoto('b.jpg', true, DetachedHow::PartlyRemoved, '2026-01-01 10:00:00')],
            $rows[0]->detached
        );
        $this->assertNull($rows[0]->earlierOrder);
    }

    public function testFindRowsFileInNoHistoryListExcludesOrphan(): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('orphan.jpg', 2);
        $db = $this->db('["a.jpg"]', [[10, '["a.jpg"]', '2026-01-01 10:00:00']]);

        $this->assertSame([], $this->recovery($db)->findRows());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function resizedNameProvider(): array
    {
        return [
            '100 jpg'   => ['b-resized-100.jpg'],
            '2048 jpeg' => ['b-resized-2048.jpeg'],
            '300 png'   => ['b-resized-300.png'],
        ];
    }

    #[DataProvider('resizedNameProvider')]
    public function testFindRowsResizedCopyInHistoryIsExcluded(string $name): void
    {
        $this->photo($name, 2);
        $db = $this->db('[]', [[10, json_encode([$name]), '2026-01-01 10:00:00']]);

        $this->assertSame([], $this->recovery($db)->findRows());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nonImageProvider(): array
    {
        return [
            'text file'             => ['notes.txt', 'plain text'],
            'jpg that is not image' => ['fake.jpg', 'this is not a JPEG at all'],
            'empty jpg'             => ['empty.jpg', ''],
        ];
    }

    #[DataProvider('nonImageProvider')]
    public function testFindRowsNonImageFileInHistoryIsExcluded(string $name, string $content): void
    {
        file_put_contents($this->root . '/' . self::CAR . '/' . $name, $content);
        $db = $this->db('[]', [[10, json_encode([$name]), '2026-01-01 10:00:00']]);

        $this->assertSame([], $this->recovery($db)->findRows());
    }

    public function testFindRowsCandidateWithSameBytesAsAttachedPhotoIsExcluded(): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('copy-of-a.jpg', 1);
        $db = $this->db('["a.jpg"]', [[10, '["copy-of-a.jpg"]', '2026-01-01 10:00:00']]);

        $this->assertSame([], $this->recovery($db)->findRows());
    }

    /**
     * @return array<string, array{list<array{int, string, string}>, string}>
     */
    public static function duplicateCandidatesProvider(): array
    {
        return [
            'safe name wins over a later unsafe name' => [
                [[10, '["safe.jpg"]', '2026-01-01 10:00:00'], [11, '["un safe.jpg"]', '2026-01-02 10:00:00']],
                'safe.jpg',
            ],
            'later history appearance wins between safe names' => [
                [[10, '["early.jpg"]', '2026-01-01 10:00:00'], [11, '["late.jpg"]', '2026-01-02 10:00:00']],
                'late.jpg',
            ],
        ];
    }

    /**
     * @param list<array{int, string, string}> $history
     */
    #[DataProvider('duplicateCandidatesProvider')]
    public function testFindRowsCandidatesWithSameBytesCollapseToOne(array $history, string $expected): void
    {
        foreach ($history as [, $image]) {
            foreach (json_decode($image, true) as $name) {
                $this->photo($name, 5);
            }
        }
        $db = $this->db('[]', $history);

        $rows = $this->recovery($db)->findRows();

        $this->assertCount(1, $rows);
        $this->assertSame([$expected], array_column($rows[0]->detached, 'name'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeNameProvider(): array
    {
        return [
            'space'        => ['my photo.jpg'],
            'ampersand'    => ['a&b.jpg'],
            'quote'        => ["o'neil.jpg"],
            'non-ascii'    => ["caf\u{e9}.jpg"],
        ];
    }

    #[DataProvider('unsafeNameProvider')]
    public function testFindRowsUnsafeNameIsShownButNotAttachable(string $name): void
    {
        $this->photo($name, 2);
        $db = $this->db('[]', [[10, json_encode([$name]), '2026-01-01 10:00:00']]);

        $rows = $this->recovery($db)->findRows();

        $this->assertCount(1, $rows);
        $this->assertSame($name, $rows[0]->detached[0]->name);
        $this->assertFalse($rows[0]->detached[0]->safe);
        $this->assertSame([], $rows[0]->attachableNames());
    }

    /**
     * @return array<string, array{string|null, string, string}>
     */
    public static function storedFormatProvider(): array
    {
        return [
            'JSON current, JSON history'        => ['["a.jpg"]', '["a.jpg","b.jpg"]', 'partly removed'],
            'comma current, comma history'      => ['a.jpg', 'a.jpg,b.jpg', 'partly removed'],
            'NULL current'                      => [null, '["b.jpg"]', 'removed'],
            'empty-string current'              => ['', '["b.jpg"]', 'removed'],
        ];
    }

    #[DataProvider('storedFormatProvider')]
    public function testFindRowsStoredFormatsDecodeToTheSameNames(?string $current, string $history, string $howLeft): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $db = $this->db($current, [[10, $history, '2026-01-01 10:00:00']]);

        $rows = $this->recovery($db)->findRows();

        $this->assertCount(1, $rows);
        $this->assertSame(['b.jpg'], array_column($rows[0]->detached, 'name'));
        $this->assertSame($howLeft, $rows[0]->detached[0]->howLeft->value);
        $this->assertSame($current, $rows[0]->rawImage);
    }

    // ------------------------------------------------------------------
    // findRows(): how the photo left, and when
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string, list<array{int, string, string}>, string, string}>
     */
    public static function howLeftProvider(): array
    {
        return [
            'replaced by the current list' => [
                '["new.jpg"]',
                [[10, '["old.jpg"]', '2026-01-01 10:00:00']],
                'replaced',
                '2026-01-01 10:00:00',
            ],
            'removed, current list empty' => [
                '[]',
                [[10, '["old.jpg"]', '2026-01-01 10:00:00']],
                'removed',
                '2026-01-01 10:00:00',
            ],
            'partly removed, current list keeps one' => [
                '["new.jpg"]',
                [[10, '["new.jpg","old.jpg"]', '2026-01-01 10:00:00']],
                'partly removed',
                '2026-01-01 10:00:00',
            ],
            'dropped in the middle, next history row is empty' => [
                '["new.jpg"]',
                [
                    [10, '["old.jpg"]', '2026-01-01 10:00:00'],
                    [11, '["old.jpg"]', '2026-01-02 10:00:00'],
                    [12, '[]', '2026-01-03 10:00:00'],
                    [13, '["new.jpg"]', '2026-01-04 10:00:00'],
                ],
                'removed',
                '2026-01-02 10:00:00',
            ],
            'dropped in the middle, next history row replaced it' => [
                '["new.jpg","other.jpg"]',
                [
                    [10, '["old.jpg"]', '2026-01-01 10:00:00'],
                    [11, '["new.jpg"]', '2026-01-02 10:00:00'],
                    [12, '["new.jpg"]', '2026-01-03 10:00:00'],
                ],
                'replaced',
                '2026-01-01 10:00:00',
            ],
        ];
    }

    /**
     * @param list<array{int, string, string}> $history
     */
    #[DataProvider('howLeftProvider')]
    public function testFindRowsClassifiesTheDroppingSaveWithItsTime(
        string $current,
        array $history,
        string $howLeft,
        string $leftAt
    ): void {
        $this->photo('old.jpg', 1);
        $this->photo('new.jpg', 2);
        $this->photo('other.jpg', 3);
        $db = $this->db($current, $history);

        $rows = $this->recovery($db)->findRows();

        $this->assertCount(1, $rows);
        $this->assertEquals(
            [new DetachedPhoto('old.jpg', true, DetachedHow::from($howLeft), $leftAt)],
            $rows[0]->detached
        );
    }

    /**
     * Pins the trigger semantics: rows are read in id order (not timestamp
     * order), a NULL image row is not a state, and the drop time is the time
     * of the last row (by id) whose list holds the photo.
     */
    public function testFindRowsUsesIdOrderSkipsNullRowsAndTakesTimeOfLastRowWithPhoto(): void
    {
        $this->photo('old.jpg', 1);
        $this->photo('new.jpg', 2);
        $db = $this->db('["new.jpg"]', [
            // Data refresh: DELETE (OLD) + INSERT (NEW) in the same second.
            [20, '["old.jpg"]', '2026-06-22 14:21:41'],
            [21, '["old.jpg"]', '2026-06-22 14:21:41'],
            // A DELETE row with a NULL image: not a state.
            [22, null, '2026-06-22 14:21:42'],
            // Higher id, earlier timestamp: the OLD list of the dropping save.
            [23, '["old.jpg"]', '2026-06-22 14:21:40'],
            [24, '["new.jpg"]', '2026-06-23 09:00:00'],
        ]);

        $rows = $this->recovery($db)->findRows();

        $this->assertCount(1, $rows);
        $this->assertSame('2026-06-22 14:21:40', $rows[0]->detached[0]->leftAt);
        $this->assertSame('replaced', $rows[0]->detached[0]->howLeft->value);
    }

    public function testFindRowsCarWithNoDirectoryReturnsNoDetachedPhotos(): void
    {
        rmdir($this->root . '/' . self::CAR);
        $db = $this->db('["a.jpg"]', [[10, '["a.jpg","b.jpg"]', '2026-01-01 10:00:00']]);

        $this->assertSame([], $this->recovery($db)->findRows());
    }

    // ------------------------------------------------------------------
    // findRows(): restore order
    // ------------------------------------------------------------------

    public function testFindRowsExactReverseInHistoryOffersRestoreWithLatestTime(): void
    {
        $db = $this->db('["c.jpg","b.jpg","a.jpg"]', [
            [10, '["a.jpg","b.jpg","c.jpg"]', '2026-01-01 10:00:00'],
            [11, '["c.jpg","b.jpg","a.jpg"]', '2026-01-02 10:00:00'],
            [12, '["a.jpg","b.jpg","c.jpg"]', '2026-01-03 10:00:00'],
        ]);

        $rows = $this->recovery($db)->findRows();

        $this->assertCount(1, $rows);
        $this->assertSame(['a.jpg', 'b.jpg', 'c.jpg'], $rows[0]->earlierOrder);
        $this->assertSame('2026-01-03 10:00:00', $rows[0]->earlierOrderAt);
        $this->assertSame([], $rows[0]->detached);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function noRestoreProvider(): array
    {
        return [
            'one photo'       => ['["a.jpg"]', '["a.jpg"]'],
            'partial reverse' => ['["c.jpg","b.jpg","a.jpg"]', '["b.jpg","c.jpg","a.jpg"]'],
            'same order'      => ['["a.jpg","b.jpg"]', '["a.jpg","b.jpg"]'],
            'palindrome'      => ['["a.jpg","b.jpg","a.jpg"]', '["a.jpg","b.jpg","a.jpg"]'],
        ];
    }

    #[DataProvider('noRestoreProvider')]
    public function testFindRowsNoExactReverseOffersNoRestore(string $current, string $history): void
    {
        $db = $this->db($current, [[10, $history, '2026-01-01 10:00:00']]);

        $this->assertSame([], $this->recovery($db)->findRows());
    }

    // ------------------------------------------------------------------
    // findRows(): input and row shape
    // ------------------------------------------------------------------

    public function testFindRowsCarIdsFilterBindsTheIds(): void
    {
        $db = $this->db('[]', []);

        $this->recovery($db)->findRows([self::CAR, 9]);

        // The `logs` read is not by car: the car ID is in the JSON metadata.
        $carQueries = array_values(array_filter($db->queries, static fn(array $query): bool => !str_contains($query[0], 'FROM logs')));
        $this->assertCount(2, $carQueries);
        foreach ($carQueries as [$sql, $params]) {
            $this->assertStringContainsString('IN (?,?)', $sql);
            $this->assertSame([self::CAR, 9], $params);
        }
    }

    public function testFindRowsEmptyCarIdsReturnsNoRowsWithoutQuery(): void
    {
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);

        $this->assertSame([], $this->recovery($db)->findRows([]));
        $this->assertSame([], $db->queries);
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function badCarIdsProvider(): array
    {
        return [
            'numeric string' => [['7']],
            'float'          => [[7.0]],
            'zero'           => [[0]],
            'negative'       => [[-3]],
            'null'           => [[null]],
            'array'          => [[[7]]],
            'mixed'          => [[7, 'x']],
        ];
    }

    /**
     * @param array<mixed> $carIds
     */
    #[DataProvider('badCarIdsProvider')]
    public function testFindRowsCarIdsWithNonPositiveIntThrowsValidationException(array $carIds): void
    {
        $db = $this->db('[]', []);

        $this->expectException(CarValidationException::class);
        $this->recovery($db)->findRows($carIds);
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function badImageValueProvider(): array
    {
        return [
            'int in cars'          => [42, true],
            'array in cars'        => [['a.jpg'], true],
            'int in cars_hist'     => [42, false],
            'array in cars_hist'   => [['a.jpg'], false],
        ];
    }

    #[DataProvider('badImageValueProvider')]
    public function testFindRowsImageValueNotStringThrowsDatabaseException(mixed $value, bool $inCars): void
    {
        $db = $this->db('[]', [[10, '[]', '2026-01-01 10:00:00']]);
        if ($inCars) {
            $db->carRows[0]['image'] = $value;
        } else {
            $db->histRows[0]['image'] = $value;
        }

        $this->expectException(CarDatabaseException::class);
        $this->recovery($db)->findRows();
    }

    public function testFindRowsQueryErrorThrowsDatabaseException(): void
    {
        $db = $this->db('[]', []);
        $db->failQueries = true;

        $this->expectException(CarDatabaseException::class);
        $this->recovery($db)->findRows();
    }

    // ------------------------------------------------------------------
    // findRows() and apply(): files the server cannot read
    // ------------------------------------------------------------------

    public function testFindRowsUnreadableFileGivesRowWithNoActionsAndOtherCarsKeepTheirRows(): void
    {
        $this->skipWhenRoot();
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $this->photo('c.jpg', 3);
        $this->lock($this->root . '/' . self::CAR . '/c.jpg');
        $this->otherCarPhoto('d.jpg', 4);
        $db = $this->twoCarDb();

        $rows = $this->recovery($db)->findRows();

        $this->assertCount(2, $rows);
        $this->assertSame(self::CAR, $rows[0]->carId);
        $this->assertSame(['c.jpg'], $rows[0]->unreadable);
        $this->assertSame(['b.jpg', 'a.jpg'], $rows[0]->currentNames);
        $this->assertSame([], $rows[0]->detached);
        $this->assertNull($rows[0]->earlierOrder);
        $this->assertSame([], $rows[0]->attachableNames());
        $this->assertSame(self::OTHER_CAR, $rows[1]->carId);
        $this->assertSame([], $rows[1]->unreadable);
        $this->assertSame(['d.jpg'], $rows[1]->attachableNames());
        $this->assertSame([], $this->logEntries());
    }

    public function testFindRowsUnreadableFolderGivesRowWithNoActionsAndOtherCarsKeepTheirRows(): void
    {
        $this->skipWhenRoot();
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $this->photo('c.jpg', 3);
        $this->lock($this->root . '/' . self::CAR);
        $this->otherCarPhoto('d.jpg', 4);
        $db = $this->twoCarDb();

        $rows = $this->recovery($db)->findRows();

        $this->assertCount(2, $rows);
        $this->assertSame(self::CAR, $rows[0]->carId);
        $this->assertSame([self::CAR . '/'], $rows[0]->unreadable);
        $this->assertSame([], $rows[0]->detached);
        $this->assertNull($rows[0]->earlierOrder);
        $this->assertSame(self::OTHER_CAR, $rows[1]->carId);
        $this->assertSame(['d.jpg'], $rows[1]->attachableNames());
        $this->assertSame([], $this->logEntries());
    }

    public function testApplyUnreadableFileReturnsUnreadableAndWritesNothing(): void
    {
        $this->skipWhenRoot();
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $this->photo('c.jpg', 3);
        $this->lock($this->root . '/' . self::CAR . '/c.jpg');
        $db = $this->db('["b.jpg","a.jpg"]', [
            [10, '["a.jpg","b.jpg","c.jpg"]', '2026-01-01 10:00:00'],
            [11, '["a.jpg","b.jpg"]', '2026-01-02 10:00:00'],
        ]);

        $result = $this->recovery($db)->apply(self::CAR, ['c.jpg'], true, 3, $this->seen($db));

        $this->assertSame(CarPhotoRecoveryResult::Unreadable, $result);
        $this->assertNull($db->updateParams());
        $this->assertSame([], $this->logEntries());
    }

    public function testFindRowsUnreadableAttachedPhotoWithCandidateGivesUnreadableRowAndApplyWritesNothing(): void
    {
        $this->skipWhenRoot();
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $this->lock($this->root . '/' . self::CAR . '/a.jpg');
        $db = $this->db('["a.jpg"]', [[10, '["a.jpg","b.jpg"]', '2026-01-01 10:00:00']]);

        $rows = $this->recovery($db)->findRows();

        $this->assertCount(1, $rows);
        $this->assertSame(['a.jpg'], $rows[0]->unreadable);
        $this->assertSame([], $rows[0]->detached);
        $this->assertNull($rows[0]->earlierOrder);
        $this->assertSame([], $rows[0]->attachableNames());

        $result = $this->recovery($db)->apply(self::CAR, ['b.jpg'], true, 3, $this->seen($db));

        $this->assertSame(CarPhotoRecoveryResult::Unreadable, $result);
        $this->assertNull($db->updateParams());
        $this->assertSame([], $this->logEntries());
    }

    public function testFindRowsUnreadableOrphanDoesNotBlockItsCar(): void
    {
        $this->skipWhenRoot();
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $this->photo('orphan.jpg', 3);
        $this->lock($this->root . '/' . self::CAR . '/orphan.jpg');
        $db = $this->db('["a.jpg"]', [[10, '["a.jpg","b.jpg"]', '2026-01-01 10:00:00']]);

        $rows = $this->recovery($db)->findRows();

        $this->assertCount(1, $rows);
        $this->assertSame([], $rows[0]->unreadable);
        $this->assertSame(['b.jpg'], $rows[0]->attachableNames());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badImageRootProvider(): array
    {
        return [
            'missing directory' => ['missing'],
            'regular file'      => ['file'],
        ];
    }

    #[DataProvider('badImageRootProvider')]
    public function testFindRowsImageRootNotReadableDirectoryThrowsImageProcessingException(string $kind): void
    {
        $badRoot = $this->root . '/not-a-root';
        if ($kind === 'file') {
            file_put_contents($badRoot, 'x');
        }
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);

        $this->expectException(ImageProcessingException::class);
        (new CarPhotoRecovery($db, $badRoot, new CarRepository($db)))->findRows();
    }

    // ------------------------------------------------------------------
    // versionToken()
    // ------------------------------------------------------------------

    public function testVersionTokenTellsNullFromEmptyString(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', CarPhotoRecovery::versionToken(null));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', CarPhotoRecovery::versionToken(''));
        $this->assertNotSame(CarPhotoRecovery::versionToken(null), CarPhotoRecovery::versionToken(''));
        $this->assertNotSame(CarPhotoRecovery::versionToken('["a.jpg"]'), CarPhotoRecovery::versionToken('["b.jpg"]'));
        $this->assertSame(CarPhotoRecovery::versionToken('["a.jpg"]'), CarPhotoRecovery::versionToken('["a.jpg"]'));
    }

    // ------------------------------------------------------------------
    // apply()
    // ------------------------------------------------------------------

    public function testApplySeenTokenMismatchReturnsChangedAndWritesNothing(): void
    {
        $this->photo('b.jpg', 2);
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);

        $result = $this->recovery($db)->apply(self::CAR, ['b.jpg'], false, 3, CarPhotoRecovery::versionToken('["x.jpg"]'));

        $this->assertSame(CarPhotoRecoveryResult::Changed, $result);
        $this->assertNull($db->updateParams());
        $this->assertSame([], $this->logEntries());
    }

    public function testApplyReattachKeepsTheOrderOfTwoOrMoreExistingPhotos(): void
    {
        $this->photo('c.jpg', 3);
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $db = $this->db('["c.jpg","a.jpg"]', [[10, '["c.jpg","a.jpg","b.jpg"]', '2026-01-01 10:00:00']]);

        $result = $this->recovery($db)->apply(self::CAR, ['b.jpg'], false, 3, $this->seen($db));

        $this->assertSame(CarPhotoRecoveryResult::Applied, $result);
        $this->assertSame(['["c.jpg","a.jpg","b.jpg"]', self::CAR, '["c.jpg","a.jpg"]'], $db->updateParams());
    }

    public function testApplyReattachAppendsSafeDetachedPhotosInDropOrder(): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $this->photo('c.jpg', 3);
        $this->photo('bad name.jpg', 4);
        $db = $this->db('["a.jpg"]', [
            [10, '["a.jpg","c.jpg","bad name.jpg"]', '2026-01-01 10:00:00'],
            [11, '["a.jpg","b.jpg"]', '2026-01-02 10:00:00'],
        ]);

        $result = $this->recovery($db)->apply(self::CAR, ['b.jpg', 'c.jpg'], false, 3, $this->seen($db));

        $this->assertSame(CarPhotoRecoveryResult::Applied, $result);
        $this->assertSame(['["a.jpg","c.jpg","b.jpg"]', self::CAR, '["a.jpg"]'], $db->updateParams());
    }

    public function testApplyRestoreThenReattachWritesReversedOrderThenDetached(): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $this->photo('d.jpg', 3);
        $db = $this->db('["b.jpg","a.jpg"]', [
            [10, '["a.jpg","b.jpg","d.jpg"]', '2026-01-01 10:00:00'],
            [11, '["a.jpg","b.jpg"]', '2026-01-02 10:00:00'],
        ]);

        $result = $this->recovery($db)->apply(self::CAR, ['d.jpg'], true, 3, $this->seen($db));

        $this->assertSame(CarPhotoRecoveryResult::Applied, $result);
        $this->assertSame(['["a.jpg","b.jpg","d.jpg"]', self::CAR, '["b.jpg","a.jpg"]'], $db->updateParams());
    }

    public function testApplyRestoreOnlyLeavesDetachedPhotosOut(): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $this->photo('d.jpg', 3);
        $db = $this->db('["b.jpg","a.jpg"]', [
            [10, '["a.jpg","b.jpg","d.jpg"]', '2026-01-01 10:00:00'],
            [11, '["a.jpg","b.jpg"]', '2026-01-02 10:00:00'],
        ]);

        $this->recovery($db)->apply(self::CAR, [], true, 3, $this->seen($db));

        $this->assertSame(['["a.jpg","b.jpg"]', self::CAR, '["b.jpg","a.jpg"]'], $db->updateParams());
    }

    public function testApplyOnlyUnsafeDetachedPhotoReturnsNothingToDoAndWritesNothing(): void
    {
        $this->photo('bad name.jpg', 4);
        $db = $this->db('[]', [[10, '["bad name.jpg"]', '2026-01-01 10:00:00']]);

        $result = $this->recovery($db)->apply(self::CAR, ['bad name.jpg'], true, 3, $this->seen($db));

        $this->assertSame(CarPhotoRecoveryResult::NothingToDo, $result);
        $this->assertNull($db->updateParams());
        $this->assertSame([], $this->logEntries());
    }

    /**
     * @return array<string, array{list<string>, bool}>
     */
    public static function noFlagProvider(): array
    {
        return [
            'no photo ticked, no restore'        => [[], false],
            'restore with no reverse in history' => [[], true],
        ];
    }

    /**
     * @param list<string> $reattachNames
     */
    #[DataProvider('noFlagProvider')]
    public function testApplyNoEffectiveActionReturnsNothingToDoAndWritesNothing(array $reattachNames, bool $restore): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $db = $this->db('["a.jpg"]', [[10, '["a.jpg","b.jpg"]', '2026-01-01 10:00:00']]);

        $result = $this->recovery($db)->apply(self::CAR, $reattachNames, $restore, 3, $this->seen($db));

        $this->assertSame(CarPhotoRecoveryResult::NothingToDo, $result);
        $this->assertNull($db->updateParams());
        $this->assertSame([], $this->logEntries());
    }

    public function testApplyCarWithNothingToShowReturnsNothingToDo(): void
    {
        $db = $this->db('["a.jpg"]', [[10, '["a.jpg"]', '2026-01-01 10:00:00']]);

        $this->assertSame(CarPhotoRecoveryResult::NothingToDo, $this->recovery($db)->apply(self::CAR, ['a.jpg'], true, 3, $this->seen($db)));
        $this->assertNull($db->updateParams());
    }

    public function testApplyCompareAndSetMissReturnsChangedAndLogsNothing(): void
    {
        $this->photo('b.jpg', 2);
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);
        $db->updateCount = 0;

        $result = $this->recovery($db)->apply(self::CAR, ['b.jpg'], false, 3, $this->seen($db));

        $this->assertSame(CarPhotoRecoveryResult::Changed, $result);
        $this->assertSame([], $this->logEntries());
    }

    public function testApplyCompareAndSetMissReportsNoIgnoredNames(): void
    {
        $this->photo('b.jpg', 2);
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);
        $db->updateCount = 0;

        $result = $this->recovery($db)->apply(self::CAR, ['b.jpg', 'evil.jpg'], false, 3, $this->seen($db), $ignored);

        $this->assertSame(CarPhotoRecoveryResult::Changed, $result);
        $this->assertSame([], $ignored, 'The names were judged against data that another save replaced');
    }

    public function testApplyMalformedSeenTokenReturnsChangedWithoutAQuery(): void
    {
        $this->photo('b.jpg', 2);
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);

        $result = $this->recovery($db)->apply(self::CAR, ['b.jpg'], false, 3, 'not-a-token', $ignored);

        $this->assertSame(CarPhotoRecoveryResult::Changed, $result);
        $this->assertSame([], $ignored);
        $this->assertSame([], $db->queries, 'A malformed token stops the call before it reads the car');
    }

    public function testApplyWriteLogsOneCarActionsRow(): void
    {
        $this->photo('b.jpg', 2);
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);

        $this->recovery($db)->apply(self::CAR, ['b.jpg'], false, 3, $this->seen($db));

        $entries = $this->logEntries();
        $this->assertCount(1, $entries);
        $this->assertSame(3, $entries[0]['user_id']);
        $this->assertSame(LogCategories::LOG_CATEGORY_CAR_ACTIONS, $entries[0]['category']);
        $this->assertSame('Car Photos: car 7, reattached 1 photo(s), order restored: no', $entries[0]['message']);
        $this->assertSame(
            [
                'action'         => 'car_photos_apply',
                'car_id'         => self::CAR,
                'reattached'     => ['b.jpg'],
                'order_restored' => false,
                'token_after'    => CarPhotoRecovery::versionToken('["b.jpg"]'),
            ],
            $entries[0]['metadata']
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function badApplyCarIdProvider(): array
    {
        return ['zero' => [0], 'negative' => [-1]];
    }

    #[DataProvider('badApplyCarIdProvider')]
    public function testApplyNonPositiveCarIdThrowsValidationException(int $carId): void
    {
        $db = $this->db('[]', []);

        $this->expectException(CarValidationException::class);
        $this->recovery($db)->apply($carId, ['b.jpg'], true, 3, $this->seen($db));
    }

    // ------------------------------------------------------------------
    // apply(): ticked photo names
    // ------------------------------------------------------------------

    public function testApplyTickedSubsetAttachesOnlyTheTickedPhotos(): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $this->photo('c.jpg', 3);
        $db = $this->db('["a.jpg"]', [[10, '["a.jpg","b.jpg","c.jpg"]', '2026-01-01 10:00:00']]);

        $result = $this->recovery($db)->apply(self::CAR, ['c.jpg'], false, 3, $this->seen($db), $ignored);

        $this->assertSame(CarPhotoRecoveryResult::Applied, $result);
        $this->assertSame(['["a.jpg","c.jpg"]', self::CAR, '["a.jpg"]'], $db->updateParams());
        $this->assertSame([], $ignored);
        $this->assertSame(['c.jpg'], $this->logEntries()[0]['metadata']['reattached']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notAttachableNameProvider(): array
    {
        return [
            'forged name'           => ['evil.jpg'],
            'unsafe detached name'  => ['bad name.jpg'],
            'already attached name' => ['a.jpg'],
            'path to another car'   => ['../8/d.jpg'],
        ];
    }

    #[DataProvider('notAttachableNameProvider')]
    public function testApplyNameNotAttachableIsIgnoredAndReported(string $name): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $this->photo('bad name.jpg', 4);
        $db = $this->db('["a.jpg"]', [[10, '["a.jpg","b.jpg","bad name.jpg"]', '2026-01-01 10:00:00']]);

        $result = $this->recovery($db)->apply(self::CAR, [$name, 'b.jpg'], false, 3, $this->seen($db), $ignored);

        $this->assertSame(CarPhotoRecoveryResult::Applied, $result);
        $this->assertSame(['["a.jpg","b.jpg"]', self::CAR, '["a.jpg"]'], $db->updateParams());
        $this->assertSame([$name], $ignored);
    }

    public function testApplyOnlyNotAttachableNamesReturnsNothingToDoAndReportsThem(): void
    {
        $this->photo('b.jpg', 2);
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);

        $result = $this->recovery($db)->apply(self::CAR, ['evil.jpg', 'evil.jpg'], false, 3, $this->seen($db), $ignored);

        $this->assertSame(CarPhotoRecoveryResult::NothingToDo, $result);
        $this->assertNull($db->updateParams());
        $this->assertSame(['evil.jpg'], $ignored);
        $this->assertSame([], $this->logEntries());
    }

    public function testApplyTokenMismatchReportsNoIgnoredNames(): void
    {
        $this->photo('b.jpg', 2);
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);

        $result = $this->recovery($db)->apply(self::CAR, ['evil.jpg'], false, 3, CarPhotoRecovery::versionToken('["x.jpg"]'), $ignored);

        $this->assertSame(CarPhotoRecoveryResult::Changed, $result);
        $this->assertSame([], $ignored);
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function wrongTypedNamesProvider(): array
    {
        return [
            'int entry'   => [[123]],
            'array entry' => [['b.jpg', ['c.jpg']]],
            'null entry'  => [[null]],
        ];
    }

    /**
     * @param array<mixed> $names
     */
    #[DataProvider('wrongTypedNamesProvider')]
    public function testApplyNameThatIsNotStringThrowsValidationExceptionAndWritesNothing(array $names): void
    {
        $this->photo('b.jpg', 2);
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);

        try {
            $this->recovery($db)->apply(self::CAR, $names, false, 3, $this->seen($db));
            $this->fail('apply() accepted a photo name that is not a string');
        } catch (CarValidationException $e) {
            $this->assertStringContainsString('must be a string', $e->getMessage());
        }
        $this->assertNull($db->updateParams());
    }

    // ------------------------------------------------------------------
    // apply(), ignore(), undoIgnore(): the car has no row now (SF3)
    // ------------------------------------------------------------------

    public function testApplyNoRowAndTokenMismatchReturnsChanged(): void
    {
        $db = $this->db('["a.jpg"]', [[10, '["a.jpg"]', '2026-01-01 10:00:00']]);

        $result = $this->recovery($db)->apply(self::CAR, ['b.jpg'], false, 3, CarPhotoRecovery::versionToken('["a.jpg","b.jpg"]'), $ignored);

        $this->assertSame(CarPhotoRecoveryResult::Changed, $result);
        $this->assertSame([], $ignored);
        $this->assertNull($db->updateParams());
    }

    public function testApplyNoRowAndTokenMatchReturnsNothingToDoAndReportsNoIgnoredNames(): void
    {
        $db = $this->db('["a.jpg"]', [[10, '["a.jpg"]', '2026-01-01 10:00:00']]);

        $result = $this->recovery($db)->apply(self::CAR, ['b.jpg'], true, 3, $this->seen($db), $ignored);

        $this->assertSame(CarPhotoRecoveryResult::NothingToDo, $result);
        $this->assertSame([], $ignored, 'No row was checked, so no ticked name is proven false');
        $this->assertNull($db->updateParams());
    }

    public function testApplyCarGoneReturnsChanged(): void
    {
        $db = new CarPhotoRecoveryFakeDatabase([], []);

        $result = $this->recovery($db)->apply(self::CAR, ['b.jpg'], false, 3, CarPhotoRecovery::versionToken('[]'));

        $this->assertSame(CarPhotoRecoveryResult::Changed, $result);
        $this->assertNull($db->updateParams());
    }

    public function testIgnoreNoRowAndTokenMismatchReturnsChangedAndLogsNothing(): void
    {
        $db = $this->db('["a.jpg"]', [[10, '["a.jpg"]', '2026-01-01 10:00:00']]);

        $result = $this->recovery($db)->ignore(self::CAR, 3, CarPhotoRecovery::versionToken('[]'));

        $this->assertSame(CarPhotoRecoveryResult::Changed, $result);
        $this->assertSame([], $this->logEntries());
    }

    // ------------------------------------------------------------------
    // Ignore store
    // ------------------------------------------------------------------

    public function testIgnoreWritesOneDecisionRowAndChangesNoImage(): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $this->photo('bad name.jpg', 4);
        $db = $this->db('["a.jpg"]', [[10, '["a.jpg","bad name.jpg","b.jpg"]', '2026-01-01 10:00:00']]);

        $result = $this->recovery($db)->ignore(self::CAR, 3, $this->seen($db));

        $this->assertSame(CarPhotoRecoveryResult::Applied, $result);
        $this->assertNull($db->updateParams());
        $entries = $this->logEntries();
        $this->assertCount(1, $entries);
        $this->assertSame(3, $entries[0]['user_id']);
        $this->assertSame(LogCategories::LOG_CATEGORY_CAR_ACTIONS, $entries[0]['category']);
        $this->assertSame('Car Photos: car 7 ignored with 2 detached photo(s)', $entries[0]['message']);
        $this->assertSame(
            ['action' => 'car_photos_ignore', 'car_id' => self::CAR, 'token' => $this->seen($db), 'detached' => ['bad name.jpg', 'b.jpg']],
            $entries[0]['metadata']
        );
    }

    public function testIgnoreThenFindRowsReportsTheRowAsIgnoredWithWhoAndWhen(): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $this->photo('c.jpg', 3);
        $db = $this->db('["a.jpg"]', [[10, '["a.jpg","c.jpg","b.jpg"]', '2026-01-01 10:00:00']]);
        $this->recovery($db)->ignore(self::CAR, 3, $this->seen($db));
        $db->logRows = $this->logRowsFromEntries();

        $recovery = $this->recovery($db);
        $row      = $recovery->findRows()[0];

        $this->assertTrue($recovery->isIgnored($row));
        $this->assertSame(['userId' => 3, 'at' => '2026-02-01 09:00:01'], $recovery->ignoredBy($row));
        $this->assertSame(
            'SELECT id, user_id, logdate, metadata FROM logs WHERE logtype = ? AND lognote LIKE ? ORDER BY id',
            (string) preg_replace('/\s+/', ' ', $db->logQuery()[0] ?? '')
        );
        $this->assertSame([LogCategories::LOG_CATEGORY_CAR_ACTIONS, 'Car Photos:%'], $db->logQuery()[1] ?? null);
    }

    public function testIgnoreRowAlreadyIgnoredReturnsNothingToDoAndLogsNothing(): void
    {
        $this->photo('b.jpg', 2);
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);
        $db->logRows = [$this->decisionRow(1, 'car_photos_ignore', $this->seen($db), ['b.jpg'])];

        $result = $this->recovery($db)->ignore(self::CAR, 3, $this->seen($db));

        $this->assertSame(CarPhotoRecoveryResult::NothingToDo, $result);
        $this->assertSame([], $this->logEntries());
    }

    public function testIsIgnoredTokenChangedReturnsFalse(): void
    {
        $this->photo('b.jpg', 2);
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);
        $db->logRows = [$this->decisionRow(1, 'car_photos_ignore', CarPhotoRecovery::versionToken('["x.jpg"]'), ['b.jpg'])];

        $recovery = $this->recovery($db);

        $this->assertFalse($recovery->isIgnored($recovery->findRows()[0]));
    }

    public function testIsIgnoredNewDetachedFileReturnsFalse(): void
    {
        $this->photo('b.jpg', 2);
        $this->photo('c.jpg', 3);
        $db = $this->db('[]', [[10, '["b.jpg","c.jpg"]', '2026-01-01 10:00:00']]);
        $db->logRows = [$this->decisionRow(1, 'car_photos_ignore', $this->seen($db), ['b.jpg'])];

        $recovery = $this->recovery($db);

        $this->assertFalse($recovery->isIgnored($recovery->findRows()[0]));
    }

    public function testIsIgnoredSameNamesInOtherOrderReturnsTrue(): void
    {
        $this->photo('b.jpg', 2);
        $this->photo('c.jpg', 3);
        $db = $this->db('[]', [[10, '["b.jpg","c.jpg"]', '2026-01-01 10:00:00']]);
        $db->logRows = [$this->decisionRow(1, 'car_photos_ignore', $this->seen($db), ['c.jpg', 'b.jpg'])];

        $recovery = $this->recovery($db);

        $this->assertTrue($recovery->isIgnored($recovery->findRows()[0]));
    }

    /**
     * @return array<string, array{list<string>, bool}>
     */
    public static function newestDecisionProvider(): array
    {
        return [
            'ignore, then undo'         => [['car_photos_ignore', 'car_photos_unignore'], false],
            'undo, then ignore'         => [['car_photos_unignore', 'car_photos_ignore'], true],
            'ignore, undo, ignore'      => [['car_photos_ignore', 'car_photos_unignore', 'car_photos_ignore'], true],
        ];
    }

    /**
     * @param list<string> $actions In id order
     */
    #[DataProvider('newestDecisionProvider')]
    public function testIsIgnoredNewestDecisionWins(array $actions, bool $expected): void
    {
        $this->photo('b.jpg', 2);
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);
        foreach ($actions as $index => $action) {
            $db->logRows[] = $this->decisionRow($index + 1, $action, $this->seen($db), ['b.jpg']);
        }

        $recovery = $this->recovery($db);

        $this->assertSame($expected, $recovery->isIgnored($recovery->findRows()[0]));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function badDecisionMetadataProvider(): array
    {
        return [
            'not JSON'          => ['{not json'],
            'NULL'              => [null],
            'apply row'         => ['{"car_id":7,"reattached":["b.jpg"],"order_restored":false}'],
            'no token'          => ['{"action":"car_photos_ignore","car_id":7,"detached":["b.jpg"]}'],
            'car_id as string'  => ['{"action":"car_photos_ignore","car_id":"7","token":"t","detached":["b.jpg"]}'],
            'detached not list' => ['{"action":"car_photos_ignore","car_id":7,"token":"t","detached":{"x":"b.jpg"}}'],
            'unknown action'    => ['{"action":"car_photos_other","car_id":7,"token":"t","detached":["b.jpg"]}'],
        ];
    }

    /**
     * A bad row is skipped, so an earlier valid ignore stays the newest decision.
     */
    #[DataProvider('badDecisionMetadataProvider')]
    public function testIsIgnoredSkipsLogRowWithBadMetadata(?string $metadata): void
    {
        $this->photo('b.jpg', 2);
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);
        $metadata = $metadata === null ? null : str_replace('"t"', '"' . $this->seen($db) . '"', $metadata);
        $db->logRows = [
            $this->decisionRow(1, 'car_photos_ignore', $this->seen($db), ['b.jpg']),
            ['id' => '2', 'user_id' => '4', 'logdate' => '2026-02-02 09:00:00', 'metadata' => $metadata],
        ];

        $recovery = $this->recovery($db);

        $this->assertSame(['userId' => 3, 'at' => '2026-02-01 09:00:01'], $recovery->ignoredBy($recovery->findRows()[0]));
    }

    public function testUndoIgnoreIgnoredRowWritesOneDecisionRow(): void
    {
        $this->photo('b.jpg', 2);
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);
        $db->logRows = [$this->decisionRow(1, 'car_photos_ignore', $this->seen($db), ['b.jpg'])];

        $result = $this->recovery($db)->undoIgnore(self::CAR, 5, $this->seen($db));

        $this->assertSame(CarPhotoRecoveryResult::Applied, $result);
        $entries = $this->logEntries();
        $this->assertCount(1, $entries);
        $this->assertSame(5, $entries[0]['user_id']);
        $this->assertSame('Car Photos: car 7, ignore undone', $entries[0]['message']);
        $this->assertSame('car_photos_unignore', $entries[0]['metadata']['action']);
        $this->assertNull($db->updateParams());
    }

    /**
     * @return array<string, array{bool, CarPhotoRecoveryResult}>
     */
    public static function undoIgnoreNoWriteProvider(): array
    {
        return [
            'row not ignored' => [true, CarPhotoRecoveryResult::NothingToDo],
            'stale token'     => [false, CarPhotoRecoveryResult::Changed],
        ];
    }

    #[DataProvider('undoIgnoreNoWriteProvider')]
    public function testUndoIgnoreWithoutMatchingIgnoreOrWithStaleTokenLogsNothing(bool $tokenMatches, CarPhotoRecoveryResult $expected): void
    {
        $this->photo('b.jpg', 2);
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);
        if (!$tokenMatches) {
            $db->logRows = [$this->decisionRow(1, 'car_photos_ignore', $this->seen($db), ['b.jpg'])];
        }
        $token = $tokenMatches ? $this->seen($db) : CarPhotoRecovery::versionToken('["x.jpg"]');

        $result = $this->recovery($db)->undoIgnore(self::CAR, 5, $token);

        $this->assertSame($expected, $result);
        $this->assertSame([], $this->logEntries());
    }

    public function testIgnoreStaleTokenReturnsChangedAndLogsNothing(): void
    {
        $this->photo('b.jpg', 2);
        $db = $this->db('[]', [[10, '["b.jpg"]', '2026-01-01 10:00:00']]);

        $result = $this->recovery($db)->ignore(self::CAR, 3, CarPhotoRecovery::versionToken('["x.jpg"]'));

        $this->assertSame(CarPhotoRecoveryResult::Changed, $result);
        $this->assertSame([], $this->logEntries());
    }

    // ------------------------------------------------------------------
    // findRows(): sort order
    // ------------------------------------------------------------------

    public function testFindRowsPutsRowsWithEarlierOrderFirstThenCarIdOrder(): void
    {
        $this->photo('d.jpg', 4);
        $this->otherCarPhoto('a.jpg', 1);
        $this->otherCarPhoto('b.jpg', 2);
        $this->otherCarPhoto('e.jpg', 5);
        mkdir($this->root . '/9');
        TestJpeg::write($this->root . '/9/x.jpg', 20, 15, 20, 40, 60);
        TestJpeg::write($this->root . '/9/y.jpg', 20, 15, 40, 40, 60);
        $db = new CarPhotoRecoveryFakeDatabase(
            [
                ['id' => (string) self::CAR, 'image' => '[]'],
                ['id' => (string) self::OTHER_CAR, 'image' => '["b.jpg","a.jpg"]'],
                ['id' => '9', 'image' => '["y.jpg","x.jpg"]'],
            ],
            [
                ['id' => '10', 'car_id' => (string) self::CAR, 'image' => '["d.jpg"]', 'timestamp' => '2026-01-01 10:00:00'],
                ['id' => '11', 'car_id' => (string) self::OTHER_CAR, 'image' => '["a.jpg","b.jpg","e.jpg"]', 'timestamp' => '2026-01-01 10:00:00'],
                ['id' => '12', 'car_id' => (string) self::OTHER_CAR, 'image' => '["a.jpg","b.jpg"]', 'timestamp' => '2026-01-02 10:00:00'],
                ['id' => '13', 'car_id' => '9', 'image' => '["x.jpg","y.jpg"]', 'timestamp' => '2026-01-01 10:00:00'],
            ]
        );

        $rows = $this->recovery($db)->findRows();

        $this->assertSame([self::OTHER_CAR, 9, self::CAR], array_map(static fn(CarPhotoRecoveryRow $row): int => $row->carId, $rows));
    }

    // ------------------------------------------------------------------
    // findRows(): no second restore offer after a restore
    // ------------------------------------------------------------------

    public function testApplyRestoreThenFindRowsOffersNoRestoreAndDropsTheRow(): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $db = $this->db('["b.jpg","a.jpg"]', [[10, '["a.jpg","b.jpg"]', '2026-01-01 10:00:00']]);
        $this->assertSame(CarPhotoRecoveryResult::Applied, $this->recovery($db)->apply(self::CAR, [], true, 3, $this->seen($db)));
        $this->assertSame(CarPhotoRecovery::versionToken('["a.jpg","b.jpg"]'), $this->logEntries()[0]['metadata']['token_after']);
        $this->assertTrue($this->logEntries()[0]['metadata']['order_restored']);
        // What the database holds after the write: the restored list, and the
        // reversed list that the trigger wrote to cars_hist.
        $db->carRows[0]['image']    = '["a.jpg","b.jpg"]';
        $db->histRows[]             = ['id' => '11', 'car_id' => (string) self::CAR, 'image' => '["b.jpg","a.jpg"]', 'timestamp' => '2026-03-01 10:00:00'];
        $db->logRows                = $this->logRowsFromEntries();

        $this->assertSame([], $this->recovery($db)->findRows(), 'No restore offer, and no other reason to show the car');
    }

    public function testApplyRestoreThenFindRowsKeepsDetachedPhotosWithoutRestoreOffer(): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $this->photo('c.jpg', 3);
        $db = $this->db('["a.jpg","b.jpg"]', [
            [10, '["c.jpg"]', '2026-01-01 10:00:00'],
            [11, '["b.jpg","a.jpg"]', '2026-03-01 10:00:00'],
        ]);
        $db->logRows = [$this->restoreRow(1, '["a.jpg","b.jpg"]')];

        $rows = $this->recovery($db)->findRows();

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->earlierOrder);
        $this->assertSame(['c.jpg'], $rows[0]->detachedNames());
    }

    public function testFindRowsAfterRestoreAndLaterOutOfBandChangeOffersRestoreAgain(): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        // The owner saved [b, a] after the restore wrote [a, b].
        $db = $this->db('["b.jpg","a.jpg"]', [
            [10, '["a.jpg","b.jpg"]', '2026-01-01 10:00:00'],
            [11, '["b.jpg","a.jpg"]', '2026-03-01 10:00:00'],
            [12, '["a.jpg","b.jpg"]', '2026-04-01 10:00:00'],
        ]);
        $db->logRows = [$this->restoreRow(1, '["a.jpg","b.jpg"]')];

        $rows = $this->recovery($db)->findRows();

        $this->assertCount(1, $rows);
        $this->assertSame(['a.jpg', 'b.jpg'], $rows[0]->earlierOrder);
    }

    public function testFindRowsApplyRowWithoutRestoreDoesNotHideTheRestoreOffer(): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $db = $this->db('["b.jpg","a.jpg"]', [[10, '["a.jpg","b.jpg"]', '2026-01-01 10:00:00']]);
        $db->logRows = [$this->restoreRow(1, '["b.jpg","a.jpg"]', false)];

        $this->assertSame(['a.jpg', 'b.jpg'], $this->recovery($db)->findRows()[0]->earlierOrder);
    }

    public function testApplyStaleRestorePostAfterRestoreReturnsNothingToDoAndWritesNothing(): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $db = $this->db('["a.jpg","b.jpg"]', [
            [10, '["a.jpg","b.jpg"]', '2026-01-01 10:00:00'],
            [11, '["b.jpg","a.jpg"]', '2026-03-01 10:00:00'],
        ]);
        $db->logRows = [$this->restoreRow(1, '["a.jpg","b.jpg"]')];

        $result = $this->recovery($db)->apply(self::CAR, [], true, 3, $this->seen($db));

        $this->assertSame(CarPhotoRecoveryResult::NothingToDo, $result);
        $this->assertNull($db->updateParams());
    }

    public function testIgnoreReversedOrderRowHidesItUntilTheListChanges(): void
    {
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $db = $this->db('["b.jpg","a.jpg"]', [[10, '["a.jpg","b.jpg"]', '2026-01-01 10:00:00']]);
        $this->assertSame(CarPhotoRecoveryResult::Applied, $this->recovery($db)->ignore(self::CAR, 3, $this->seen($db)));
        $db->logRows = $this->logRowsFromEntries();

        $recovery = $this->recovery($db);
        $row      = $recovery->findRows()[0];

        $this->assertSame(['a.jpg', 'b.jpg'], $row->earlierOrder, 'The row stays, so the ignored list can offer Undo ignore');
        $this->assertTrue($recovery->isIgnored($row), 'The tab hides the row');
    }

    public function testIgnoredOrderOnlyRowThatBecomesUnreadableIsNotIgnored(): void
    {
        $this->skipWhenRoot();
        $this->photo('a.jpg', 1);
        $this->photo('b.jpg', 2);
        $db = $this->db('["b.jpg","a.jpg"]', [[10, '["a.jpg","b.jpg"]', '2026-01-01 10:00:00']]);
        $this->assertSame(CarPhotoRecoveryResult::Applied, $this->recovery($db)->ignore(self::CAR, 3, $this->seen($db)));
        $db->logRows = $this->logRowsFromEntries();
        $this->lock($this->root . '/' . self::CAR);

        $recovery = $this->recovery($db);
        $row      = $recovery->findRows()[0];

        $this->assertSame([self::CAR . '/'], $row->unreadable, 'Test setup: the row is unreadable');
        $this->assertNull($recovery->ignoredBy($row), 'The tab shows the row and its banner');
        $this->assertFalse($recovery->isIgnored($row));
    }

    // ------------------------------------------------------------------
    // CarPhotoRecoveryRow invariants
    // ------------------------------------------------------------------

    public function testDetachedPhotoEmptyNameThrowsValidationException(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('needs a file name');

        new DetachedPhoto('', true, DetachedHow::Removed, '2026-01-01 10:00:00');
    }

    /**
     * @return array<string, array{int, list<DetachedPhoto>, list<string>|null, string|null, list<string>, string}>
     */
    public static function badRowProvider(): array
    {
        $photo = new DetachedPhoto('b.jpg', true, DetachedHow::Removed, '2026-01-01 10:00:00');
        return [
            'zero car ID'                   => [0, [], null, null, [], 'positive car ID'],
            'negative car ID'               => [-3, [], null, null, [], 'positive car ID'],
            'unreadable with detached'      => [7, [$photo], null, null, ['c.jpg'], 'cannot offer an action'],
            'unreadable with earlier order' => [7, [], ['b.jpg', 'a.jpg'], '2026-01-01 10:00:00', ['c.jpg'], 'cannot offer an action'],
            'order without time'            => [7, [], ['b.jpg', 'a.jpg'], null, [], 'or neither'],
            'time without order'            => [7, [], null, '2026-01-01 10:00:00', [], 'or neither'],
        ];
    }

    /**
     * @param list<DetachedPhoto> $detached
     * @param list<string>|null   $earlierOrder
     * @param list<string>        $unreadable
     */
    #[DataProvider('badRowProvider')]
    public function testRowConstructorBrokenInvariantThrowsValidationException(
        int $carId,
        array $detached,
        ?array $earlierOrder,
        ?string $earlierOrderAt,
        array $unreadable,
        string $message,
    ): void {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage($message);

        new CarPhotoRecoveryRow($carId, '[]', [], $detached, $earlierOrder, $earlierOrderAt, $unreadable);
    }

    public function testRowAttachableNamesKeepsOnlySafeNamesInOrder(): void
    {
        $row = new CarPhotoRecoveryRow(7, '[]', [], [
            new DetachedPhoto('c.jpg', true, DetachedHow::Removed, '2026-01-01 10:00:00'),
            new DetachedPhoto('bad name.jpg', false, DetachedHow::Removed, '2026-01-01 10:00:00'),
            new DetachedPhoto('b.jpg', true, DetachedHow::Replaced, '2026-01-02 10:00:00'),
        ], null, null);

        $this->assertSame(['c.jpg', 'b.jpg'], $row->attachableNames());
        $this->assertSame(['c.jpg', 'bad name.jpg', 'b.jpg'], $row->detachedNames());
    }

    // ------------------------------------------------------------------
    // thumbnailName()
    // ------------------------------------------------------------------

    public function testThumbnailNameReturnsResizedCopyWhenItExists(): void
    {
        $this->photo('a.jpeg', 1);
        $this->photo('a-resized-100.jpeg', 1);
        $this->photo('b.jpg', 2);
        $recovery = $this->recovery($this->db('[]', []));

        $this->assertSame('a-resized-100.jpeg', $recovery->thumbnailName(self::CAR, 'a.jpeg'));
        $this->assertSame('b.jpg', $recovery->thumbnailName(self::CAR, 'b.jpg'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function recovery(FakeDatabase $db): CarPhotoRecovery
    {
        return new CarPhotoRecovery($db, $this->root, new CarRepository($db));
    }

    /** The version token of the one car's `cars.image` in $db, as the page sends it. */
    private function seen(CarPhotoRecoveryFakeDatabase $db): string
    {
        $image = $db->carRows[0]['image'] ?? null;
        return CarPhotoRecovery::versionToken(is_string($image) ? $image : null);
    }

    /** Skip the test as root, because root reads a file with mode 000. */
    private function skipWhenRoot(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Root can read a file with mode 000, so the read failure cannot happen.');
        }
    }

    /** Remove all access to a file or directory. tearDown() restores it. */
    private function lock(string $path): void
    {
        $this->assertTrue(chmod($path, 0000));
        $this->locked[] = $path;
    }

    /** Write a real JPEG in the directory of OTHER_CAR. */
    private function otherCarPhoto(string $name, int $shade): void
    {
        $directory = $this->root . '/' . self::OTHER_CAR;
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        TestJpeg::write($directory . '/' . $name, 20, 15, 20 * $shade, 40, 60);
    }

    /**
     * Build a fake database with two cars. CAR has the detached c.jpg, and a
     * history list that is the exact reverse of its current list. So CAR
     * offers a restore when all of its files can be read. OTHER_CAR has the
     * detached d.jpg.
     */
    private function twoCarDb(): CarPhotoRecoveryFakeDatabase
    {
        return new CarPhotoRecoveryFakeDatabase(
            [
                ['id' => (string) self::CAR, 'image' => '["b.jpg","a.jpg"]'],
                ['id' => (string) self::OTHER_CAR, 'image' => '[]'],
            ],
            [
                ['id' => '10', 'car_id' => (string) self::CAR, 'image' => '["a.jpg","b.jpg","c.jpg"]', 'timestamp' => '2026-01-01 10:00:00'],
                ['id' => '11', 'car_id' => (string) self::CAR, 'image' => '["a.jpg","b.jpg"]', 'timestamp' => '2026-01-02 10:00:00'],
                ['id' => '12', 'car_id' => (string) self::OTHER_CAR, 'image' => '["d.jpg"]', 'timestamp' => '2026-01-01 10:00:00'],
            ]
        );
    }

    /** Write a real JPEG in the car directory. The same shade gives the same bytes. */
    private function photo(string $name, int $shade): void
    {
        TestJpeg::write($this->root . '/' . self::CAR . '/' . $name, 20, 15, 20 * $shade, 40, 60);
    }

    /**
     * Build a `logs` row of an Ignore or Undo ignore decision for CAR, by user 3.
     *
     * @param list<string> $detached
     * @return array<string, string>
     */
    private function decisionRow(int $id, string $action, string $token, array $detached): array
    {
        return [
            'id'       => (string) $id,
            'user_id'  => '3',
            'logdate'  => sprintf('2026-02-01 09:00:%02d', $id),
            'metadata' => (string) json_encode(['action' => $action, 'car_id' => self::CAR, 'token' => $token, 'detached' => $detached]),
        ];
    }

    /**
     * Build a `logs` row of an apply by user 3 for CAR that wrote $written.
     *
     * @return array<string, string>
     */
    private function restoreRow(int $id, string $written, bool $orderRestored = true): array
    {
        return [
            'id'       => (string) $id,
            'user_id'  => '3',
            'logdate'  => sprintf('2026-03-01 10:00:%02d', $id),
            'metadata' => (string) json_encode([
                'action'         => 'car_photos_apply',
                'car_id'         => self::CAR,
                'reattached'     => [],
                'order_restored' => $orderRestored,
                'token_after'    => CarPhotoRecovery::versionToken($written),
            ]),
        ];
    }

    /**
     * Turn the logger() calls so far into `logs` rows, as the real logger() stores them.
     *
     * @return list<array<string, string|null>>
     */
    private function logRowsFromEntries(): array
    {
        $rows = [];
        foreach ($this->logEntries() as $index => $entry) {
            $rows[] = [
                'id'       => (string) ($index + 1),
                'user_id'  => (string) $entry['user_id'],
                'logdate'  => sprintf('2026-02-01 09:00:%02d', $index + 1),
                'metadata' => $entry['metadata'] === null ? null : (string) json_encode($entry['metadata']),
            ];
        }
        return $rows;
    }

    /**
     * @return list<array{user_id: mixed, category: mixed, message: mixed, metadata: mixed}>
     */
    private function logEntries(): array
    {
        global $mockLogEntries;
        return $mockLogEntries ?? [];
    }

    /**
     * Build a fake database with one car and its history rows.
     *
     * @param list<array{int, string|null, string}> $history [id, image, timestamp] in id order
     */
    private function db(?string $currentImage, array $history): CarPhotoRecoveryFakeDatabase
    {
        $histRows = [];
        foreach ($history as [$id, $image, $time]) {
            $histRows[] = ['id' => (string) $id, 'car_id' => (string) self::CAR, 'image' => $image, 'timestamp' => $time];
        }

        return new CarPhotoRecoveryFakeDatabase([['id' => (string) self::CAR, 'image' => $currentImage]], $histRows);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } elseif (!unlink($path)) {
                fwrite(STDERR, "NOTE: tearDown() failed to unlink {$path}\n");
            }
        }
        if (!rmdir($dir)) {
            fwrite(STDERR, "NOTE: tearDown() failed to remove directory {$dir}\n");
        }
    }
}

/**
 * Fake database for CarPhotoRecoveryTest: hands back the `cars`, `cars_hist`
 * or `logs` rows by the SQL of the last query, and records each query.
 * An UPDATE reports $updateCount changed rows.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 */
class CarPhotoRecoveryFakeDatabase extends FakeDatabase
{
    /** @var list<array{string, array<mixed>}> Each query: SQL and params */
    public array $queries = [];

    /** @var list<array<string, mixed>> Rows for the `logs` query */
    public array $logRows = [];

    /** Rows an UPDATE reports. 0 is a compare-and-set miss. */
    public int $updateCount = 1;

    /** True makes error() report a failed query. */
    public bool $failQueries = false;

    private string $lastSql = '';

    /**
     * @param list<array<string, mixed>> $carRows  Rows for the `cars` query
     * @param list<array<string, mixed>> $histRows Rows for the `cars_hist` query
     */
    public function __construct(public array $carRows, public array $histRows)
    {
    }

    /**
     * @param string $sql SQL with `?` placeholders
     * @param array<mixed> $params Values bound to the placeholders
     * @return self This instance, for chaining
     */
    public function query(string $sql, array $params = []): self
    {
        $this->lastSql   = $sql;
        $this->queries[] = [$sql, $params];
        return $this;
    }

    /** @return bool The value of $failQueries */
    public function error(): bool
    {
        return $this->failQueries;
    }

    /**
     * @param bool $assoc Ignored. The rows are arrays.
     * @return array<int, object|array<string, mixed>> The rows for the last query
     */
    public function results(bool $assoc = false): array
    {
        if (str_contains($this->lastSql, 'FROM logs')) {
            return $this->logRows;
        }
        if (str_contains($this->lastSql, 'FROM cars_hist') && !str_contains($this->lastSql, 'EXISTS')) {
            return $this->histRows;
        }
        return str_starts_with(ltrim($this->lastSql), 'SELECT') ? $this->carRows : [];
    }

    /** @return int $updateCount after an UPDATE, else 0 */
    public function count(): int
    {
        return str_starts_with(ltrim($this->lastSql), 'UPDATE') ? $this->updateCount : 0;
    }

    /** @return array{string, array<mixed>}|null The SQL and params of the first `logs` query, or null when none ran */
    public function logQuery(): ?array
    {
        foreach ($this->queries as $query) {
            if (str_contains($query[0], 'FROM logs')) {
                return $query;
            }
        }
        return null;
    }

    /** @return array<mixed>|null The params of the first UPDATE, or null when none ran */
    public function updateParams(): ?array
    {
        foreach ($this->queries as [$sql, $params]) {
            if (str_starts_with(ltrim($sql), 'UPDATE')) {
                return $params;
            }
        }
        return null;
    }
}
