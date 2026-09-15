<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use DateTime;
use ElanRegistry\AppConstants;
use ElanRegistry\Exceptions\CarDatabaseException;
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
     * Execute a repository update, translating failures into CarDatabaseException
     *
     * @param callable $update Repository call to execute; returns true on success
     * @param string $logCategory LogCategories constant to log failures under
     * @param string $failureMessage User-facing message thrown if the update call itself throws
     * @param string $context Log message prefix used when the update call throws
     * @param int $carId Car id being updated, included in the logged message for traceability
     * @return bool True on success
     * @throws CarDatabaseException If the update call throws or the repository reports failure
     */
    private function persist(callable $update, string $logCategory, string $failureMessage, string $context, int $carId): bool
    {
        try {
            $updateSuccess = $update();
        } catch (\Throwable $e) {
            logger(0, $logCategory, sprintf('%s for car %d (%s): %s', $context, $carId, get_class($e), $e->getMessage()));
            throw new CarDatabaseException($failureMessage);
        }

        if (!$updateSuccess) {
            logger(0, $logCategory, sprintf(
                'Database update failed for car %d: Repository returned false: %s',
                $carId,
                $this->repo->errorString() ?: 'unknown'
            ));
            throw new CarDatabaseException('Unable to save changes. Please try again.');
        }

        return true;
    }

    /**
     * Set or clear a car's bounced-email flag, and the address it bounced against
     *
     * @param object $carData Car data object (must have ->id property)
     * @param bool $bounced True to flag as bounced, false to clear
     * @param string|null $bouncedAddress The address the bounce was reported against
     *                                    (ignored/nulled when $bounced is false)
     * @return bool True if the bounce flag was updated successfully
     * @throws CarDatabaseException If database update fails
     */
    private function updateBounced(object $carData, bool $bounced, ?string $bouncedAddress = null): bool
    {
        $bouncedAddress = $bounced ? $bouncedAddress : null;

        $result = $this->persist(
            fn () => $this->repo->updateEmailBounced((int) $carData->id, $bounced, $bouncedAddress),
            LogCategories::LOG_CATEGORY_EMAIL_BOUNCED,
            $bounced
                ? 'Bounce status could not be updated. Please try again or contact support.'
                : 'Bounce status could not be cleared. Please try again or contact support.',
            $bounced ? 'Failed to flag email as bounced' : 'Failed to clear bounced email flag',
            (int) $carData->id,
        );

        $carData->email_bounced = $bounced ? 1 : 0;
        $carData->email_bounced_address = $bouncedAddress;
        return $result;
    }

    /**
     * Set or clear a car's email-suppressed flag
     *
     * @param object $carData Car data object (must have ->id property)
     * @param bool $suppressed True to flag as suppressed, false to clear
     * @return bool True if the suppressed flag was updated successfully
     * @throws CarDatabaseException If database update fails
     */
    private function updateSuppressed(object $carData, bool $suppressed): bool
    {
        $result = $this->persist(
            fn () => $this->repo->updateEmailSuppressed((int) $carData->id, $suppressed),
            LogCategories::LOG_CATEGORY_EMAIL_BOUNCED,
            $suppressed
                ? 'Suppressed status could not be updated. Please try again or contact support.'
                : 'Suppressed status could not be cleared. Please try again or contact support.',
            $suppressed ? 'Failed to flag email as suppressed' : 'Failed to clear suppressed email flag',
            (int) $carData->id,
        );

        $carData->email_suppressed = $suppressed ? 1 : 0;
        return $result;
    }

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

        $result = $this->persist(
            fn () => $this->repo->updateVerificationCode((int) $carData->id, $verificationCode),
            LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            'Verification code could not be updated. Please try again or contact support.',
            'Failed to set verification code',
            (int) $carData->id,
        );

        $carData->vericode = $verificationCode;
        return $result;
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

        $result = $this->persist(
            fn () => $this->repo->updateLastVerified((int) $carData->id, $currentDateTime),
            LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            'Unable to mark car as verified. Please try again or contact support.',
            'Failed to mark car as verified',
            (int) $carData->id,
        );

        $carData->last_verified = $currentDateTime;
        return $result;
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

        $result = $this->persist(
            fn () => $this->repo->updateSoldDate((int) $carData->id, $soldDate),
            LogCategories::LOG_CATEGORY_CAR_SOLD,
            'Unable to mark car as sold. Please try again or contact support.',
            'Failed to mark car as sold',
            (int) $carData->id,
        );

        $carData->solddate = $soldDate;
        return $result;
    }

    /**
     * Generate a cryptographically secure verification code
     *
     * Returns a 32-character lowercase hexadecimal string (16 random bytes).
     *
     * @return string The generated verification code
     */
    public function generateVerificationCode(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Record when a verification email was sent for a car
     *
     * @param object $carData Car data object (must have ->id property)
     * @param string $dateTime Timestamp the verification email was sent
     * @return bool True if the timestamp was recorded successfully
     * @throws CarDatabaseException If database update fails
     */
    public function setVerificationSentAt(object $carData, string $dateTime): bool
    {
        $result = $this->persist(
            fn () => $this->repo->updateVerificationSentAt((int) $carData->id, $dateTime),
            LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            'Verification timestamp could not be updated. Please try again or contact support.',
            'Failed to set verification sent timestamp',
            (int) $carData->id,
        );

        $carData->vericode_sent_at = $dateTime;
        return $result;
    }

    /**
     * Flag a car's owner email as bounced
     *
     * @param object $carData Car data object (must have ->id property)
     * @param string $bouncedAddress The address the bounce was reported against
     * @return bool True if the bounce flag was set successfully
     * @throws CarDatabaseException If database update fails
     */
    public function setBounced(object $carData, string $bouncedAddress): bool
    {
        return $this->updateBounced($carData, true, $bouncedAddress);
    }

    /**
     * Clear a car's bounced email flag (admin reversal)
     *
     * @param object $carData Car data object (must have ->id property)
     * @return bool True if the bounce flag was cleared successfully
     * @throws CarDatabaseException If database update fails
     */
    public function clearBounced(object $carData): bool
    {
        return $this->updateBounced($carData, false);
    }

    /**
     * Flag a car's owner email as suppressed (e.g. a spam complaint)
     *
     * @param object $carData Car data object (must have ->id property)
     * @return bool True if the suppressed flag was set successfully
     * @throws CarDatabaseException If database update fails
     */
    public function setSuppressed(object $carData): bool
    {
        return $this->updateSuppressed($carData, true);
    }

    /**
     * Clear a car's email-suppressed flag (admin reversal)
     *
     * @param object $carData Car data object (must have ->id property)
     * @return bool True if the suppressed flag was cleared successfully
     * @throws CarDatabaseException If database update fails
     */
    public function clearSuppressed(object $carData): bool
    {
        return $this->updateSuppressed($carData, false);
    }
}
