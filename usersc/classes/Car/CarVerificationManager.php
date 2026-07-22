<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use DateTime;
use ElanRegistry\AppConstants;
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
     * @throws CarValidationException If verification code is invalid
     * @throws CarDatabaseException If database update fails
     */
    public function setVerificationCode(object $carData, string $verificationCode): bool
    {
        if (strlen($verificationCode) < 8) {
            throw new CarValidationException('The verification code format is not valid.');
        }

        try {
            $updateSuccess = $this->repo->updateVerificationCode((int) $carData->id, $verificationCode);
        } catch (\Throwable $e) {
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, 'Failed to set verification code: ' . $e->getMessage());
            throw new CarDatabaseException('Verification code could not be updated. Please try again or contact support.');
        }

        if ($updateSuccess) {
            $carData->vericode = $verificationCode;
            return true;
        }

        logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, 'Database update failed: Repository returned false: ' . ($this->repo->errorString() ?: 'unknown'));
        throw new CarDatabaseException('Unable to save changes. Please try again.');
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
        } catch (\Throwable $e) {
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, 'Failed to mark car as verified: ' . $e->getMessage());
            throw new CarDatabaseException('Unable to mark car as verified. Please try again or contact support.');
        }

        if ($updateSuccess) {
            $carData->last_verified = $currentDateTime;
            return true;
        }

        logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, 'Database update failed: Repository returned false: ' . ($this->repo->errorString() ?: 'unknown'));
        throw new CarDatabaseException('Unable to save changes. Please try again.');
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
            throw new CarValidationException('The sold date format is not valid. Please use YYYY-MM-DD format.');
        }

        try {
            $updateSuccess = $this->repo->updateSoldDate((int) $carData->id, $soldDate);
        } catch (\Throwable $e) {
            logger(0, LogCategories::LOG_CATEGORY_CAR_SOLD, 'Failed to mark car as sold: ' . $e->getMessage());
            throw new CarDatabaseException('Unable to mark car as sold. Please try again or contact support.');
        }

        if ($updateSuccess) {
            $carData->solddate = $soldDate;
            return true;
        }

        logger(0, LogCategories::LOG_CATEGORY_CAR_SOLD, 'Database update failed: Repository returned false: ' . ($this->repo->errorString() ?: 'unknown'));
        throw new CarDatabaseException('Unable to save changes. Please try again.');
    }
}
