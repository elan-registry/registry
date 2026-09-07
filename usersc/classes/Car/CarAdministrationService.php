<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\AppConstants;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarDeletionException;
use ElanRegistry\Exceptions\CarException;
use ElanRegistry\Exceptions\CarMergeException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ImageProcessingException;
use ElanRegistry\Exceptions\OwnerDatabaseException;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;
use ElanRegistry\Car\CarValidator;

/**
 * CarAdministrationService - Administrative operations for cars
 *
 * Extracted from Car.php to provide focused, testable admin operation logic.
 * Handles car deletion, ownership transfer, and car merging with full
 * transaction support and audit trails.
 *
 * @package ElanRegistry\Car
 * @since v2.15.0
 * @see https://github.com/unibrain1/elanregistry/issues/463
 */
class CarAdministrationService
{
    /**
     * Columns on `cars` that mirror the current owner's identity, and must
     * therefore be overwritten wholesale on an ownership change — including
     * being cleared when the new owner has no value. See
     * withBlankedFieldsRestored(). `user_id` and `join_date` are excluded:
     * they are never blank on a valid target.
     *
     * @var list<string>
     */
    private const OWNER_IDENTITY_FIELDS = [
        'email', 'fname', 'lname', 'city', 'state', 'country', 'lat', 'lon', 'website',
    ];

    private const OPERATION_MERGE = 'MERGE';

    /**
     * The `noowner` system account's unroutable address, kept in sync with
     * RegisterNoownerAccount::EMAIL. Used only to distinguish the expected
     * blanking case from a real account with a malformed email — see
     * contactableEmail(). Duplicated as a literal rather than imported: the
     * migration is not autoloaded, and this const going stale can at worst add
     * a spurious log line, never change behavior.
     */
    private const SYSTEM_ACCOUNT_EMAIL = 'noowner@invalid';

    /**
     * The `noowner` system account's username, kept in sync with
     * RegisterNoownerAccount::USERNAME and the lookup in
     * usersc/scripts/after_user_deletion.php. Unlike SYSTEM_ACCOUNT_EMAIL this
     * one does change behavior: transfer() keys the solddate decision on it, so
     * if it drifts from the migration's value, `noowner` reassignments will
     * start clearing solddate.
     */
    private const SYSTEM_ACCOUNT_USERNAME = 'noowner';

    /**
     * Moves image files between per-car directories during merge(). Injectable
     * so tests can point it at a temp directory: the relocator performs real
     * filesystem moves, which is the whole point of the class and cannot be
     * mocked away meaningfully.
     */
    private CarImageRelocator $relocator;

    /**
     * @param CarImageRelocator|null $relocator Relocator to use for merge()'s
     *        image moves. Defaults to one rooted at the application's real
     *        `userimages/` directory, so existing call sites need no argument.
     *        The base path is resolved here rather than inside CarImageRelocator
     *        to keep that class free of framework globals (see #1943).
     */
    public function __construct(?CarImageRelocator $relocator = null)
    {
        if ($relocator === null) {
            global $abs_us_root, $us_url_root;
            $relocator = new CarImageRelocator(
                ($abs_us_root ?? '') . ($us_url_root ?? '') . ELAN_IMAGE_DIR
            );
        }

        $this->relocator = $relocator;
    }

    /**
     * Delete a car and all associated records
     *
     * @param object $carData Car data object
     * @param string $reason Reason for deletion (for audit trail)
     * @param int $adminUserId ID of the admin performing the deletion
     * @param CarRepository $repo Repository for database operations
     * @return bool True if deletion was successful
     * @throws CarDatabaseException If database operation fails
     * @throws CarDeletionException If deletion operation fails
     */
    public function delete(
        object $carData,
        string $reason,
        int $adminUserId,
        CarRepository $repo
    ): bool {
        $carId = (int) $carData->id;
        $chassis = $carData->chassis ?? 'Unknown';

        try {
            $repo->beginTransaction();

            if (!$repo->deleteCar($carId)) {
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_DELETION, 'Database update failed: query returned false');
                throw new CarDatabaseException('Database update failed - check system logs for details.');
            }

            $repo->commit();
            logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_DELETION, "Car ID $carId ($chassis) permanently deleted. Reason: $reason");
            return true;

        } catch (\Throwable $e) {
            $repo->rollback();
            if ($e instanceof CarException) {
                throw $e;
            }
            logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_DELETION, 'Car deletion failed: ' . $e->getMessage());
            throw new CarDeletionException('Operation failed - check system logs for technical details.');
        }
    }

    /**
     * Transfer car ownership to a different user
     *
     * @param object $carData Car data object
     * @param int $newUserId The user ID to transfer ownership to
     * @param string $reason Reason for transfer (for audit trail)
     * @param string $operationType Operation type (e.g., 'NEWOWNER', 'TRANSFER')
     * @param int $adminUserId ID of the admin performing the transfer
     * @param CarRepository $repo Repository for database operations
     * @param DatabaseInterface $db Database instance used to look up the target owner
     * @return true Always returns true; throws on any failure.
     * @throws CarValidationException If target user is invalid
     * @throws CarDatabaseException If database operation fails
     * @throws OwnerDatabaseException If the target-user lookup itself fails
     *         due to a DB error — thrown from the `new Owner($newUserId, $db)`
     *         lookup below, before the transaction begins. Relies on callers'
     *         existing broad (`\Throwable`/`CarException`) catches; all three
     *         current production callers already satisfy this.
     *
     * Note: a transfer is not a re-attestation, so `owner_last_updated` is
     * intentionally left untouched here.
     */
    public function transfer(
        object $carData,
        int $newUserId,
        string $reason,
        string $operationType,
        int $adminUserId,
        CarRepository $repo,
        DatabaseInterface $db
    ): true {
        $targetUser = (new Owner($newUserId, $db))->data();
        if (!$targetUser) {
            logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_TRANSFER, 'Target user not found - cannot transfer ownership to user ID: ' . $newUserId);
            throw new CarValidationException('Unable to transfer ownership: the target user account is not valid.');
        }

        $carId = (int) $carData->id;

        // System accounts (notably `noowner`, the GDPR reassignment target) carry a
        // deliberately unroutable address so they can never be reached by password
        // reset or passwordless login — see RegisterNoownerAccount. Denormalizing
        // that address onto the car and its history rows would both fail
        // CarValidator's format check and store a junk contact address on every
        // reassigned car. A car with no reachable owner correctly has no owner
        // email; contact flows resolve the owner through `user_id`, not this column.
        $targetEmail = $this->contactableEmail($targetUser->email ?? '', $adminUserId, $carId);

        // A sale does not survive a change of owner, so solddate is cleared on
        // any transfer to a real owner regardless of $operationType. Reassignment
        // to the system account (GDPR deletion, admin "no owner") is not a change
        // of owner, so the sold state is kept (#1878).
        $isSystemAccount = ($targetUser->username ?? '') === self::SYSTEM_ACCOUNT_USERNAME;
        $soldDate = $isSystemAccount ? ($carData->solddate ?? null) : null;

        // email_bounced is a property of the previous owner's address, not the
        // car — cleared on a real-owner transfer (see below) and preserved on a
        // system-account reassignment, mirroring solddate's treatment.
        $emailBounced = $isSystemAccount ? (int) ($carData->email_bounced ?? 0) : 0;

        try {
            $repo->beginTransaction();

            $ownerFields = [
                'mtime'     => date(AppConstants::DATETIME_FORMAT),
                'user_id'   => $targetUser->id,
                'email'     => $targetEmail,
                'fname'     => $targetUser->fname    ?? '',
                'lname'     => $targetUser->lname    ?? '',
                'join_date' => $targetUser->join_date ?? date(AppConstants::DATETIME_FORMAT),
                'city'      => $targetUser->city     ?? '',
                'state'     => $targetUser->state    ?? '',
                'country'   => $targetUser->country  ?? '',
                'lat'       => $targetUser->lat      ?? null,
                'lon'       => $targetUser->lon      ?? null,
                'website'   => $targetUser->website  ?? '',
            ];
            // Ordinary transfer: clear it. For the system account the key is omitted
            // entirely — DB::update() writes only the keys given, so the stored value
            // is untouched; writing null here would erase it.
            if (!$isSystemAccount) {
                $ownerFields['solddate'] = null;

                // email_bounced belonged to the previous owner's address, not the
                // car — carrying it forward would permanently exclude the car from
                // CarRepository::findVerificationEligible() once the address that
                // caused the bounce is gone.
                $ownerFields['email_bounced'] = $emailBounced;
            }

            // Validate owner fields before writing. $requireAll = false so only the
            // fields present in $ownerFields are checked (email format, website scheme,
            // lat/lon range, city/state/country normalization) without requiring car-
            // intrinsic fields like chassis/model/year that are not being updated here.
            $ownerFields = $this->withBlankedFieldsRestored(
                $ownerFields,
                (new CarValidator())->validateAndSanitizeFields($ownerFields, false)
            );

            $updateSuccess = $repo->updateCar($carId, $ownerFields);
            if (!$updateSuccess) {
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_TRANSFER, 'Database update failed: Repository returned false');
                throw new CarDatabaseException('Database update failed - check system logs for details.');
            }

            // Insert history before commit so a failure rolls back the entire
            // ownership change atomically (standalone and outer-transaction alike).
            $historyFields = [
                'operation'    => $operationType,
                'car_id'       => $carId,
                'comments'     => $reason,
                'ctime'        => $carData->ctime ?? date(AppConstants::DATETIME_FORMAT),
                'mtime'        => date(AppConstants::DATETIME_FORMAT),
                'model'        => $carData->model ?? '',
                'series'       => $carData->series ?? '',
                'variant'      => $carData->variant ?? '',
                'year'         => $carData->year ?? '',
                'type'         => $carData->type ?? '',
                'chassis'      => $carData->chassis ?? '',
                'color'        => $carData->color ?? '',
                'engine'       => $carData->engine ?? '',
                'purchasedate' => $carData->purchasedate ?? null,
                'solddate'     => $soldDate,
                'email_bounced' => $emailBounced,
                'image'        => $carData->image ?? '',
                'user_id'      => $targetUser->id,
                'email'        => $targetEmail,
                'fname'        => $targetUser->fname ?? '',
                'lname'        => $targetUser->lname ?? '',
                'join_date'    => $targetUser->join_date ?? null,
                'city'         => $targetUser->city ?? '',
                'state'        => $targetUser->state ?? '',
                'country'      => $targetUser->country ?? '',
                'lat'          => $targetUser->lat ?? null,
                'lon'          => $targetUser->lon ?? null,
                'website'      => $targetUser->website ?? ''
            ];

            if (!$repo->insertHistory($historyFields)) {
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_TRANSFER_ERROR, 'Failed to create audit trail entry for ' . $operationType);
                throw new CarDatabaseException('Operation failed - could not create audit trail entry.');
            }

            $repo->commit();

            return true;

        } catch (\Throwable $e) {
            $repo->rollback();
            if ($e instanceof CarException) {
                throw $e;
            }
            logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_TRANSFER, 'Car ownership transfer failed: ' . $e->getMessage());
            throw new CarDatabaseException('Operation failed - check system logs for technical details.');
        }
    }

    /**
     * Merge another car's history into a target car and delete the source car
     *
     * @param object $targetCarData Target car data object (car to keep)
     * @param int $oldCarId Source car ID (car to merge and delete)
     * @param string $reason Reason for merge (for audit trail)
     * @param int $adminUserId ID of the admin performing the merge
     * @param CarRepository $repo Repository for database operations
     * @return true Always returns true; throws on any failure.
     * @throws CarNotFoundException If source or target car doesn't exist
     * @throws CarValidationException If merging car with itself
     * @throws CarDatabaseException If a database operation fails, including a
     *         lost CAS race on the target's `image` column
     * @throws CarMergeException If merge operation fails, including a failure to
     *         relocate the image files — the relocator's own
     *         ImageProcessingException does not extend CarException, so it is
     *         wrapped rather than propagated to callers
     */
    public function merge(
        object $targetCarData,
        int $oldCarId,
        string $reason,
        int $adminUserId,
        CarRepository $repo
    ): true {
        if ($oldCarId === (int) $targetCarData->id) {
            logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, 'Cannot merge a car with itself - car ID: ' . $oldCarId);
            throw new CarValidationException('Unable to merge a car with itself.');
        }

        $newCarId = (int) $targetCarData->id;
        $newChassis = $targetCarData->chassis ?? 'Unknown';

        // Declared before the try so the catch block always has a map to
        // compensate with, whether or not relocate() was reached. It stays empty
        // when relocate() itself throws, and that is correct rather than a gap:
        // relocate() restores its own partial work before re-throwing, so there
        // is nothing left here to undo.
        $renameMap = [];

        // CarRepository::commit() clears its transactionOwner flag *before*
        // calling the driver's commit(), so a throw from the driver leaves the
        // catch block's rollback() a no-op — and the server-side commit may
        // well have landed anyway. Compensating in that state would move every
        // file back to the deleted source car's directory while the database
        // shows the merge as done, which is worse than the failure itself. This
        // flag lets the catch block tell the two cases apart.
        $committed = false;

        try {
            $repo->beginTransaction();

            // Both rows are locked, in ascending ID order: two concurrent merges
            // touching the same pair therefore queue behind each other instead of
            // deadlocking. The target is locked as well as the source because its
            // live `image` value is the CAS baseline for updateImage() below —
            // $targetCarData was read before the transaction opened and may
            // already be stale.
            if ($oldCarId < $newCarId) {
                $oldCarData = $repo->findByIdForUpdate($oldCarId);
                $lockedTargetCar = $repo->findByIdForUpdate($newCarId);
            } else {
                $lockedTargetCar = $repo->findByIdForUpdate($newCarId);
                $oldCarData = $repo->findByIdForUpdate($oldCarId);
            }

            if (!$oldCarData) {
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, 'Source car not found - cannot merge car ID: ' . $oldCarId);
                throw new CarNotFoundException('The source car for merging could not be found.');
            }

            if (!$lockedTargetCar) {
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, 'Target car not found - cannot merge into car ID: ' . $newCarId);
                throw new CarNotFoundException('The target car for merging could not be found.');
            }

            $oldChassis = $oldCarData->chassis ?? 'Unknown';

            if (!$repo->transferHistory($oldCarId, $newCarId)) {
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, 'Failed to transfer car history: query returned false');
                throw new CarDatabaseException('Car merge failed - could not transfer history records.');
            }

            if (!$repo->deleteCar($oldCarId)) {
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, 'Database update failed: query returned false');
                throw new CarDatabaseException('Database update failed - check system logs for details.');
            }

            // The filesystem cannot join the transaction, so the moves happen
            // here and restore() in the catch block is the compensating action.
            $renameMap = $this->relocator->relocate(
                $oldCarId,
                $newCarId,
                $this->storedImageFilenames($oldCarData->image ?? null)
            );

            // Target's own images keep their positions and the source's are
            // appended, so the surviving car's primary (first) image — the one
            // rendered as its card thumbnail — is unchanged by the merge.
            $mergedImageJson = (new CarImageProcessor($repo))->encodeImages(array_merge(
                $this->storedImageFilenames($lockedTargetCar->image ?? null),
                array_values($renameMap)
            ));

            // Only write when the column actually changes. An identical value
            // makes the UPDATE a no-op, and MySQL reports rows *changed* rather
            // than rows *matched* (PDO::MYSQL_ATTR_FOUND_ROWS is not set), so
            // updateImage() would report a CAS conflict for a write that was
            // never needed — e.g. merging a source car that has no images.
            $liveTargetImageJson = $lockedTargetCar->image ?? null;
            if ($mergedImageJson !== (string) $liveTargetImageJson) {
                // updateImage() returns false rather than throwing when its
                // null-safe `WHERE image <=> ?` guard matches no row, meaning
                // another writer changed the column between the lock and here.
                // Ignoring that would silently drop the relocated images from
                // cars.image while leaving the files moved.
                //
                // The write is skipped entirely when the value is unchanged
                // because MySQL reports rows *changed*, not rows *matched*
                // (PDO::MYSQL_ATTR_FOUND_ROWS is unset), so an identical write
                // returns 0 and would be misread as a lost CAS race.
                //
                // The row is already held by findByIdForUpdate() for the whole
                // transaction, so that lock, not this string comparison, is what
                // actually excludes a concurrent writer; the CAS is defence in
                // depth.
                if (!$repo->updateImage($newCarId, $mergedImageJson, $liveTargetImageJson)) {
                    logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, 'Image update rejected by CAS guard for car ID: ' . $newCarId);
                    throw new CarDatabaseException('Car merge failed - the target car was modified by another operation.');
                }
            }

            $historyFields = [
                'operation'    => self::OPERATION_MERGE,
                'car_id'       => $newCarId,
                'comments'     => "Car $oldChassis (ID: $oldCarId) was merged into car $newChassis (ID: $newCarId) by admin $adminUserId. Reason: $reason",
                'ctime'        => $targetCarData->ctime ?? date(AppConstants::DATETIME_FORMAT),
                'mtime'        => date(AppConstants::DATETIME_FORMAT),
                'model'        => $targetCarData->model ?? '',
                'series'       => $targetCarData->series ?? '',
                'variant'      => $targetCarData->variant ?? '',
                'year'         => $targetCarData->year ?? '',
                'type'         => $targetCarData->type ?? '',
                'chassis'      => $targetCarData->chassis ?? '',
                'color'        => $targetCarData->color ?? '',
                'engine'       => $targetCarData->engine ?? '',
                'purchasedate' => $targetCarData->purchasedate ?? null,
                'solddate'     => $targetCarData->solddate ?? null,
                // The POST-merge value, not $targetCarData->image: merge now
                // rewrites this column, so recording the pre-transaction read
                // would leave the audit trail misstating what the surviving car
                // held immediately after the operation.
                'image'        => $mergedImageJson
            ];

            if (!$repo->insertHistory($historyFields)) {
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, 'Failed to create audit trail entry for car merge');
                throw new CarDatabaseException('Operation failed - could not create audit trail entry.');
            }

            // Set BEFORE the call, not after: the ambiguous window is the
            // commit itself. CarRepository::commit() clears transactionOwner
            // before delegating to the driver, so a throw from inside it leaves
            // rollback() a no-op over a transaction the server may already have
            // committed durably. Setting this afterwards would leave it false in
            // exactly that case, sending the catch block down the compensating
            // branch — moving every file back to a source car the database says
            // is deleted, which is the corruption this flag exists to prevent.
            $committed = true;
            $repo->commit();

            // A collision rename gives a file a new name that appears nowhere
            // else: cars.image records only the new name, and the old name
            // survives only here. Without this line there is no way to tie a
            // file in the target directory back to the source car it came from,
            // which is exactly what a later investigation needs.
            if ($renameMap !== []) {
                logger(
                    $adminUserId,
                    LogCategories::LOG_CATEGORY_CAR_MERGE,
                    'Relocated ' . count($renameMap) . ' image file(s) from car ' . $oldCarId
                    . ' to car ' . $newCarId . '; old => new names: '
                    . json_encode($renameMap)
                );
            }

            return true;

        } catch (\Throwable $e) {
            // Declared before the branch: the $committed path deliberately does
            // not compensate, and the post-rollback checks below still read
            // both of these.
            $unrestored = [];
            $commitFailureWarning = null;

            if ($committed) {
                // The commit itself threw. rollback() below is a no-op (the
                // repository already cleared transactionOwner), and the
                // server-side commit may have succeeded, so the database state
                // is indeterminate. Restoring here could move the files back to
                // a car the database says no longer exists — the compensation
                // would create the corruption it exists to prevent. Leave the
                // files where they are and make sure a human hears about it.
                // The log itself is deferred until after rollback() for the
                // same durability reason as the unrestored-files warning below:
                // if the commit threw before the server actually committed, the
                // transaction is still open and a log written here would be
                // rolled back with it.
                $commitFailureWarning =
                    'Car merge commit failed after relocating image files from car ' . $oldCarId
                    . ' to car ' . $newCarId . '. Files were deliberately NOT restored because the'
                    . ' database state is indeterminate; manual verification of both the cars rows'
                    . ' and the image directories is required. Relocated old => new names: '
                    . json_encode($renameMap);
            } else {
                // Compensate before rollback so the filesystem and the database
                // are both restored to their pre-merge state. restore() never
                // throws, so it cannot mask $e; an empty map is a no-op.
                $unrestored = $this->relocator->restore($oldCarId, $newCarId, $renameMap);
            }

            $repo->rollback();

            if ($commitFailureWarning !== null) {
                $this->logFileWarning($adminUserId, $commitFailureWarning);
            }

            if ($unrestored !== []) {
                // The transaction rolls back cleanly but these files are
                // stranded in the surviving car's directory, so "rolled back"
                // is untrue of the filesystem. Only a log makes that
                // discoverable — and it must be written AFTER rollback():
                // logger() INSERTs into `logs` on this same connection, and
                // `logs` is InnoDB, so a warning written while the transaction
                // is still open is erased by the rollback that follows it
                // (verified against the project database: 1 row inside the
                // transaction, 0 after ROLLBACK).
                $this->logFileWarning(
                    $adminUserId,
                    'Car merge rollback could not restore ' . count($unrestored)
                    . ' image file(s) from car ' . $newCarId . ' back to car ' . $oldCarId
                    . '. Manual repair is required; the files remain in the target car'
                    . ' directory. Unrestored old => new names: ' . json_encode($unrestored)
                );
            }
            if ($e instanceof CarException) {
                throw $e;
            }
            logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, 'Car merge failed: ' . $e->getMessage());
            throw new CarMergeException('Operation failed - check system logs for technical details.');
        }
    }

    /**
     * Record a filesystem inconsistency that needs a human, from inside an
     * error path that is already handling another failure.
     *
     * Callers must invoke this only from an error path where an exception is
     * already pending — that pending exception is the thing the caller actually
     * needs to see, and this method is written to protect it. logger() writes to
     * the database, so it can itself throw — a dead connection is a plausible
     * reason for the merge to have failed in the first place. Letting that
     * throw would replace the original exception with a logging error and lose
     * the real diagnosis, so it is caught and discarded here: a lost log line
     * is a strictly smaller loss than a masked exception.
     *
     * Callers must also invoke this only once the surrounding transaction is
     * closed. logger() INSERTs into the InnoDB `logs` table on the same
     * connection, so a warning written inside a transaction that then rolls
     * back is erased along with it.
     *
     * The message additionally goes to error_log() because a database log is
     * the wrong last line of defence for a message about database/filesystem
     * divergence: logger() delegates to DB::insert(), which returns false on
     * failure rather than throwing, so the catch below cannot see a quiet
     * failed insert. These call sites are the only record that image files are
     * misplaced, so they are written somewhere that does not depend on the
     * database being healthy.
     *
     * @param int    $adminUserId Admin performing the merge.
     * @param string $message     Full description, including both car IDs and
     *                            the relevant rename map as JSON.
     * @return void
     */
    private function logFileWarning(int $adminUserId, string $message): void
    {
        error_log('ElanRegistry car merge image warning: ' . $message);

        try {
            logger($adminUserId, LogCategories::LOG_CATEGORY_FILE_ERROR, $message);
        } catch (\Throwable) {
            // Deliberately swallowed — see the docblock above.
        }
    }

    /**
     * Read the base filenames stored in a car's `image` column.
     *
     * The column normally holds a JSON array of bare filenames, but rows
     * predating that format store a comma-separated list — the same fallback
     * CarImageProcessor::decodeAndProcessImages() applies. Values are returned
     * verbatim; CarImageRelocator validates each one with isSafeFilename()
     * before touching the filesystem.
     *
     * @param string|null $imageData Raw `cars.image` value.
     * @return list<string> Stored filenames in their stored order, possibly empty.
     */
    private function storedImageFilenames(?string $imageData): array
    {
        if ($imageData === null || $imageData === '') {
            return [];
        }

        $decoded = json_decode($imageData, true);
        if (!is_array($decoded)) {
            $decoded = explode(',', $imageData);
        }

        $filenames = [];
        foreach ($decoded as $filename) {
            if (is_string($filename) && $filename !== '') {
                $filenames[] = $filename;
            }
        }

        return $filenames;
    }

    /**
     * Reduce an owner email to what may legitimately be denormalized onto a car
     * or its history row: a real, deliverable address, or nothing at all.
     *
     * Anything that fails FILTER_VALIDATE_EMAIL is stored as an empty string
     * rather than propagated. This is the same filter CarValidator applies,
     * checked here so a system-account target blanks the field instead of
     * aborting the whole transfer.
     *
     * The expected case is a system account's unroutable sentinel (`noowner`),
     * where blanking is the intended outcome and needs no attention. Any *other*
     * unparseable address means a real account is carrying a malformed
     * `users.email` — dropping that silently would erase a legitimate owner's
     * contact details with no record, so it is logged. Blanking still wins over
     * throwing: this path runs inside the GDPR reassignment transaction in
     * usersc/scripts/after_user_deletion.php, where an exception would roll back
     * the entire deletion and leave the deleted owner's PII on their former cars.
     */
    private function contactableEmail(string $email, int $adminUserId, int $carId): string
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            return $email;
        }

        if ($email !== '' && $email !== self::SYSTEM_ACCOUNT_EMAIL) {
            logger(
                $adminUserId,
                LogCategories::LOG_CATEGORY_CAR_TRANSFER,
                'Target owner email failed validation and was not copied to car ' . $carId
                . '; the account carries a malformed users.email that needs correcting'
            );
        }

        return '';
    }

    /**
     * Re-add owner fields that CarValidator dropped for being empty.
     *
     * validateAndSanitizeFields() only copies a field into its result when the
     * value is non-empty, which is right for a user-submitted edit form (an
     * omitted field should leave the stored value alone) but wrong for an
     * ownership change: these columns are a denormalized copy of *the current
     * owner*, so a new owner with no city must clear the old owner's city
     * rather than inherit it. Without this, transferring a car — including the
     * GDPR reassignment in usersc/scripts/after_user_deletion.php — left the
     * previous owner's email, location and website on the row.
     *
     * @param array<string, mixed> $submitted Owner fields as assembled above.
     * @param array<string, mixed> $validated CarValidator's filtered output.
     * @return array<string, mixed>
     */
    private function withBlankedFieldsRestored(array $submitted, array $validated): array
    {
        foreach (self::OWNER_IDENTITY_FIELDS as $field) {
            if (!array_key_exists($field, $validated) && array_key_exists($field, $submitted)) {
                $validated[$field] = $submitted[$field];
            }
        }

        return $validated;
    }
}
