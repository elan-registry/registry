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
     * The plaintext code is hashed (HMAC-SHA256 via hashVericode()) before storage;
     * cars.vericode never holds plaintext. Note the deliberate asymmetry: on success,
     * $carData->vericode is set to the *plaintext* code so the caller can compose the
     * verification email from it — this differs from what a fresh DB read would return.
     *
     * @param object $carData Car data object (must have ->id property); on success
     *                        its ->vericode is set to the plaintext $verificationCode
     * @param string $verificationCode The plaintext verification code to set
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
            fn () => $this->repo->updateVerificationCode((int) $carData->id, hashVericode($verificationCode)),
            LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            'Verification code could not be updated. Please try again or contact support.',
            'Failed to set verification code',
            (int) $carData->id,
        );

        // The DB now stores hashVericode($verificationCode); this property holds
        // the plaintext for the caller (e.g. the future email composer).
        $carData->vericode = $verificationCode;
        return $result;
    }

    /**
     * Mark a car as verified
     *
     * Writes `last_verified` and `owner_last_updated` in a single atomic update,
     * both set to the same timestamp.
     *
     * OWNER-INITIATED TRIGGER: this method belongs to the owner-facing verify flow.
     * `owner_last_updated` must only ever reflect owner action (per the FRD), so this
     * method must NOT be reused for admin-initiated changes without first adding an
     * actor-flag parameter that suppresses the `owner_last_updated` write.
     *
     * @param object $carData Car data object (must have ->id property)
     * @return bool True if car was marked as verified successfully
     * @throws CarDatabaseException If database update fails
     */
    public function markVerified(object $carData): bool
    {
        $currentDateTime = date(AppConstants::DATETIME_FORMAT);

        $result = $this->persist(
            fn () => $this->repo->updateCar((int) $carData->id, [
                'last_verified'      => $currentDateTime,
                'owner_last_updated' => $currentDateTime,
            ]),
            LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            'Unable to mark car as verified. Please try again or contact support.',
            'Failed to mark car as verified',
            (int) $carData->id,
        );

        $carData->last_verified = $currentDateTime;
        $carData->owner_last_updated = $currentDateTime;
        return $result;
    }

    /**
     * Mark a car as sold
     *
     * Writes `solddate` and `owner_last_updated` in a single atomic update.
     * `owner_last_updated` is the current datetime, independent of $soldDate.
     *
     * OWNER-INITIATED TRIGGER: this method belongs to the owner-facing sold flow.
     * `owner_last_updated` must only ever reflect owner action (per the FRD), so this
     * method must NOT be reused for admin-initiated changes without first adding an
     * actor-flag parameter that suppresses the `owner_last_updated` write.
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

        $currentDateTime = date(AppConstants::DATETIME_FORMAT);

        $result = $this->persist(
            fn () => $this->repo->updateCar((int) $carData->id, [
                'solddate'           => $soldDate,
                'owner_last_updated' => $currentDateTime,
            ]),
            LogCategories::LOG_CATEGORY_CAR_SOLD,
            'Unable to mark car as sold. Please try again or contact support.',
            'Failed to mark car as sold',
            (int) $carData->id,
        );

        $carData->solddate = $soldDate;
        $carData->owner_last_updated = $currentDateTime;
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

    /**
     * Suppress verification email for every car this owner has (owner-initiated
     * opt-out). Runs no transaction of its own — caller wraps this and the
     * per-car cars_hist inserts in one transaction (matches verify/sold
     * branches in verify_car.php).
     *
     * Scoped to unsold cars only (findUnsoldByOwner()): a sold car is never a
     * verification-email candidate (findVerificationEligible() excludes
     * solddate IS NOT NULL), so suppressing it has no real effect and counting
     * it would overstate what the opt-out did.
     *
     * TWO WRITES, TWO MEANINGS. Besides the per-car fan-out this sets
     * `profiles.email_suppressed = 1` once for the owner. The per-car flag is
     * the fan-out target and is also written by the Brevo webhook/sync paths;
     * the profile flag is the authoritative record that *this owner opted out*,
     * and stays correct when their car list later changes or the per-car flags
     * drift. The profile write happens FIRST, before any car is touched, so an
     * owner with no profiles row fails the whole opt-out cleanly rather than
     * part-way through the fan-out. It is independent of $changed: it is
     * attempted even when every car is already suppressed, so a profile flag
     * that was never set (e.g. a car suppressed by the Brevo path before this
     * column existed) is still brought up to date. It is skipped only when the
     * profile flag already reads 1 — the same read-then-skip idempotency the
     * per-car loop uses, and required here because MySQL reports 0 affected
     * rows for an UPDATE that changes nothing, which is indistinguishable from
     * a missing row.
     *
     * @param int $ownerId Owner user ID
     * @return array<object> PRE-CHANGE snapshots of the car rows actually
     *                        changed — each is a clone taken before
     *                        setSuppressed() wrote email_suppressed=1 onto it,
     *                        so the caller's cars_hist row records the OLD
     *                        value (matching the cars_update trigger's OLD.*
     *                        convention). Already-suppressed cars are skipped —
     *                        idempotent at the per-car level. An empty array
     *                        does NOT mean nothing happened: the owner-level
     *                        profile flag may still have been written.
     * @throws CarDatabaseException If a database update fails, or if the owner
     *                              has no `profiles` row to record the
     *                              opt-out on (nothing is written in that case
     *                              — the check runs before the fan-out)
     */
    public function setSuppressedForOwner(int $ownerId): array
    {
        $this->suppressOwnerProfile($ownerId);

        $changed = [];

        foreach ($this->repo->findUnsoldByOwner($ownerId) as $carRef) {
            $carData = $this->repo->findById((int) $carRef->id);

            if ($carData === null) {
                // findUnsoldByOwner() listed this id moments ago, so null here
                // means the row disappeared mid-fan-out (concurrent merge or
                // GDPR erasure) — or a genuine findUnsoldByOwner()/findById()
                // inconsistency. Skipping is correct (nothing to suppress), but
                // it must never be silent: without this log line it is
                // indistinguishable from a real bug.
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, sprintf(
                    'CarVerificationManager::setSuppressedForOwner: car %d listed for owner %d '
                    . 'but no longer readable; skipped from opt-out fan-out',
                    (int) $carRef->id,
                    $ownerId
                ));
                continue;
            }

            if ((int) $carData->email_suppressed === 1) {
                continue; // Already suppressed — idempotent, expected, not an anomaly.
            }

            // Snapshot BEFORE mutation: setSuppressed() writes email_suppressed=1
            // onto $carData in place, and the caller uses this returned object to
            // build a cars_hist audit row — which must record the pre-change
            // state (matches the cars_update trigger's OLD.* convention), not the
            // post-change state setSuppressed() leaves behind.
            $before = clone $carData;

            $this->setSuppressed($carData);
            $changed[] = $before;
        }

        return $changed;
    }

    /**
     * Record the owner-level opt-out on `profiles.email_suppressed` (#1883)
     *
     * Reads the current value first and returns without writing when it is
     * already 1 — both to keep a repeat opt-out a true no-op and because a
     * MySQL UPDATE that changes nothing reports 0 affected rows, which this
     * method would otherwise mistake for a missing profiles row.
     *
     * A missing profiles row is an error, not a skip. `users` and `profiles`
     * are not strictly 1:1 in this schema, so an owner can genuinely have no
     * profiles row; when that happens there is nowhere to record that they
     * opted out, and silently suppressing only their cars would lose the
     * decision the moment their car list changed. Inserting a row instead is
     * not an option either — profiles carries NOT NULL columns with no
     * defaults, so it would mean inventing owner data.
     *
     * @param int $ownerId Owner user ID
     * @return void
     * @throws CarDatabaseException If the owner has no profiles row, or the
     *                              read or write fails
     */
    private function suppressOwnerProfile(int $ownerId): void
    {
        $current = $this->repo->findProfileEmailSuppressed($ownerId);

        if ($current === null) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, sprintf(
                'CarVerificationManager::setSuppressedForOwner: owner %d has no profiles row; '
                . 'opt-out aborted before any car was suppressed',
                $ownerId
            ));
            throw new CarDatabaseException(
                'Your opt-out could not be recorded. Please try again or contact support.'
            );
        }

        if ($current === 1) {
            return; // Already opted out — idempotent, expected, not an anomaly.
        }

        if (!$this->repo->updateProfileEmailSuppressed($ownerId, true)) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, sprintf(
                'CarVerificationManager::setSuppressedForOwner: profiles.email_suppressed update '
                . 'affected 0 rows for owner %d (row read as %d moments earlier): %s',
                $ownerId,
                $current,
                $this->repo->errorString() ?: 'unknown'
            ));
            throw new CarDatabaseException(
                'Your opt-out could not be recorded. Please try again or contact support.'
            );
        }
    }
}
