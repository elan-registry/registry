<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use DateTime;
use ElanRegistry\AppConstants;
use ElanRegistry\CarErrorMessages;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\LogCategories;

/**
 * CarVerificationManager - Verification code and status management for cars
 *
 * Extracted from Car.php to provide focused, testable verification logic.
 * Handles setting verification codes, marking cars as verified, and marking cars as sold.
 *
 * @package ElanRegistry\Car
 * @since v2.15.0
 * @see https://github.com/unibrain1/elanregistry/issues/463
 */
class CarVerificationManager
{
    public function __construct(private CarRepository $repo) {}

    /**
     * Set a verification code on a car
     *
     * @param object $carData Car data object (must have ->id property)
     * @param string $verificationCode The verification code to set
     * @return bool True if verification code was set successfully
     * @throws CarNotFoundException If car data is invalid
     * @throws CarValidationException If verification code is invalid
     * @throws CarDatabaseException If database update fails
     */
    public function setVerificationCode(object $carData, string $verificationCode): bool
    {
        if (strlen($verificationCode) < 8) {
            throw new CarValidationException(CarErrorMessages::getMessage('invalid_verification_code'));
        }

        try {
            $updateSuccess = $this->repo->updateVerificationCode((int) $carData->id, $verificationCode);
        } catch (\Exception $e) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('verification_code_failed', ['error' => $e->getMessage()]);
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
            throw new CarDatabaseException(CarErrorMessages::getMessage('verification_code_failed'));
        }

        if ($updateSuccess) {
            $carData->vericode = $verificationCode;
            return true;
        }

        $technicalMsg = CarErrorMessages::getTechnicalMessage('database_update_failed', ['error' => 'Repository returned false: ' . ($this->repo->errorString() ?: 'unknown')]);
        logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
        throw new CarDatabaseException(CarErrorMessages::getMessage('database_update_failed'));
    }

    /**
     * Mark a car as verified
     *
     * @param object $carData Car data object (must have ->id property)
     * @return bool True if car was marked as verified successfully
     * @throws CarDatabaseException If database update fails
     */
    public function markVerified(object $carData): bool
    {
        $currentDateTime = date(AppConstants::DATETIME_FORMAT);

        try {
            $updateSuccess = $this->repo->updateLastVerified((int) $carData->id, $currentDateTime);
        } catch (\Exception $e) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('verification_mark_failed', ['error' => $e->getMessage()]);
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
            throw new CarDatabaseException(CarErrorMessages::getMessage('verification_mark_failed'));
        }

        if ($updateSuccess) {
            $carData->last_verified = $currentDateTime;
            return true;
        }

        $technicalMsg = CarErrorMessages::getTechnicalMessage('database_update_failed', ['error' => 'Repository returned false: ' . ($this->repo->errorString() ?: 'unknown')]);
        logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
        throw new CarDatabaseException(CarErrorMessages::getMessage('database_update_failed'));
    }

    /**
     * Mark a car as sold
     *
     * @param object $carData Car data object (must have ->id property)
     * @param string|null $soldDate Sold date in Y-m-d format (defaults to today)
     * @return bool True if car was marked as sold successfully
     * @throws CarValidationException If date format is invalid
     * @throws CarDatabaseException If database update fails
     */
    public function markSold(object $carData, ?string $soldDate): bool
    {
        $soldDate ??= date('Y-m-d');

        $parsedDate = DateTime::createFromFormat('Y-m-d', $soldDate);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $soldDate) {
            throw new CarValidationException(CarErrorMessages::getMessage('invalid_sold_date', 'user', ['date' => $soldDate]));
        }

        try {
            $updateSuccess = $this->repo->updateSoldDate((int) $carData->id, $soldDate);
        } catch (\Exception $e) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('sold_mark_failed', ['error' => $e->getMessage()]);
            logger(0, LogCategories::LOG_CATEGORY_CAR_SOLD, $technicalMsg);
            throw new CarDatabaseException(CarErrorMessages::getMessage('sold_mark_failed'));
        }

        if ($updateSuccess) {
            $carData->solddate = $soldDate;
            return true;
        }

        $technicalMsg = CarErrorMessages::getTechnicalMessage('database_update_failed', ['error' => 'Repository returned false: ' . ($this->repo->errorString() ?: 'unknown')]);
        logger(0, LogCategories::LOG_CATEGORY_CAR_SOLD, $technicalMsg);
        throw new CarDatabaseException(CarErrorMessages::getMessage('database_update_failed'));
    }
}
