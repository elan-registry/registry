<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\AppConstants;
use ElanRegistry\CarErrorMessages;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarDeletionException;
use ElanRegistry\Exceptions\CarException;
use ElanRegistry\Exceptions\CarMergeException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;

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
                $technicalMsg = CarErrorMessages::getTechnicalMessage('database_update_failed', ['error' => 'query returned false']);
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_DELETION, $technicalMsg);
                throw new CarDatabaseException(CarErrorMessages::getAdminMessage('database_update_failed'));
            }

            $repo->commit();
            logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_DELETION, "Car ID $carId ($chassis) permanently deleted. Reason: $reason");
            return true;

        } catch (\Throwable $e) {
            $repo->rollback();
            if ($e instanceof CarException) {
                throw $e;
            }
            $technicalMsg = CarErrorMessages::getTechnicalMessage('operation_failed', ['operation' => 'Car deletion', 'error' => $e->getMessage()]);
            logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_DELETION, $technicalMsg);
            throw new CarDeletionException(CarErrorMessages::getMessage('operation_failed', 'admin'));
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
            $technicalMsg = CarErrorMessages::getTechnicalMessage('user_not_found', ['user_id' => $newUserId]);
            logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_TRANSFER, $technicalMsg);
            throw new CarValidationException(CarErrorMessages::getMessage('user_not_found'));
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

            $updateSuccess = $repo->updateCar($carId, $ownerFields);
            if (!$updateSuccess) {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('database_update_failed', ['error' => 'Repository returned false']);
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_TRANSFER, $technicalMsg);
                throw new CarDatabaseException(CarErrorMessages::getAdminMessage('database_update_failed'));
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
                $technicalMsg = CarErrorMessages::getTechnicalMessage('audit_trail_failed', ['operation' => $operationType]);
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_TRANSFER_ERROR, $technicalMsg);
                throw new CarDatabaseException(CarErrorMessages::getAdminMessage('audit_trail_failed', ['operation' => $operationType]));
            }

            $repo->commit();

            return true;

        } catch (\Throwable $e) {
            $repo->rollback();
            if ($e instanceof CarException) {
                throw $e;
            }
            $technicalMsg = CarErrorMessages::getTechnicalMessage('operation_failed', ['operation' => 'Car ownership transfer', 'error' => $e->getMessage()]);
            logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_TRANSFER, $technicalMsg);
            throw new CarDatabaseException(CarErrorMessages::getMessage('operation_failed', 'admin'));
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
            $technicalMsg = CarErrorMessages::getTechnicalMessage('car_merge_self', ['id' => $oldCarId]);
            logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, $technicalMsg);
            throw new CarValidationException(CarErrorMessages::getMessage('car_merge_self'));
        }

        $newCarId = (int) $targetCarData->id;
        $newChassis = $targetCarData->chassis ?? 'Unknown';

        try {
            $repo->beginTransaction();

            $oldCarData = $repo->findByIdForUpdate($oldCarId);
            if (!$oldCarData) {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('merge_source_not_found', ['id' => $oldCarId]);
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, $technicalMsg);
                throw new CarNotFoundException(CarErrorMessages::getMessage('merge_source_not_found'));
            }

            $oldChassis = $oldCarData->chassis ?? 'Unknown';

            if (!$repo->transferHistory($oldCarId, $newCarId)) {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('car_history_transfer_failed', ['error' => 'query returned false']);
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, $technicalMsg);
                throw new CarDatabaseException(CarErrorMessages::getAdminMessage('car_history_transfer_failed'));
            }

            if (!$repo->deleteCar($oldCarId)) {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('database_update_failed', ['error' => 'query returned false']);
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, $technicalMsg);
                throw new CarDatabaseException(CarErrorMessages::getAdminMessage('database_update_failed'));
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
                $technicalMsg = CarErrorMessages::getTechnicalMessage('audit_trail_failed', ['operation' => 'car merge']);
                logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, $technicalMsg);
                throw new CarDatabaseException(CarErrorMessages::getAdminMessage('audit_trail_failed', ['operation' => 'car merge']));
            }

            $repo->commit();
            return true;

        } catch (\Throwable $e) {
            $repo->rollback();
            if ($e instanceof CarException) {
                throw $e;
            }
            $technicalMsg = CarErrorMessages::getTechnicalMessage('operation_failed', ['operation' => 'Car merge', 'error' => $e->getMessage()]);
            logger($adminUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, $technicalMsg);
            throw new CarMergeException(CarErrorMessages::getMessage('operation_failed', 'admin'));
        }
    }
}
