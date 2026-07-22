<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\AppConstants;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarDeletionException;
use ElanRegistry\Exceptions\CarException;
use ElanRegistry\Exceptions\CarMergeException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarValidationException;
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
    private const OPERATION_MERGE = 'MERGE';

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
     * @return true Always returns true; throws on any failure.
     * @throws CarValidationException If target user is invalid
     * @throws CarDatabaseException If database operation fails
     */
    public function transfer(
        object $carData,
        int $newUserId,
        string $reason,
        string $operationType,
        int $adminUserId,
        CarRepository $repo
    ): true {
        $targetUser = (new Owner($newUserId))->data();
        if (!$targetUser) {
            logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_TRANSFER, 'Target user not found - cannot transfer ownership to user ID: ' . $newUserId);
            throw new CarValidationException('Unable to transfer ownership: the target user account is not valid.');
        }

        $carId = (int) $carData->id;

        try {
            $repo->beginTransaction();

            $ownerFields = [
                'mtime'     => date(AppConstants::DATETIME_FORMAT),
                'user_id'   => $targetUser->id,
                'email'     => $targetUser->email    ?? '',
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

            // Validate owner fields before writing. $requireAll = false so only the
            // fields present in $ownerFields are checked (email format, website scheme,
            // lat/lon range, city/state/country normalization) without requiring car-
            // intrinsic fields like chassis/model/year that are not being updated here.
            $ownerFields = (new CarValidator())->validateAndSanitizeFields($ownerFields, false);

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
                'solddate'     => $carData->solddate ?? null,
                'image'        => $carData->image ?? '',
                'user_id'      => $targetUser->id,
                'email'        => $targetUser->email ?? '',
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
     * @throws CarNotFoundException If source car doesn't exist
     * @throws CarValidationException If merging car with itself
     * @throws CarDatabaseException If database operation fails
     * @throws CarMergeException If merge operation fails
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

        try {
            $repo->beginTransaction();

            $oldCarData = $repo->findByIdForUpdate($oldCarId);
            if (!$oldCarData) {
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, 'Source car not found - cannot merge car ID: ' . $oldCarId);
                throw new CarNotFoundException('The source car for merging could not be found.');
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
                'image'        => $targetCarData->image ?? ''
            ];

            if (!$repo->insertHistory($historyFields)) {
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, 'Failed to create audit trail entry for car merge');
                throw new CarDatabaseException('Operation failed - could not create audit trail entry.');
            }

            $repo->commit();
            return true;

        } catch (\Throwable $e) {
            $repo->rollback();
            if ($e instanceof CarException) {
                throw $e;
            }
            logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, 'Car merge failed: ' . $e->getMessage());
            throw new CarMergeException('Operation failed - check system logs for technical details.');
        }
    }
}
