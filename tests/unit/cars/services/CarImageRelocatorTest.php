<?php

declare(strict_types=1);

use ElanRegistry\Car\CarImageRelocator;
use ElanRegistry\Exceptions\ImageProcessingException;
use ElanRegistry\UploadPathGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CarImageRelocator against real temp directories.
 *
 * No filesystem mocking: moving files is the entire point of this class, and
 * mocking rename()/is_dir()/etc. away would test nothing real. "Unit" here
 * means no DB, per project convention (CarImageRelocator itself has zero
 * framework dependency — no globals, no logger(), no $db — see its docblock).
 *
 * @issue 1867
 */
#[Group('fast')]
final class CarImageRelocatorTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/elan_relocator_' . bin2hex(random_bytes(8));
        mkdir($this->tempRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        // Guarded by is_dir() and run BEFORE cleanup: a test that chmod'd a car
        // directory to 0500 must restore write access first, or
        // recursiveRemoveDirectory() silently leaves it behind (it only writes
        // to STDERR on failure) and the temp dir leaks across runs.
        foreach ([5, 6] as $carId) {
            $dir = $this->tempRoot . '/' . $carId;
            if (is_dir($dir)) {
                @chmod($dir, 0700);
            }
        }

        $this->recursiveRemoveDirectory($this->tempRoot);
    }

    private function recursiveRemoveDirectory(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }

        $entries = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($entries as $entry) {
            $path = $dir . '/' . $entry;
            if (is_link($path) || !is_dir($path)) {
                if (!unlink($path)) {
                    fwrite(STDERR, "NOTE: tearDown() failed to unlink {$path}\n");
                }
            } else {
                $this->recursiveRemoveDirectory($path);
            }
        }
        if (!rmdir($dir)) {
            fwrite(STDERR, "NOTE: tearDown() failed to remove directory {$dir}\n");
        }
    }

    /**
     * Write a real file with content so tests can distinguish "the incoming
     * file" from "the existing file" by contents, not just by name.
     */
    private function writeFile(string $path, string $contents = 'content'): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $contents);
    }

    private function relocator(): CarImageRelocator
    {
        return new CarImageRelocator($this->tempRoot);
    }

    // -------------------------------------------------------------------
    // Core relocate()/restore() behavior
    // -------------------------------------------------------------------

    public function testRelocateMovesBaseAndVariantsToEmptyTargetWithIdentityMap(): void
    {
        $this->writeFile($this->tempRoot . '/5/img_x.jpg', 'base');
        $this->writeFile($this->tempRoot . '/5/img_x-resized-100.jpg', 'v100');
        $this->writeFile($this->tempRoot . '/5/img_x-resized-300.jpg', 'v300');

        $map = $this->relocator()->relocate(5, 6, ['img_x.jpg']);

        $this->assertSame(['img_x.jpg' => 'img_x.jpg'], $map);
        $this->assertFileExists($this->tempRoot . '/6/img_x.jpg');
        $this->assertFileExists($this->tempRoot . '/6/img_x-resized-100.jpg');
        $this->assertFileExists($this->tempRoot . '/6/img_x-resized-300.jpg');
        $this->assertDirectoryDoesNotExist($this->tempRoot . '/5');
    }

    public function testRelocateCollisionRenamesIncomingLeavesExistingUntouched(): void
    {
        $this->writeFile($this->tempRoot . '/5/img_x.jpg', 'incoming-base');
        $this->writeFile($this->tempRoot . '/5/img_x-resized-100.jpg', 'incoming-variant');
        $this->writeFile($this->tempRoot . '/6/img_x.jpg', 'existing-base');

        $map = $this->relocator()->relocate(5, 6, ['img_x.jpg']);

        $this->assertArrayHasKey('img_x.jpg', $map);
        $newBase = $map['img_x.jpg'];
        $this->assertNotSame('img_x.jpg', $newBase, 'a colliding filename must be renamed, not overwritten');

        // The existing target file survives, unmodified, under its original name.
        $this->assertSame('existing-base', file_get_contents($this->tempRoot . '/6/img_x.jpg'));

        // The incoming file survives under the new name, with its own content.
        $this->assertSame('incoming-base', file_get_contents($this->tempRoot . '/6/' . $newBase));

        // All of the renamed base's variants follow using the SAME new stem.
        $newStem = pathinfo($newBase, PATHINFO_FILENAME);
        $this->assertFileExists($this->tempRoot . '/6/' . $newStem . '-resized-100.jpg');
        $this->assertSame(
            'incoming-variant',
            file_get_contents($this->tempRoot . '/6/' . $newStem . '-resized-100.jpg')
        );
    }

    public function testRelocateMovesBaseEvenWhenAVariantIsMissingOnDisk(): void
    {
        // Simulates a partial earlier write (#1629-class defect): the base
        // exists but one of its expected variants never landed on disk.
        $this->writeFile($this->tempRoot . '/5/img_x.jpg', 'base');

        $map = $this->relocator()->relocate(5, 6, ['img_x.jpg']);

        $this->assertSame(['img_x.jpg' => 'img_x.jpg'], $map);
        $this->assertFileExists($this->tempRoot . '/6/img_x.jpg');
        $this->assertFileDoesNotExist($this->tempRoot . '/6/img_x-resized-100.jpg');
    }

    public function testRelocateIsNoOpWhenSourceDirectoryIsAbsent(): void
    {
        // Neither /5 nor /6 exists at all.
        $map = $this->relocator()->relocate(5, 6, ['img_x.jpg']);

        $this->assertSame([], $map);
        $this->assertDirectoryDoesNotExist($this->tempRoot . '/6');
    }

    public function testRelocateRemovesEmptySourceDirectoryEvenWithNoFiles(): void
    {
        mkdir($this->tempRoot . '/5', 0755, true);

        $map = $this->relocator()->relocate(5, 6, []);

        $this->assertSame([], $map);
        $this->assertDirectoryDoesNotExist($this->tempRoot . '/5');
        // No file was ever moved, so the target directory must never be created.
        $this->assertDirectoryDoesNotExist($this->tempRoot . '/6');
    }

    public function testRestoreMovesFilesBackAndRecreatesSourceDirectory(): void
    {
        $this->writeFile($this->tempRoot . '/5/img_x.jpg', 'base');
        $this->writeFile($this->tempRoot . '/5/img_x-resized-100.jpg', 'variant');

        $relocator = $this->relocator();
        $map = $relocator->relocate(5, 6, ['img_x.jpg']);
        $this->assertDirectoryDoesNotExist($this->tempRoot . '/5');

        $relocator->restore(5, 6, $map);

        $this->assertDirectoryExists($this->tempRoot . '/5');
        $this->assertFileExists($this->tempRoot . '/5/img_x.jpg');
        $this->assertSame('base', file_get_contents($this->tempRoot . '/5/img_x.jpg'));
        $this->assertFileExists($this->tempRoot . '/5/img_x-resized-100.jpg');
        $this->assertSame('variant', file_get_contents($this->tempRoot . '/5/img_x-resized-100.jpg'));

        // Target is left as it was before the move (empty, in this case) —
        // restore() does not delete the target directory itself.
        $this->assertDirectoryExists($this->tempRoot . '/6');
        $this->assertSame(['.', '..'], scandir($this->tempRoot . '/6'));
    }

    /**
     * Skipped when running as root: root bypasses permission bits, turning
     * chmod(0500) into a silent false pass rather than a real failure test.
     * CI (ubuntu-latest, no container:) runs as the non-root `runner` user.
     */
    public function testUnwritableTargetDirectoryThrowsAndRestoreFullyRecovers(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Running as root bypasses filesystem permission bits.');
        }

        $this->writeFile($this->tempRoot . '/5/img_x.jpg', 'base');
        // Target directory must exist BEFORE the chmod so relocate() attempts an
        // actual move into it rather than creating it fresh.
        mkdir($this->tempRoot . '/6', 0700, true);
        chmod($this->tempRoot . '/6', 0500);

        $relocator = $this->relocator();

        try {
            $threw = false;
            try {
                $relocator->relocate(5, 6, ['img_x.jpg']);
            } catch (ImageProcessingException $e) {
                $threw = true;
            }
            $this->assertTrue($threw, 'relocate() must throw when the target directory is unwritable');

            // Compensate as CarAdministrationService::merge() would on its catch
            // path. relocate() throws before populating a map on this failure,
            // so restore() is invoked with what the caller already had: nothing
            // was moved, so an empty map is the correct compensating call.
            $relocator->restore(5, 6, []);
        } finally {
            // Guarded by is_dir() and run BEFORE cleanup, or the temp dir leaks.
            if (is_dir($this->tempRoot . '/6')) {
                chmod($this->tempRoot . '/6', 0700);
            }
        }

        $this->assertFileExists($this->tempRoot . '/5/img_x.jpg', 'source file must still exist after the failed move');
    }

    /**
     * The actual partial-move recovery scenario AC 4 exists for: TWO base
     * files (each with a resized variant) in the source directory, the
     * FIRST moves completely, the SECOND fails partway through — its base
     * file moves but its variant does not. Every other failure test in this
     * file induces failure on the FIRST file's base, so relocate() never
     * gets past a single already-moved (or not-yet-moved) file before
     * throwing — it never exercises compensating a *second*, distinct,
     * already-relocated file at the same time.
     *
     * relocate() is now itself the compensating caller on the failure path
     * (see its docblock): a mid-loop exception makes it call its own
     * restore() with everything moved so far — the completed entries in
     * $renameMap plus the base file caught mid-move via $inFlight — before
     * re-throwing, so a caller (CarAdministrationService::merge()) that
     * only ever sees an empty map on this path is correctly calling
     * restore() as a no-op afterwards. That makes relocate()'s own
     * self-compensation the thing this test must exercise directly, with a
     * genuinely multi-file partial move — not a hand-built map passed to
     * restore() in isolation.
     *
     * A pre-existing BASE-file collision does not make moveFile() throw:
     * resolveDestinationName() detects it beforehand and picks a fresh
     * generated name instead, so a colliding base file is simply renamed
     * rather than failing. moveVariants(), however, has no such
     * rename-on-collision logic of its own — it always moves a variant to
     * the (possibly-renamed) base's exact stem. Pre-creating the SECOND
     * file's *variant* destination therefore deterministically fails only
     * that variant's move — via the #1867 review-added overwrite guard in
     * moveFile() — after img_a.jpg has moved completely and img_b.jpg's
     * base has also already moved, giving relocate() a genuinely partial,
     * two-entry internal state (one complete via $renameMap, one in-flight)
     * to compensate for.
     *
     * After relocate() throws, BOTH base files must be back in the source
     * directory under their ORIGINAL names, img_a.jpg's variant must be
     * back too, img_b.jpg's variant (which never moved) must still be in
     * the source, and the target directory must hold none of the relocated
     * leftovers (only the pre-existing collision file that caused the
     * failure). A caller's own restore(..., []) afterwards — exactly what
     * CarAdministrationService::merge()'s catch block does, since it never
     * received a map — must then be a true no-op.
     */
    public function testRelocateSelfCompensatesBothFilesAfterPartialMoveFailsOnSecondFilesVariant(): void
    {
        $this->writeFile($this->tempRoot . '/5/img_a.jpg', 'base-a');
        $this->writeFile($this->tempRoot . '/5/img_a-resized-100.jpg', 'variant-a-100');
        $this->writeFile($this->tempRoot . '/5/img_b.jpg', 'base-b');
        $this->writeFile($this->tempRoot . '/5/img_b-resized-100.jpg', 'variant-b-100');

        $this->writeFile($this->tempRoot . '/6/img_b-resized-100.jpg', 'pre-existing-collision');

        $relocator = $this->relocator();

        $threw = false;
        try {
            $relocator->relocate(5, 6, ['img_a.jpg', 'img_b.jpg']);
        } catch (ImageProcessingException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'relocate() must throw when the second file\'s variant collides with an existing target file');

        // A caller on this path (CarAdministrationService::merge()) never
        // received a map, so its own compensating call is restore(..., [])
        // — must be a genuine no-op given relocate() already self-compensated.
        $callerUnrestored = $relocator->restore(5, 6, []);
        $this->assertSame([], $callerUnrestored);

        // Both base files back in the source directory under their original names.
        $this->assertFileExists($this->tempRoot . '/5/img_a.jpg', 'first base file must be restored to the source');
        $this->assertSame('base-a', file_get_contents($this->tempRoot . '/5/img_a.jpg'));
        $this->assertFileExists($this->tempRoot . '/5/img_b.jpg', 'second base file must be restored to the source');
        $this->assertSame('base-b', file_get_contents($this->tempRoot . '/5/img_b.jpg'));

        // Variants restored / left in place.
        $this->assertFileExists(
            $this->tempRoot . '/5/img_a-resized-100.jpg',
            'first base file\'s variant must be restored to the source'
        );
        $this->assertSame('variant-a-100', file_get_contents($this->tempRoot . '/5/img_a-resized-100.jpg'));
        $this->assertFileExists(
            $this->tempRoot . '/5/img_b-resized-100.jpg',
            'second base file\'s variant was never moved and must still be in the source'
        );
        $this->assertSame('variant-b-100', file_get_contents($this->tempRoot . '/5/img_b-resized-100.jpg'));

        // Target directory left as it was before the move: only the
        // pre-existing collision file remains, no leftovers from either
        // base file.
        $this->assertFileDoesNotExist($this->tempRoot . '/6/img_a.jpg', 'restored file must be gone from the target');
        $this->assertFileDoesNotExist(
            $this->tempRoot . '/6/img_a-resized-100.jpg',
            'restored variant must be gone from the target'
        );
        $this->assertFileDoesNotExist($this->tempRoot . '/6/img_b.jpg', 'restored second base file must be gone from the target');
        $this->assertFileExists(
            $this->tempRoot . '/6/img_b-resized-100.jpg',
            'the pre-existing collision file must be untouched'
        );
        $this->assertSame('pre-existing-collision', file_get_contents($this->tempRoot . '/6/img_b-resized-100.jpg'));
        $this->assertCount(
            3,
            scandir($this->tempRoot . '/6'),
            'target directory must hold only "." ".." and the pre-existing collision file'
        );
    }

    // -------------------------------------------------------------------
    // UploadPathGuard — the class's own guard, tested at the boundary it
    // actually enforces (see note below on why relocate() itself cannot
    // reach a rejection).
    // -------------------------------------------------------------------

    /**
     * relocate() always derives both the source and target directories as
     * `{$this->imageBaseDirectory}/{carId}` internally — there is no
     * caller-supplied path. Because of that, `dirname()` of either directory
     * is always exactly `$this->imageBaseDirectory` as a string, so
     * `UploadPathGuard::isWithinTarget($this->imageBaseDirectory, $dir)`
     * cannot disagree with the source-directory existence check that must
     * pass first (`is_dir($sourceDirectory)`): both walk the identical
     * parent path, so whatever breaks one breaks the other. There is
     * therefore no filesystem state reachable through relocate()'s own
     * public contract where the source directory exists (clearing the
     * no-op check) AND the guard rejects it — confirmed empirically
     * (symlinks, `..` segments, and permission changes on the base
     * directory were all tried and none produce a split).
     *
     * This test instead exercises UploadPathGuard::isWithinTarget() directly
     * — the exact guard relocate() delegates to via assertWithinBase() — for
     * the traversal case it exists to catch: a destination outside the
     * configured base. This is the guard's own behavioral contract, not a
     * duplicate/hand-rolled check (see UploadPathGuardTest.php for the
     * guard's own full behavioral suite).
     */
    public function testUploadPathGuardRejectsADirectoryOutsideTheBase(): void
    {
        mkdir($this->tempRoot . '/5', 0755, true);
        $sibling = $this->tempRoot . '-other';
        mkdir($sibling . '/5', 0755, true);

        $this->assertFalse(
            UploadPathGuard::isWithinTarget($this->tempRoot, $sibling . '/5'),
            'a sibling directory sharing a name prefix with the base must be rejected'
        );

        $this->recursiveRemoveDirectory($sibling);
    }

    // -------------------------------------------------------------------
    // Wrong-typed / malformed input
    // -------------------------------------------------------------------

    public function testRelocateWithEmptyListIsNoOpNotAnError(): void
    {
        $this->writeFile($this->tempRoot . '/5/img_x.jpg', 'base');

        $map = $this->relocator()->relocate(5, 6, []);

        // The source directory is left in place because it was never touched:
        // relocate() only removes it once it has finished iterating filenames,
        // and an empty list still reaches that point — but since nothing was
        // in the list, is_file() never matched, so nothing moved. The source
        // still holds its (unrequested) file, and is removed only if empty —
        // it isn't, so it survives.
        $this->assertSame([], $map);
        $this->assertDirectoryExists($this->tempRoot . '/5');
        $this->assertFileExists($this->tempRoot . '/5/img_x.jpg');
        $this->assertDirectoryDoesNotExist($this->tempRoot . '/6');
    }

    public function testRelocateIgnoresAResizedVariantNamePassedAsABase(): void
    {
        $this->writeFile($this->tempRoot . '/5/img_x.jpg', 'base');
        $this->writeFile($this->tempRoot . '/5/img_x-resized-100.jpg', 'variant');

        $map = $this->relocator()->relocate(5, 6, ['img_x.jpg', 'img_x-resized-100.jpg']);

        // The variant name is dropped by filterBaseFilenames() (isResizedVariant()),
        // never treated as its own base: it must not appear as a map key, and it
        // must not be double-processed (moved once via the base's variant sweep,
        // not once as its own "base" too).
        $this->assertSame(['img_x.jpg' => 'img_x.jpg'], $map);
        $this->assertArrayNotHasKey('img_x-resized-100.jpg', $map);
        $this->assertFileExists($this->tempRoot . '/6/img_x-resized-100.jpg');
        // Only one copy of the variant landed: '.', '..', img_x.jpg, and
        // img_x-resized-100.jpg — no duplicate/renamed second copy from being
        // treated as its own base.
        $this->assertCount(4, scandir($this->tempRoot . '/6'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function traversalAndMalformedFilenameProvider(): array
    {
        return [
            'parent traversal' => ['../../etc/passwd.jpg'],
            'null byte' => ["img_\x00evil.jpg"],
            'absolute path' => ['/etc/passwd'],
        ];
    }

    #[DataProvider('traversalAndMalformedFilenameProvider')]
    public function testRelocateRejectsTraversalAndMalformedFilenames(string $malformed): void
    {
        $this->writeFile($this->tempRoot . '/5/img_x.jpg', 'base');

        $this->expectException(ImageProcessingException::class);

        try {
            $this->relocator()->relocate(5, 6, [$malformed]);
        } finally {
            // Nothing was moved: the legitimate file is still exactly where it
            // was, and no target directory was created at all.
            $this->assertFileExists($this->tempRoot . '/5/img_x.jpg');
            $this->assertDirectoryDoesNotExist($this->tempRoot . '/6');
        }
    }

    public function testRelocateWithAssociativeArrayIteratesValuesIgnoringKeys(): void
    {
        $this->writeFile($this->tempRoot . '/5/img_x.jpg', 'base');

        // array type hints do not enforce list-ness — a caller-assembled
        // associative array must still behave correctly.
        $map = $this->relocator()->relocate(5, 6, ['first' => 'img_x.jpg']);

        $this->assertSame(['img_x.jpg' => 'img_x.jpg'], $map);
        $this->assertFileExists($this->tempRoot . '/6/img_x.jpg');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function wrongTypedValueProvider(): array
    {
        return [
            'integer' => [123],
            'null' => [null],
            'boolean true' => [true],
            'nested array' => [['nested']],
        ];
    }

    /**
     * `cars.image` is a JSON column read at runtime from a DB row; nothing
     * about PHP's `array` type hint on $sourceBaseFilenames prevents a
     * corrupted or hand-edited row from producing an int, null, bool, or
     * nested array as one of its elements. Such an element must be rejected
     * through the class's documented @throws contract, not escape as a raw
     * \TypeError from the strictly-typed
     * CarImageProcessor::isSafeFilename(string $filename) it would otherwise
     * reach. PHPStan cannot catch this: the array shape is assembled at
     * runtime from the database, not at a call site it can analyse.
     */
    #[DataProvider('wrongTypedValueProvider')]
    public function testRelocateRejectsWrongTypedFilenameWithDocumentedException(mixed $badValue): void
    {
        $this->writeFile($this->tempRoot . '/5/img_x.jpg', 'base');

        $this->expectException(ImageProcessingException::class);

        try {
            $this->relocator()->relocate(5, 6, [$badValue]);
        } finally {
            // Rejection must happen before anything moves: the source file is
            // untouched and no target directory was created.
            $this->assertFileExists($this->tempRoot . '/5/img_x.jpg');
            $this->assertDirectoryDoesNotExist($this->tempRoot . '/6');
        }
    }

    public function testRelocateWithSelfMergeIsNoOpAndNeverDeletesTheDirectory(): void
    {
        $this->writeFile($this->tempRoot . '/5/img_x.jpg', 'base');

        $map = $this->relocator()->relocate(5, 5, ['img_x.jpg']);

        $this->assertSame([], $map, 'a degenerate self-merge must not report any files as relocated');
        $this->assertDirectoryExists($this->tempRoot . '/5', 'the directory must never be deleted when source === target');
        $this->assertFileExists($this->tempRoot . '/5/img_x.jpg', 'the file must survive a self-merge untouched');
    }

    /**
     * Regression: an unreadable source directory must abort the merge, not
     * quietly move the base file and abandon every variant.
     *
     * A directory at mode 0300 is writable and executable but not readable, so
     * rename() of a known filename still succeeds while the variants cannot be
     * enumerated. This was previously detected by testing glob() for a `false`
     * return, which glob() never produces for an unreadable directory (it
     * returns `[]`, indistinguishable from a genuine no-match) — so the guard
     * was unreachable and the variants were silently left behind on a car row
     * that the merge then deleted.
     */
    public function testUnreadableSourceDirectoryAbortsInsteadOfAbandoningVariants(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Running as root bypasses filesystem permission bits.');
        }

        $this->writeFile($this->tempRoot . '/5/img_x.jpg', 'base');
        $this->writeFile($this->tempRoot . '/5/img_x-resized-100.jpg', 'variant');

        // Writable and executable, but not readable: rename() works, glob() cannot see.
        chmod($this->tempRoot . '/5', 0300);

        try {
            $threw = false;
            try {
                $this->relocator()->relocate(5, 6, ['img_x.jpg']);
            } catch (ImageProcessingException) {
                $threw = true;
            }

            $this->assertTrue(
                $threw,
                'relocate() must throw when the source directory cannot be listed, '
                . 'rather than reporting success while abandoning the variants'
            );
        } finally {
            if (is_dir($this->tempRoot . '/5')) {
                chmod($this->tempRoot . '/5', 0700);
            }
        }
    }

    /**
     * Regression: restore() must not claim a full recovery when an unreadable
     * target directory hides variants it therefore could not move back.
     *
     * restore()'s contract is that an empty return proves the filesystem was
     * put back — CarAdministrationService::merge() logs a stranded-files
     * warning only when the return is non-empty, so a false empty means the
     * operator is never told.
     */
    public function testRestoreReportsUnrestoredWhenTargetDirectoryCannotBeListed(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Running as root bypasses filesystem permission bits.');
        }

        $this->writeFile($this->tempRoot . '/6/img_x.jpg', 'base');
        $this->writeFile($this->tempRoot . '/6/img_x-resized-100.jpg', 'variant');
        mkdir($this->tempRoot . '/5', 0700, true);

        chmod($this->tempRoot . '/6', 0300);

        try {
            $unrestored = $this->relocator()->restore(5, 6, ['img_x.jpg' => 'img_x.jpg']);

            $this->assertNotSame(
                [],
                $unrestored,
                'restore() must report the entry when it cannot enumerate the variants it left behind'
            );
        } finally {
            if (is_dir($this->tempRoot . '/6')) {
                chmod($this->tempRoot . '/6', 0700);
            }
        }
    }
}
