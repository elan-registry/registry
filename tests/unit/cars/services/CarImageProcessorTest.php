<?php

declare(strict_types=1);

use ElanRegistry\Car\CarImageProcessor;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\CarConcurrentModificationException;
use ElanRegistry\Exceptions\ImageProcessingException;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->processor = new CarImageProcessor($this->createStub(CarRepository::class));
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
    // removeImage tests
    // ============================================================

    public function testRemoveImageThrowsOnEmptyFilename(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->never())->method('updateImage');
        $processor = new CarImageProcessor($repo);

        $this->expectException(ImageProcessingException::class);
        $carData = (object) ['id' => 1, 'image' => '["test.jpg"]'];
        $processor->removeImage($carData, '');
    }

    public function testRemoveImageReturnsFalseWhenImageNotFound(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->never())->method('updateImage');
        $processor = new CarImageProcessor($repo);

        $carData = (object) ['id' => 1, 'image' => '["other.jpg"]'];
        $this->assertFalse($processor->removeImage($carData, 'nonexistent.jpg'));
    }

    public function testRemoveImageReturnsTrueWhenFound(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('updateImage')->willReturn(true);
        $processor = new CarImageProcessor($repo);

        $carData = (object) ['id' => 1, 'image' => '["test.jpg","other.jpg"]'];
        $result = $processor->removeImage($carData, 'test.jpg');
        $this->assertTrue($result);
    }

    public function testRemoveImageUpdatesCarData(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('updateImage')->willReturn(true);
        $processor = new CarImageProcessor($repo);

        $carData = (object) ['id' => 1, 'image' => '["test.jpg","other.jpg"]'];
        $processor->removeImage($carData, 'test.jpg');
        $this->assertEquals('["other.jpg"]', $carData->image);
    }

    public function testRemoveImageHandlesCsvFormat(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('updateImage')->willReturn(true);
        $processor = new CarImageProcessor($repo);

        $carData = (object) ['id' => 1, 'image' => 'test.jpg,other.jpg'];
        $result = $processor->removeImage($carData, 'test.jpg');
        $this->assertTrue($result);
    }

    public function testRemoveLastImageSetsCarDataImageToEmptyString(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('updateImage')->willReturn(true);
        $processor = new CarImageProcessor($repo);

        $carData = (object) ['id' => 1, 'image' => '["test.jpg"]'];
        $processor->removeImage($carData, 'test.jpg');
        $this->assertSame('', $carData->image);
    }

    /**
     * When updateImage() returns false (CAS conflict — another request modified
     * the image column between the read and the write), removeImage() must throw
     * CarConcurrentModificationException so save.php can route it to a retriable
     * response rather than a generic server error.
     */
    public function testRemoveImageThrowsCarConcurrentModificationExceptionOnCasConflict(): void
    {
        // count=0: UPDATE matched 0 rows → CAS guard failed → updateImage() returns false
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('updateImage')->willReturn(false);
        $processor = new CarImageProcessor($repo);

        $carData = (object) ['id' => 1, 'image' => '["test.jpg"]'];
        $this->expectException(CarConcurrentModificationException::class);
        $processor->removeImage($carData, 'test.jpg');
    }

    /**
     * An owner removal passes a timestamp to updateImage() and, on success,
     * copies it to the cached car row (issue #1929).
     */
    public function testRemoveImagePassesTimestampAndUpdatesCarDataWhenOwnerInitiated(): void
    {
        $captured = 'not-called';
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())
            ->method('updateImage')
            ->with(1, '["other.jpg"]', '["test.jpg","other.jpg"]', $this->anything())
            ->willReturnCallback(function (int $id, string $new, ?string $old, ?string $ts) use (&$captured): bool {
                $captured = $ts;
                return true;
            });
        $processor = new CarImageProcessor($repo);

        $carData = (object) ['id' => 1, 'image' => '["test.jpg","other.jpg"]', 'owner_last_updated' => '2024-01-01 00:00:00'];
        $processor->removeImage($carData, 'test.jpg', true);

        $this->assertIsString($captured);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $captured);
        $this->assertSame($captured, $carData->owner_last_updated);
    }

    /**
     * The default (admin, editor, or any non-owner caller) passes null, so
     * the freshness clock and the cached row stay unchanged.
     */
    public function testRemoveImagePassesNullTimestampWhenNotOwnerInitiated(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())
            ->method('updateImage')
            ->with(1, '["other.jpg"]', '["test.jpg","other.jpg"]', null)
            ->willReturn(true);
        $processor = new CarImageProcessor($repo);

        $carData = (object) ['id' => 1, 'image' => '["test.jpg","other.jpg"]', 'owner_last_updated' => '2024-01-01 00:00:00'];
        $processor->removeImage($carData, 'test.jpg');

        $this->assertSame('2024-01-01 00:00:00', $carData->owner_last_updated);
    }

    /**
     * On a CAS conflict nothing was written, so the cached owner_last_updated
     * must stay unchanged.
     */
    public function testRemoveImageLeavesOwnerLastUpdatedOnCasConflict(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('updateImage')->willReturn(false);
        $processor = new CarImageProcessor($repo);

        $carData = (object) ['id' => 1, 'image' => '["test.jpg"]', 'owner_last_updated' => '2024-01-01 00:00:00'];
        try {
            $processor->removeImage($carData, 'test.jpg', true);
            $this->fail('Expected CarConcurrentModificationException');
        } catch (CarConcurrentModificationException) {
            $this->assertSame('2024-01-01 00:00:00', $carData->owner_last_updated);
        }
    }

    // ============================================================
    // removeImages (plural) tests
    // ============================================================

    public function testRemoveImagesShortCircuitsOnEmptyFilenamesArray(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->never())->method('updateImage');
        $processor = new CarImageProcessor($repo);

        $carData = (object) ['id' => 1, 'image' => '["test.jpg","other.jpg"]'];
        $result = $processor->removeImages($carData, []);
        $this->assertSame(['updated' => false, 'casConflict' => false], $result);
        // Untouched — short-circuit must not mutate the passed-in car data.
        $this->assertSame('["test.jpg","other.jpg"]', $carData->image);
    }

    public function testRemoveImagesReturnsNoOpWhenCarDataImageIsEmpty(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->never())->method('updateImage');
        $processor = new CarImageProcessor($repo);

        $carData = (object) ['id' => 1, 'image' => ''];
        $result = $processor->removeImages($carData, ['test.jpg', 'other.jpg']);
        $this->assertSame(['updated' => false, 'casConflict' => false], $result);
    }

    public function testRemoveImagesHandlesCsvFormat(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('updateImage')->willReturn(true);
        $processor = new CarImageProcessor($repo);

        $carData = (object) ['id' => 1, 'image' => 'test.jpg,other.jpg'];
        $result = $processor->removeImages($carData, ['test.jpg']);
        $this->assertSame(['updated' => true, 'casConflict' => false], $result);
        $this->assertSame('["other.jpg"]', $carData->image);
    }

    public function testRemoveImagesRemovesOnlyFilenamesPresentInMixedList(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('updateImage')->willReturn(true);
        $processor = new CarImageProcessor($repo);

        $carData = (object) ['id' => 1, 'image' => '["a.jpg","b.jpg","c.jpg"]'];
        // 'x.jpg' and 'y.jpg' are not present in the current image list — only
        // 'a.jpg' and 'c.jpg' should actually be removed.
        $result = $processor->removeImages($carData, ['a.jpg', 'x.jpg', 'c.jpg', 'y.jpg']);
        $this->assertSame(['updated' => true, 'casConflict' => false], $result);
        $this->assertSame('["b.jpg"]', $carData->image);
    }

    // ============================================================
    // isResizedVariant tests
    //
    // Backs the mvTmpImages() fix in app/api/cars/save.php (issue #1452
    // review follow-up): a base filename's temp-directory glob() matches both
    // the original upload and every "-resized-{size}." thumbnail variant, and
    // only the base file's move success may determine whether the base
    // filename is treated as "unmoved" for the cars.image cleanup path.
    // ============================================================

    public function testIsResizedVariantTrueForThumbnailFilename(): void
    {
        $this->assertTrue(CarImageProcessor::isResizedVariant('img_abc123-resized-300.jpg'));
    }

    public function testIsResizedVariantTrueForEachConfiguredSize(): void
    {
        foreach ([100, 300, 768, 1024, 2048] as $size) {
            $this->assertTrue(
                CarImageProcessor::isResizedVariant("img_abc123-resized-{$size}.jpg"),
                "Expected size {$size} to be classified as a resized variant"
            );
        }
    }

    public function testIsResizedVariantFalseForBaseFilename(): void
    {
        $this->assertFalse(CarImageProcessor::isResizedVariant('img_abc123.jpg'));
    }

    public function testIsResizedVariantFalseForBaseFilenameContainingResizedSubstringWithoutSizeSuffix(): void
    {
        // "resized" appearing in a filename without the "-resized-{digits}."
        // shape must not be misclassified as a variant.
        $this->assertFalse(CarImageProcessor::isResizedVariant('img_resized_photo.jpg'));
    }

    /**
     * Reproduces the exact granularity mismatch from the mvTmpImages() bug:
     * given one base file and its resized variants, the base-vs-variant
     * classification for each glob() result — the same classification
     * mvTmpImages() now uses to decide $baseFound/$baseMoved — must resolve
     * to "unmoved" only via the base file, never via a variant.
     */
    public function testBaseFileMoveSuccessWithVariantMoveFailureIsNotUnmoved(): void
    {
        $globResults = [
            'img_abc123.jpg',
            'img_abc123-resized-100.jpg',
            'img_abc123-resized-300.jpg',
        ];
        // Simulates: base file moved successfully, one variant's rename() failed.
        $moveSucceeded = [
            'img_abc123.jpg' => true,
            'img_abc123-resized-100.jpg' => true,
            'img_abc123-resized-300.jpg' => false,
        ];

        $baseFound = false;
        $baseMoved = false;
        foreach ($globResults as $name) {
            $isBase = !CarImageProcessor::isResizedVariant($name);
            if ($isBase) {
                $baseFound = true;
            }
            if ($moveSucceeded[$name] && $isBase) {
                $baseMoved = true;
            }
        }

        $isUnmoved = !$baseFound || !$baseMoved;
        $this->assertFalse($isUnmoved, 'Base file moved successfully — must not be treated as unmoved despite a failed variant move');
    }

    public function testBaseFileMoveFailureIsUnmovedRegardlessOfVariants(): void
    {
        $globResults = [
            'img_abc123.jpg',
            'img_abc123-resized-100.jpg',
        ];
        $moveSucceeded = [
            'img_abc123.jpg' => false,
            'img_abc123-resized-100.jpg' => true,
        ];

        $baseFound = false;
        $baseMoved = false;
        foreach ($globResults as $name) {
            $isBase = !CarImageProcessor::isResizedVariant($name);
            if ($isBase) {
                $baseFound = true;
            }
            if ($moveSucceeded[$name] && $isBase) {
                $baseMoved = true;
            }
        }

        $isUnmoved = !$baseFound || !$baseMoved;
        $this->assertTrue($isUnmoved, 'Base file failed to move — must be treated as unmoved even though a variant moved');
    }

    public function testBaseFileNeverFoundIsUnmoved(): void
    {
        // Base file missing from temp storage entirely — only variants present,
        // and the variant's own move succeeds (it just doesn't matter here).
        $globResults = ['img_abc123-resized-100.jpg'];

        $baseFound = false;
        $baseMoved = false;
        foreach ($globResults as $name) {
            $isBase = !CarImageProcessor::isResizedVariant($name);
            if ($isBase) {
                $baseFound = true;
                $baseMoved = true;
            }
        }

        $isUnmoved = !$baseFound || !$baseMoved;
        $this->assertTrue($isUnmoved, 'Base file never found in glob() results — must be treated as unmoved');
    }

    // ============================================================
    // resolveUploadOrder tests
    //
    // Backs uploadImages() in app/api/cars/save.php (#2609): a save with a
    // new upload used to drop every stored photo whose name was not the
    // secure img_<32 hex> format, so legacy photos vanished.
    // ============================================================

    /**
     * @return array<string, array{list<string>, list<string>, list<string>, array{kept: list<string>, dropped: list<string>, storedUnsafe: list<string>}}>
     */
    public static function resolveUploadOrderProvider(): array
    {
        $legacy  = '20151114101545_img_2492.jpg';
        $newName = 'img_0123456789abcdef0123456789abcdef.jpg';
        // Secure format, but neither generated by this request nor stored, so
        // a client must not be able to add it (for example a file in temp/).
        $forged  = 'img_ffffffffffffffffffffffffffffffff.jpg';

        return [
            'stored legacy name and new name stay, unknown names drop' => [
                [$legacy, $newName, '../x.jpg', 'photo.jpg'],
                [$newName],
                [$legacy, '../x.jpg'],
                ['kept' => [$legacy, $newName], 'dropped' => ['photo.jpg'], 'storedUnsafe' => ['../x.jpg']],
            ],
            'secure-format name not generated by this request drops' => [
                [$legacy, $forged, $newName],
                [$newName],
                [$legacy],
                ['kept' => [$legacy, $newName], 'dropped' => [$forged], 'storedUnsafe' => []],
            ],
            'new car keeps only names this request generated' => [
                [$legacy, $newName],
                [$newName],
                [],
                ['kept' => [$newName], 'dropped' => [$legacy], 'storedUnsafe' => []],
            ],
        ];
    }

    /**
     * @param list<string> $requestedOrder
     * @param list<string> $newNames
     * @param list<string> $storedNames
     * @param array{kept: list<string>, dropped: list<string>, storedUnsafe: list<string>} $expected
     */
    #[DataProvider('resolveUploadOrderProvider')]
    public function testResolveUploadOrder(array $requestedOrder, array $newNames, array $storedNames, array $expected): void
    {
        $this->assertSame(
            $expected,
            CarImageProcessor::resolveUploadOrder($requestedOrder, $newNames, $storedNames)
        );
    }

    public function testResolveUploadOrderKeepsForgedUnsafeNameInDroppedNotStoredUnsafe(): void
    {
        // Not stored for this car, so it is a client forgery, not a stored name to protect.
        $result = CarImageProcessor::resolveUploadOrder(['../etc/passwd.jpg'], [], ['20151114101545_img_2492.jpg']);

        $this->assertSame(['../etc/passwd.jpg'], $result['dropped']);
        $this->assertSame([], $result['storedUnsafe']);
        $this->assertSame([], $result['kept']);
    }

    public function testResolveUploadOrderNonStringNameNotInAnyListIsDropped(): void
    {
        $result = CarImageProcessor::resolveUploadOrder([5, 'a.jpg'], [], []); // @phpstan-ignore argument.type

        $this->assertSame([5, 'a.jpg'], $result['dropped']);
    }

    public function testResolveUploadOrderThrowsTypeErrorForNonStringNameInNewNames(): void
    {
        // No contract is documented. The code fails loud: no result, so no save with a lost photo.
        $this->expectException(\TypeError::class);
        // @phpstan-ignore-next-line
        CarImageProcessor::resolveUploadOrder([5], [5], []);
    }

    public function testResolveUploadOrderThrowsTypeErrorForNonStringNameInStoredNames(): void
    {
        $this->expectException(\TypeError::class);
        // @phpstan-ignore-next-line
        CarImageProcessor::resolveUploadOrder([5], [], [5]);
    }

    // ============================================================
    // storedImageFilenames tests
    // ============================================================

    /**
     * @return array<string, array{string|null, list<string>}>
     */
    public static function storedImageFilenamesProvider(): array
    {
        return [
            'CSV (legacy row)'              => ['1234567890.jpg,abc.jpeg', ['1234567890.jpg', 'abc.jpeg']],
            'JSON array'                    => ['["a.jpg","img_0123456789abcdef0123456789abcdef.jpg"]', ['a.jpg', 'img_0123456789abcdef0123456789abcdef.jpg']],
            'null'                          => [null, []],
            'empty string'                  => ['', []],
            'bad JSON falls back to CSV'    => ['["a.jpg",', ['["a.jpg"', ]],
            'JSON drops non-strings'        => ['["a.jpg",5,"",null,"b.jpg"]', ['a.jpg', 'b.jpg']],
            'CSV drops empty entries'       => ['a.jpg,,b.jpg', ['a.jpg', 'b.jpg']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('storedImageFilenamesProvider')]
    public function testStoredImageFilenames(?string $imageData, array $expected): void
    {
        $this->assertSame($expected, CarImageProcessor::storedImageFilenames($imageData));
    }

    public function testStoredCsvNamesSurviveResolveUploadOrderWithNewUpload(): void
    {
        $newName = 'img_0123456789abcdef0123456789abcdef.jpg';
        $stored  = CarImageProcessor::storedImageFilenames('1234567890.jpg,abc.jpeg');

        $result = CarImageProcessor::resolveUploadOrder(['1234567890.jpg', 'abc.jpeg', $newName], [$newName], $stored);

        $this->assertSame(['1234567890.jpg', 'abc.jpeg', $newName], $result['kept']);
        $this->assertSame([], $result['dropped']);
        $this->assertSame([], $result['storedUnsafe']);
    }

    // ============================================================
    // checkUploadOrder tests
    // ============================================================

    private const NEW_NAME = 'img_0123456789abcdef0123456789abcdef.jpg';

    public function testCheckUploadOrderNameWithNoUploadIsMissingAndGivesError(): void
    {
        // #2626: filenames named a photo, but no file arrived (empty tmp_name).
        $result = CarImageProcessor::resolveUploadOrder(['lost-photo.jpg'], [], []);

        $check = CarImageProcessor::checkUploadOrder($result, [], []);

        $this->assertSame(['lost-photo.jpg'], $check['missing']);
        $this->assertCount(1, $check['errors']);
        $this->assertStringContainsString('lost-photo.jpg', $check['errors'][0]);
        $this->assertStringContainsString('did not reach the server', $check['errors'][0]);
    }

    public function testCheckUploadOrderOnlyMatchedUploadsGiveNoErrors(): void
    {
        $result = CarImageProcessor::resolveUploadOrder([self::NEW_NAME], [self::NEW_NAME], []);

        $check = CarImageProcessor::checkUploadOrder($result, [self::NEW_NAME], []);

        $this->assertSame(
            ['missing' => [], 'storedUnsafe' => [], 'unplaced' => [], 'errors' => []],
            $check
        );
    }

    /**
     * A reorder-only save (no new files) with stored names in the new and
     * legacy formats is the most common edit. It must give no error.
     */
    public function testCheckUploadOrderReorderOnlyStoredNamesGiveNoErrors(): void
    {
        $stored = [self::NEW_NAME, '1234567890.jpg', 'abc.jpeg'];
        $result = CarImageProcessor::resolveUploadOrder(array_reverse($stored), [], $stored);

        $check = CarImageProcessor::checkUploadOrder($result, [], []);

        $this->assertSame(array_reverse($stored), $result['kept']);
        $this->assertSame(
            ['missing' => [], 'storedUnsafe' => [], 'unplaced' => [], 'errors' => []],
            $check
        );
    }

    public function testCheckUploadOrderStoredUnsafeNameGivesError(): void
    {
        $result = CarImageProcessor::resolveUploadOrder(['../x.jpg'], [], ['../x.jpg']);

        $check = CarImageProcessor::checkUploadOrder($result, [], []);

        $this->assertSame(['../x.jpg'], $check['storedUnsafe']);
        $this->assertSame([], $check['missing']);
        $this->assertCount(1, $check['errors']);
        $this->assertStringContainsString('../x.jpg', $check['errors'][0]);
        $this->assertStringContainsString('The save stopped', $check['errors'][0]);
    }

    public function testCheckUploadOrderUnplacedNewNameGivesError(): void
    {
        // The upload was stored, but the client order had no entry that matched it.
        $result = CarImageProcessor::resolveUploadOrder([], [self::NEW_NAME], []);

        $check = CarImageProcessor::checkUploadOrder($result, [self::NEW_NAME], []);

        $this->assertSame([self::NEW_NAME], $check['unplaced']);
        $this->assertCount(1, $check['errors']);
        $this->assertStringContainsString('Photo could not be added', $check['errors'][0]);
    }

    public function testCheckUploadOrderFailedNameIsNotRepeatedInMissing(): void
    {
        $result = CarImageProcessor::resolveUploadOrder(['big.jpg', 'gone.jpg'], [], []);

        $check = CarImageProcessor::checkUploadOrder($result, [], ['big.jpg']);

        $this->assertSame(['gone.jpg'], $check['missing']);
        $this->assertCount(1, $check['errors']);
        $this->assertStringNotContainsString('big.jpg', $check['errors'][0]);
        $this->assertStringContainsString('gone.jpg', $check['errors'][0]);
    }

    public function testCheckUploadOrderOnlyFailedNamesGiveNoErrorHere(): void
    {
        $result = CarImageProcessor::resolveUploadOrder(['big.jpg'], [], []);

        $check = CarImageProcessor::checkUploadOrder($result, [], ['big.jpg']);

        $this->assertSame([], $check['missing']);
        $this->assertSame([], $check['errors']);
    }

    public function testCheckUploadOrderCapsNameAt100CharactersInMessage(): void
    {
        $long   = str_repeat('a', 150) . '.jpg';
        $result = ['kept' => [], 'dropped' => [$long], 'storedUnsafe' => []];

        $check = CarImageProcessor::checkUploadOrder($result, [], []);

        $this->assertStringContainsString(str_repeat('a', 100), $check['errors'][0]);
        $this->assertStringNotContainsString(str_repeat('a', 101), $check['errors'][0]);
        // missing keeps the full name for the log.
        $this->assertSame([$long], $check['missing']);
    }

    public function testCheckUploadOrderRemovesControlCharactersFromMessage(): void
    {
        $result = ['kept' => [], 'dropped' => ["ev\nil\x00\x1F\x7F.jpg"], 'storedUnsafe' => []];

        $check = CarImageProcessor::checkUploadOrder($result, [], []);

        $this->assertStringContainsString('evil.jpg', $check['errors'][0]);
        $this->assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $check['errors'][0]));
    }

    public function testCheckUploadOrderListsAtMost20NamesInMessage(): void
    {
        $names  = [];
        for ($i = 1; $i <= 25; $i++) {
            $names[] = "p{$i}x.jpg";
        }
        $result = ['kept' => [], 'dropped' => $names, 'storedUnsafe' => []];

        $check = CarImageProcessor::checkUploadOrder($result, [], []);

        $this->assertStringContainsString('25 photo(s)', $check['errors'][0]);
        $this->assertStringContainsString('p20x.jpg', $check['errors'][0]);
        $this->assertStringNotContainsString('p21x.jpg', $check['errors'][0]);
    }

    public function testCheckUploadOrderThrowsTypeErrorWhenResultLacksDroppedKey(): void
    {
        // No contract is documented. The code fails loud (TypeError after a warning), so the save cannot go on.
        $this->expectException(\TypeError::class);
        @CarImageProcessor::checkUploadOrder(['kept' => [], 'storedUnsafe' => []], [], []); // @phpstan-ignore argument.type
    }

    public function testCheckUploadOrderThrowsTypeErrorWhenResultLacksStoredUnsafeKey(): void
    {
        // A result from before the storedUnsafe key existed.
        $this->expectException(\TypeError::class);
        @CarImageProcessor::checkUploadOrder(['kept' => [], 'dropped' => ['a.jpg']], [], []); // @phpstan-ignore argument.type
    }

    public function testCheckUploadOrderThrowsTypeErrorForNonStringNameInDropped(): void
    {
        $this->expectException(\TypeError::class);
        CarImageProcessor::checkUploadOrder(['kept' => [], 'dropped' => [5], 'storedUnsafe' => []], [], []); // @phpstan-ignore argument.type
    }
}
