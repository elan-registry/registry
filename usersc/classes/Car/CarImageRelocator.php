<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\Exceptions\ImageProcessingException;
use ElanRegistry\UploadPathGuard;

/**
 * Moves a car's image files from one per-car directory to another when two car
 * records are merged, and moves them back again if the surrounding database
 * transaction fails.
 *
 * The filesystem cannot join a database transaction, so a merge is a saga: the
 * files move inside the open transaction and restore() is the compensating
 * action run before rollback().
 *
 * The reversibility that gives a caller is split across the two exits, because
 * a caller only ever receives relocate()'s map when relocate() returns:
 *
 * - On success, the returned map names every base file that moved, and
 *   restore() reverses exactly those moves.
 * - On failure, no map reaches the caller at all, so relocate() compensates
 *   for itself before re-throwing: it restores the partial map it built plus
 *   any base file caught mid-move, leaving the caller nothing to undo. A
 *   caller's `restore(..., [])` on that path is therefore correct rather than
 *   merely harmless.
 *
 * Neither path is *guaranteed* reversal. restore() is best-effort by design —
 * it must not throw over the exception that triggered the rollback — so a move
 * back that the filesystem refuses leaves files stranded in the target
 * directory. On the success path restore() reports those entries to its caller;
 * on the self-compensating failure path there is no channel to report them
 * alongside the original exception, and they are dropped.
 *
 * The image base directory arrives as a constructor argument rather than being
 * re-derived from ELAN_IMAGE_DIR internally. That keeps the class free of any
 * framework dependency (no globals, no logger(), no $db) so it can be unit
 * tested against a real temp directory — moves are the whole point of the
 * class, and mocking them away would test nothing. See #1943 for the shared
 * path helper that will eventually feed this argument.
 *
 * Thumbnail regeneration is deliberately out of scope: whatever
 * `-resized-{size}` variants exist on disk move alongside their base file, and
 * a variant that is missing is not an error. A missing variant is a
 * pre-existing defect of that car's images, unrelated to merging (#1870).
 *
 * @issue 1867
 */
class CarImageRelocator
{
    /**
     * Resized variants are named `{basename}-resized-{size}.{ext}`. Sizes are
     * not enumerated here because the configured set has changed over time and
     * older cars still hold variants at sizes no longer generated — matching
     * the shape rather than a size list moves those too.
     */
    private const VARIANT_GLOB_SUFFIX = '-resized-*.';

    /**
     * @param string $imageBaseDirectory Absolute filesystem path to the
     *                                   `userimages/` root that contains the
     *                                   per-car directories.
     */
    public function __construct(private string $imageBaseDirectory) {}

    /**
     * Move every file belonging to the listed base filenames from the source
     * car's directory to the target car's directory, renaming on collision,
     * and remove the emptied source directory.
     *
     * A base filename whose destination is already taken is given a fresh name
     * from CarImageProcessor::generateSecureFilename(); all of that base's
     * variants are renamed to match, so the derived `-resized-{size}` naming
     * stays consistent with the base recorded in `cars.image`.
     *
     * On any failure this method undoes its own partial work before the
     * exception leaves it, so a caller that never received a map has nothing
     * left to compensate for. See the class docblock.
     *
     * @param int          $sourceCarId         Car whose directory is emptied.
     * @param int          $targetCarId         Car whose directory receives the files.
     * @param array<array-key, mixed> $sourceBaseFilenames Base filenames from the
     *                                          source car's `cars.image`. Values are read,
     *                                          keys ignored; resized-variant names are
     *                                          ignored because variants move with their base.
     *                                          Typed `mixed` because the column is decoded
     *                                          at runtime — a non-string entry is rejected,
     *                                          not assumed away.
     * @return array<string, string> Map of old base filename => new base filename,
     *                               covering only base files that were actually
     *                               moved, for the caller to build the target's
     *                               `cars.image` and to hand back to restore().
     *                               A listed filename with no file on disk is
     *                               omitted. Empty when the source directory does
     *                               not exist or held none of the listed files.
     * @throws ImageProcessingException If a path fails the traversal guard, the
     *                                  target directory cannot be created, or a
     *                                  file cannot be moved. Files already moved
     *                                  are restored first, on a best-effort
     *                                  basis, before the exception propagates.
     */
    public function relocate(int $sourceCarId, int $targetCarId, array $sourceBaseFilenames): array
    {
        $sourceDirectory = $this->carDirectory($sourceCarId);
        $targetDirectory = $this->carDirectory($targetCarId);

        // A source car that never had images merges exactly as it did before
        // this class existed: nothing to move, nothing to compensate.
        if (!is_dir($sourceDirectory)) {
            return [];
        }

        // Moving a directory into itself would "succeed" file by file and then
        // delete the directory holding the only copies. Refuse instead.
        if ($sourceCarId === $targetCarId) {
            return [];
        }

        $this->assertWithinBase($sourceDirectory);
        $this->assertWithinBase($targetDirectory);

        $baseFilenames = $this->filterBaseFilenames($sourceBaseFilenames);

        $renameMap = [];
        $targetDirectoryReady = false;

        // The name pair currently mid-move. A base file can be physically moved
        // while its variants are not (or the reverse), and that half-moved pair
        // is not yet in $renameMap — so the compensation below needs it
        // separately or the moved base would be stranded with nothing recording
        // where it went.
        $inFlight = null;

        try {
            foreach ($baseFilenames as $baseFilename) {
                // A base filename listed in cars.image whose file is already gone
                // (the #1629 defect class) is skipped entirely rather than reported.
                // The map is the caller's instruction for what to append to the
                // SURVIVING car's cars.image, so naming a file that moved nothing
                // would manufacture a fresh dangling reference on a car that never
                // had one. The merge itself still succeeds.
                if (!is_file($sourceDirectory . DIRECTORY_SEPARATOR . $baseFilename)) {
                    continue;
                }

                // Creation is deferred to the first file actually being moved, so a
                // call that moves nothing leaves no empty directory behind.
                if (!$targetDirectoryReady) {
                    $this->ensureDirectoryExists($targetDirectory);
                    // Re-check after creation: realpath() only resolves an existing
                    // path, so the guard above could not have inspected a target
                    // directory that did not yet exist.
                    $this->assertWithinBase($targetDirectory);
                    $targetDirectoryReady = true;
                }

                $newBaseFilename = $this->resolveDestinationName($targetDirectory, $baseFilename);

                $inFlight = [$baseFilename, $newBaseFilename];

                $this->moveFile(
                    $sourceDirectory . DIRECTORY_SEPARATOR . $baseFilename,
                    $targetDirectory . DIRECTORY_SEPARATOR . $newBaseFilename
                );

                $this->moveVariants($sourceDirectory, $targetDirectory, $baseFilename, $newBaseFilename);

                $renameMap[$baseFilename] = $newBaseFilename;
                $inFlight = null;
            }
        } catch (\Throwable $e) {
            // Nothing outside this method can compensate for a mid-loop failure:
            // relocate() returns its map only on normal completion, so a caller's
            // catch block holds an empty map and its restore() call would be a
            // no-op while every already-moved file sits in the target directory.
            // Undo our own partial work here instead, then let the original
            // exception continue to the caller unchanged.
            if ($inFlight !== null) {
                $renameMap[$inFlight[0]] = $inFlight[1];
            }

            // restore() is best-effort and never throws, so it cannot mask $e.
            // Its return value — the entries it could not put back — is
            // deliberately dropped: the caller is about to receive $e, which is
            // the actionable failure, and there is no channel to report both.
            // The stranded files it describes are the pre-existing worst case,
            // not one this compensation introduced.
            $this->restore($sourceCarId, $targetCarId, $renameMap);

            throw $e;
        }

        // Removing the source directory is unconditional — an empty source
        // directory is still an artifact of a car that no longer exists.
        $this->removeDirectoryIfEmpty($sourceDirectory);

        return $renameMap;
    }

    /**
     * Compensating inverse of relocate(): move the relocated files back to the
     * source car's directory under their original names and recreate that
     * directory.
     *
     * Takes relocate()'s return value verbatim so a caller's catch block can
     * hand back exactly what it received. Safe to call when relocate() did
     * nothing (an empty map is a no-op) and best-effort per file: a file that
     * is already missing is skipped rather than throwing, because this runs on
     * an error path where throwing again would mask the original failure.
     *
     * Never throwing does not mean never failing. A move back can be refused
     * for reasons this class cannot fix — the source directory cannot be
     * recreated, a path no longer resolves inside the image base, rename()
     * fails with EXDEV/EACCES/ENOSPC — and the result is files stranded in the
     * target directory while the database rolls back cleanly. The caller is the
     * only party that can log or escalate that, so the unrestored entries are
     * returned rather than swallowed. An empty return is the only proof the
     * filesystem was actually put back.
     *
     * @param int                   $sourceCarId Car the files came from.
     * @param int                   $targetCarId Car the files were moved to.
     * @param array<string, string> $renameMap   relocate()'s old => new base filename map.
     * @return array<string, string> Entries that could NOT be restored, in the
     *                               same old => new shape as $renameMap. Empty
     *                               on full success.
     */
    public function restore(int $sourceCarId, int $targetCarId, array $renameMap): array
    {
        if ($renameMap === [] || $sourceCarId === $targetCarId) {
            return [];
        }

        $sourceDirectory = $this->carDirectory($sourceCarId);
        $targetDirectory = $this->carDirectory($targetCarId);

        // Nothing was relocated into a directory that does not exist, but the
        // map says otherwise — report the whole map rather than claiming a
        // restore that never happened.
        if (!is_dir($targetDirectory)) {
            return $renameMap;
        }

        if (!$this->tryEnsureDirectoryExists($sourceDirectory)
            || !UploadPathGuard::isWithinTarget($this->imageBaseDirectory, $sourceDirectory)
            || !UploadPathGuard::isWithinTarget($this->imageBaseDirectory, $targetDirectory)
        ) {
            return $renameMap;
        }

        $unrestored = [];

        foreach ($renameMap as $originalBaseFilename => $relocatedBaseFilename) {
            // A base file left behind and a variant left behind are both
            // failures of the same promise: an empty return means the
            // filesystem was put back. Report either as an unrestored entry.
            $baseMoved = $this->tryMoveFile(
                $targetDirectory . DIRECTORY_SEPARATOR . $relocatedBaseFilename,
                $sourceDirectory . DIRECTORY_SEPARATOR . $originalBaseFilename
            );

            $variantsMoved = $this->restoreVariants(
                $targetDirectory,
                $sourceDirectory,
                $relocatedBaseFilename,
                $originalBaseFilename
            );

            if (!$baseMoved || !$variantsMoved) {
                $unrestored[$originalBaseFilename] = $relocatedBaseFilename;
            }
        }

        return $unrestored;
    }

    /**
     * Reduce caller-supplied filenames to the distinct, safe, non-variant base
     * names this class will act on.
     *
     * `cars.image` is a JSON column: a corrupted or hand-edited row could carry
     * traversal segments or null bytes, so nothing from it is trusted. Variant
     * names are dropped rather than rejected because variants are derived —
     * processing one as its own base would move it twice and record a
     * non-base name in `cars.image`.
     *
     * @param array<array-key, mixed> $filenames Raw filenames from `cars.image`.
     * @return list<string> Distinct base filenames, in their original order.
     * @throws ImageProcessingException If an entry is not a string, or fails
     *                                  isSafeFilename().
     */
    private function filterBaseFilenames(array $filenames): array
    {
        $baseFilenames = [];

        foreach ($filenames as $filename) {
            // `cars.image` is JSON decoded at runtime, so a corrupted or
            // hand-edited row can yield a non-string element. Reject it as
            // unsafe input rather than letting it reach the strictly-typed
            // isSafeFilename() as an uncaught \TypeError, which would bypass
            // this method's documented failure mode.
            if (!is_string($filename)) {
                throw new ImageProcessingException(
                    'Refusing to relocate a non-string image filename.'
                );
            }

            if (!CarImageProcessor::isSafeFilename($filename)) {
                throw new ImageProcessingException(
                    'Refusing to relocate an unsafe image filename.'
                );
            }

            if (CarImageProcessor::isResizedVariant($filename)) {
                continue;
            }

            if (!in_array($filename, $baseFilenames, true)) {
                $baseFilenames[] = $filename;
            }
        }

        return $baseFilenames;
    }

    /**
     * Pick the name the base file will take in the target directory, keeping a
     * name already present there untouched.
     *
     * The regenerated name must be free for the base *and* every variant it
     * will carry, so a partially occupied name is retried rather than accepted.
     *
     * @param string $targetDirectory Absolute path to the receiving car directory.
     * @param string $baseFilename    Base filename as it exists in the source directory.
     * @return string Filename to use in the target directory.
     * @throws ImageProcessingException If no free name can be generated.
     */
    private function resolveDestinationName(string $targetDirectory, string $baseFilename): string
    {
        if (!file_exists($targetDirectory . DIRECTORY_SEPARATOR . $baseFilename)) {
            return $baseFilename;
        }

        $extension = $this->replacementExtension($baseFilename);

        // random_bytes(16) makes a repeat collision vanishingly unlikely; the
        // bounded retry exists so a filesystem fault cannot spin forever.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = CarImageProcessor::generateSecureFilename($extension);

            if (!file_exists($targetDirectory . DIRECTORY_SEPARATOR . $candidate)) {
                return $candidate;
            }
        }

        throw new ImageProcessingException(
            'Unable to generate a free filename for the relocated image.'
        );
    }

    /**
     * Map a stored filename's extension onto one generateSecureFilename() will
     * accept, for use in a collision replacement name.
     *
     * The two allowlists are deliberately asymmetric: isSafeFilename() accepts
     * ALLOWED_EXTENSIONS plus `jpeg`, case-insensitively, because legacy
     * `cars.image` rows hold `.jpeg` and `.JPG` names, while
     * generateSecureFilename() throws for anything outside ALLOWED_EXTENSIONS
     * so it can never mint a name the rest of the system would reject. This
     * method is the bridge between them, and it is the only correct place for
     * one: widening ALLOWED_EXTENSIONS would let new uploads be written as
     * `.jpeg`, and narrowing isSafeFilename() would make existing rows
     * unreadable.
     *
     * Renaming is safe here because only the name changes — the moved file
     * keeps its bytes — so a legacy `photo.jpeg` becoming `img_<hex>.jpg` is
     * both correct and an improvement. `jpeg` maps to `jpg` explicitly; every
     * other accepted extension already differs from an allowed one only by
     * case.
     *
     * @param string $baseFilename Base filename as stored, already cleared by
     *                             isSafeFilename().
     * @return string An extension present in CarImageProcessor::ALLOWED_EXTENSIONS.
     * @throws ImageProcessingException If the extension has no allowed
     *                                  equivalent — unreachable for a filename
     *                                  isSafeFilename() accepted, and kept as a
     *                                  fail-closed guard should the two lists
     *                                  diverge again.
     */
    private function replacementExtension(string $baseFilename): string
    {
        $extension = strtolower(pathinfo($baseFilename, PATHINFO_EXTENSION));

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        if (!in_array($extension, CarImageProcessor::ALLOWED_EXTENSIONS, true)) {
            throw new ImageProcessingException(
                'Cannot generate a replacement name for an unsupported image extension.'
            );
        }

        return $extension;
    }

    /**
     * Move every `-resized-{size}` variant of a base file, renaming each to
     * follow the base file's (possibly regenerated) name.
     *
     * Variants are located by glob rather than by enumerating configured sizes
     * so variants at sizes no longer generated still move.
     *
     * @param string $sourceDirectory  Absolute path to the emptying car directory.
     * @param string $targetDirectory  Absolute path to the receiving car directory.
     * @param string $oldBaseFilename  Base filename in the source directory.
     * @param string $newBaseFilename  Base filename in the target directory.
     * @return void
     * @throws ImageProcessingException If a variant cannot be moved.
     */
    private function moveVariants(
        string $sourceDirectory,
        string $targetDirectory,
        string $oldBaseFilename,
        string $newBaseFilename
    ): void {
        $newStem = pathinfo($newBaseFilename, PATHINFO_FILENAME);

        foreach ($this->findVariants($sourceDirectory, $oldBaseFilename) as $variantFilename) {
            $suffix = $this->variantSuffix($oldBaseFilename, $variantFilename);

            if ($suffix === null) {
                continue;
            }

            $this->moveFile(
                $sourceDirectory . DIRECTORY_SEPARATOR . $variantFilename,
                $targetDirectory . DIRECTORY_SEPARATOR . $newStem . $suffix
            );
        }
    }

    /**
     * Inverse of moveVariants(), used on the compensation path where a missing
     * file is skipped rather than treated as an error.
     *
     * Reports whether every variant made it back, because restore()'s contract
     * is that an empty return proves the filesystem was put back. An unreadable
     * target directory yields no variants to enumerate, and silently treating
     * that as "nothing to do" would let restore() claim a full recovery while
     * leaving variants stranded in the surviving car's directory.
     *
     * @param string $targetDirectory Absolute path to the directory holding the relocated files.
     * @param string $sourceDirectory Absolute path to the directory being restored.
     * @param string $relocatedBaseFilename Base filename as relocated.
     * @param string $originalBaseFilename  Base filename to restore to.
     * @return bool True when every variant of this base was moved back.
     */
    private function restoreVariants(
        string $targetDirectory,
        string $sourceDirectory,
        string $relocatedBaseFilename,
        string $originalBaseFilename
    ): bool {
        // An unreadable directory cannot be enumerated, so variants that exist
        // are invisible here rather than absent — never report success for it.
        if (!is_readable($targetDirectory)) {
            return false;
        }

        $originalStem = pathinfo($originalBaseFilename, PATHINFO_FILENAME);
        $allMoved = true;

        foreach ($this->findVariants($targetDirectory, $relocatedBaseFilename, false) as $variantFilename) {
            $suffix = $this->variantSuffix($relocatedBaseFilename, $variantFilename);

            if ($suffix === null) {
                continue;
            }

            if (!$this->tryMoveFile(
                $targetDirectory . DIRECTORY_SEPARATOR . $variantFilename,
                $sourceDirectory . DIRECTORY_SEPARATOR . $originalStem . $suffix
            )) {
                $allMoved = false;
            }
        }

        return $allMoved;
    }

    /**
     * List the resized-variant filenames of one base file in a directory.
     *
     * An unlistable directory and a base file with no variants must not be
     * confused: the latter is the common, harmless case, while the former means
     * the set of variants is unknown. Collapsing them would let the forward path
     * move base files — rename() needs write permission on the parent, not read
     * permission on the directory — while silently abandoning every variant and
     * reporting success. Hence $strict: the forward path treats an unreadable
     * directory as fatal so the merge compensates and rolls back; the
     * compensation path, which must not throw, degrades to moving what it can.
     *
     * Readability is asserted with is_readable() rather than inferred from
     * glob()'s return. glob() does NOT report an unreadable directory: verified
     * on PHP 8.5/Darwin, a directory at mode 0300 (writable and executable but
     * not readable) yields `[]`, not `false`, even with GLOB_ERR — identical to
     * a genuine no-match. Reading the failure off glob() therefore made the
     * $strict branch unreachable and produced exactly the silent variant
     * abandonment described above.
     *
     * @param string $directory    Absolute path to search.
     * @param string $baseFilename Base filename whose variants are wanted.
     * @param bool   $strict       True on the forward path (throw when the
     *                             directory cannot be listed), false on the
     *                             compensation path (treat it as no variants).
     * @return list<string> Variant filenames (basenames only), possibly empty.
     * @throws ImageProcessingException If $strict and the directory cannot be listed.
     */
    private function findVariants(string $directory, string $baseFilename, bool $strict = true): array
    {
        if (!is_readable($directory)) {
            if ($strict) {
                throw new ImageProcessingException(
                    'Failed to list resized image variants while relocating car images.'
                );
            }

            return [];
        }

        $stem = pathinfo($baseFilename, PATHINFO_FILENAME);
        $extension = pathinfo($baseFilename, PATHINFO_EXTENSION);

        // The stem comes from a filename already cleared by isSafeFilename(),
        // so it holds no glob metacharacters to escape.
        $matches = glob(
            $directory . DIRECTORY_SEPARATOR . $stem . self::VARIANT_GLOB_SUFFIX . $extension
        );

        if ($matches === false) {
            if ($strict) {
                throw new ImageProcessingException(
                    'Failed to list resized image variants while relocating car images.'
                );
            }

            return [];
        }

        return array_values(array_map('basename', $matches));
    }

    /**
     * Extract the `-resized-{size}.{ext}` tail that distinguishes a variant
     * from its base, so the same tail can be reattached to a new base name.
     *
     * @param string $baseFilename    Base filename the variant derives from.
     * @param string $variantFilename Candidate variant filename.
     * @return string|null The tail, or null when $variantFilename is not a
     *                     variant of $baseFilename.
     */
    private function variantSuffix(string $baseFilename, string $variantFilename): ?string
    {
        $stem = pathinfo($baseFilename, PATHINFO_FILENAME);

        if (!str_starts_with($variantFilename, $stem)) {
            return null;
        }

        $suffix = substr($variantFilename, strlen($stem));

        return CarImageProcessor::isResizedVariant($variantFilename) ? $suffix : null;
    }

    /**
     * Move one file, treating any failure as fatal so the caller can compensate.
     *
     * A source file that is absent is not an error. Variants are optional by
     * definition, and a base file could vanish between relocate()'s is_file()
     * check and this call; neither should abort an otherwise valid merge.
     *
     * @param string $from Absolute source path.
     * @param string $to   Absolute destination path.
     * @return void
     * @throws ImageProcessingException If the file exists but cannot be moved.
     */
    private function moveFile(string $from, string $to): void
    {
        if (!is_file($from)) {
            return;
        }

        // rename() overwrites the destination silently on POSIX. The base
        // filename is already collision-checked by resolveDestinationName(),
        // but a variant is not: a target directory holding an orphaned
        // `{stem}-resized-{size}.{ext}` whose base is gone (the #1629 defect
        // class) would have that variant destroyed. Abort instead so the merge
        // compensates and rolls back — losing a file is the exact failure this
        // relocation exists to prevent.
        if (file_exists($to)) {
            throw new ImageProcessingException(
                'Refusing to overwrite an existing file while relocating car images.'
            );
        }

        // Warning suppressed: the false return is checked and rethrown as a
        // typed exception, so PHP's own warning adds nothing but noise to the
        // test output. Matches tryMoveFile()/ensureDirectory() below.
        if (!@rename($from, $to)) {
            throw new ImageProcessingException('Failed to relocate a car image file.');
        }
    }

    /**
     * Best-effort move for the compensation path, where throwing would mask
     * the original failure that triggered the rollback.
     *
     * The failure is reported rather than thrown so restore() can accumulate
     * what it could not put back. A destination that already holds a file is a
     * failure, not a success: this method refuses to overwrite, so the file at
     * $from is still stranded where it does not belong.
     *
     * @param string $from Absolute source path.
     * @param string $to   Absolute destination path.
     * @return bool True when the file is at $to afterwards — including the case
     *              where $from was already gone, since there is then nothing
     *              stranded to report.
     */
    private function tryMoveFile(string $from, string $to): bool
    {
        if (!is_file($from)) {
            return true;
        }

        if (file_exists($to)) {
            return false;
        }

        return @rename($from, $to);
    }

    /**
     * Absolute path to one car's image directory.
     *
     * @param int $carId Car ID.
     * @return string Absolute directory path, without a trailing separator.
     */
    private function carDirectory(int $carId): string
    {
        return rtrim($this->imageBaseDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . $carId;
    }

    /**
     * Fail closed unless a car directory resolves inside the configured image
     * base directory.
     *
     * UploadPathGuard::isWithinTarget() checks realpath(dirname($path)), so for
     * `{base}/{carId}` it resolves to `{base}` itself — equality is the
     * expected outcome here, not a near miss.
     *
     * @param string $directory Absolute car directory path.
     * @return void
     * @throws ImageProcessingException If the path resolves outside the base directory.
     */
    private function assertWithinBase(string $directory): void
    {
        if (!UploadPathGuard::isWithinTarget($this->imageBaseDirectory, $directory)) {
            throw new ImageProcessingException(
                'Refusing to relocate images outside the configured image directory.'
            );
        }
    }

    /**
     * @param string $directory Absolute directory path to create if absent.
     * @return void
     * @throws ImageProcessingException If the directory cannot be created.
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (!$this->tryEnsureDirectoryExists($directory)) {
            throw new ImageProcessingException(
                'Failed to create the destination car image directory.'
            );
        }
    }

    /**
     * @param string $directory Absolute directory path to create if absent.
     * @return bool True when the directory exists afterwards.
     */
    private function tryEnsureDirectoryExists(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        // The is_dir() re-check absorbs a concurrent creator losing the race.
        return @mkdir($directory, 0755, true) || is_dir($directory);
    }

    /**
     * Remove a directory once its files have moved out.
     *
     * A directory still holding files (an unmoved variant, or a stray file this
     * merge never knew about) is left in place rather than force-emptied — data
     * loss here would be silent and unrecoverable.
     *
     * @param string $directory Absolute directory path.
     * @return void
     */
    private function removeDirectoryIfEmpty(string $directory): void
    {
        $entries = @scandir($directory);

        if ($entries === false || array_diff($entries, ['.', '..']) !== []) {
            return;
        }

        @rmdir($directory);
    }
}
