<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use CarErrorMessages;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarValidationException;
use LogCategories;
use AppConstants;
use DateTime;
use DB;

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
    /**
     * Set a verification code on a car
     *
     * @param object $carData Car data object (must have ->id property)
     * @param string $verificationCode The verification code to set
     * @param DB $db Database instance
     * @return bool True if verification code was set successfully
     * @throws CarNotFoundException If car data is invalid
     * @throws CarValidationException If verification code is invalid
     * @throws CarDatabaseException If database update fails
     */
    public function setVerificationCode(object $carData, string $verificationCode, DB $db): bool
    {
        if (empty($verificationCode) || strlen($verificationCode) < 8) {
            throw new CarValidationException(CarErrorMessages::getMessage('invalid_verification_code'));
        }

        try {
            $updateSuccess = $db->update('cars', $carData->id, ['vericode' => $verificationCode]);

            if ($updateSuccess) {
                $carData->vericode = $verificationCode;
                return true;
            } else {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('database_update_failed', ['error' => 'Database update returned false']);
                logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
                throw new CarDatabaseException(CarErrorMessages::getMessage('database_update_failed'));
            }
        } catch (CarDatabaseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('verification_code_failed', ['error' => $e->getMessage()]);
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
            throw new CarDatabaseException(CarErrorMessages::getMessage('verification_code_failed'));
        }
    }

    /**
     * Mark a car as verified
     *
     * @param object $carData Car data object (must have ->id property)
     * @param DB $db Database instance
     * @return bool True if car was marked as verified successfully
     * @throws CarDatabaseException If database update fails
     */
    public function markVerified(object $carData, DB $db): bool
    {
        try {
            $currentDateTime = date(AppConstants::DATETIME_FORMAT);
            $updateSuccess = $db->update('cars', $carData->id, ['last_verified' => $currentDateTime]);

            if ($updateSuccess) {
                $carData->last_verified = $currentDateTime;
                return true;
            } else {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('database_update_failed', ['error' => 'Database update returned false']);
                logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
                throw new CarDatabaseException(CarErrorMessages::getMessage('database_update_failed'));
            }
        } catch (CarDatabaseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('verification_mark_failed', ['error' => $e->getMessage()]);
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
            throw new CarDatabaseException(CarErrorMessages::getMessage('verification_mark_failed'));
        }
    }

    /**
     * Mark a car as sold
     *
     * @param object $carData Car data object (must have ->id property)
     * @param string|null $soldDate Sold date in Y-m-d format (defaults to today)
     * @param DB $db Database instance
     * @return bool True if car was marked as sold successfully
     * @throws CarValidationException If date format is invalid
     * @throws CarDatabaseException If database update fails
     */
    public function markSold(object $carData, ?string $soldDate, DB $db): bool
    {
        if ($soldDate === null) {
            $soldDate = date('Y-m-d');
        }

        if (!DateTime::createFromFormat('Y-m-d', $soldDate)) {
            throw new CarValidationException(CarErrorMessages::getMessage('invalid_sold_date', 'user', ['date' => $soldDate]));
        }

        try {
            $updateSuccess = $db->update('cars', $carData->id, ['solddate' => $soldDate]);

            if ($updateSuccess) {
                $carData->solddate = $soldDate;
                return true;
            } else {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('database_update_failed', ['error' => 'Database update returned false']);
                logger(0, LogCategories::LOG_CATEGORY_CAR_SOLD, $technicalMsg);
                throw new CarDatabaseException(CarErrorMessages::getMessage('database_update_failed'));
            }
        } catch (CarDatabaseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('sold_mark_failed', ['error' => $e->getMessage()]);
            logger(0, LogCategories::LOG_CATEGORY_CAR_SOLD, $technicalMsg);
            throw new CarDatabaseException(CarErrorMessages::getMessage('sold_mark_failed'));
        }
    }
}
