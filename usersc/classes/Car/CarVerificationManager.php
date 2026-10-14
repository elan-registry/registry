<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use DateTime;
use ElanRegistry\AppConstants;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\LogCategories;

/**
 * CarVerificationManager - Verification state of cars and email flags of owners
 *
 * Extracted from Car.php to provide focused, testable verification logic.
 * For a car, it sets verification codes, marks the car as verified, and marks
 * it as sold.
 *
 * For an owner, it writes the email deliverability flags on the owner's
 * `profiles` row: bounced, blocked and opted out. These methods are
 * setBouncedFromEvent(), setSuppressedFromEvent(), setSuppressedForOwner(),
 * setBouncedForOwner(), the clear and resume methods, and
 * clearFlagsForConfirmedEmailChange(). Each flag
 * change also writes one audit row to `logs` through DeliverabilityFlagLog.
 *
 * @package ElanRegistry\Car
 * @since v2.15.0
 * @see https://github.com/unibrain1/elanregistry/issues/463
 */
class CarVerificationManager
{
    public function __construct(
        private CarRepository $repo,
        private DeliverabilityFlagLog $flagLog = new DeliverabilityFlagLog(),
    ) {}

    /**
     * Execute a repository update, translating failures into CarDatabaseException
     *
     * @param callable $update Repository call to execute; returns true on success
     * @param string $logCategory LogCategories constant, put in the exception message as a prefix
     * @param string $failureMessage User message ($userMessage) of the exception if the update call itself throws
     * @param string $context Exception message prefix used when the update call throws
     * @param int $carId Car id being updated, included in the exception message for traceability
     * @return bool True on success
     * @throws CarDatabaseException If the update call throws (chained as previous) or the
     *                              repository reports failure. The technical message holds
     *                              the detail. The caller logs it after any rollback.
     */
    private function persist(callable $update, string $logCategory, string $failureMessage, string $context, int $carId): bool
    {
        // No logger() call here: callers often run this inside a transaction,
        // and logger() writes through the same connection, so a rollback
        // would delete the log row. The detail goes into the exception
        // message instead. Every caller logs getMessage() after the rollback.
        try {
            $updateSuccess = $update();
        } catch (\Throwable $e) {
            throw new CarDatabaseException(
                sprintf('[%s] %s for car %d (%s): %s', $logCategory, $context, $carId, get_class($e), $e->getMessage()),
                0,
                $e,
                $failureMessage
            );
        }

        if (!$updateSuccess) {
            throw new CarDatabaseException(
                sprintf(
                    '[%s] Database update failed for car %d: Repository returned false: %s',
                    $logCategory,
                    $carId,
                    $this->repo->errorString() ?: 'unknown'
                ),
                0,
                null,
                'Unable to save changes. Please try again.'
            );
        }

        return true;
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
     * Record a bounce from a delivery event on an owner's profile
     *
     * Sets `email_bounced` with the address and `email_bounced_at` (#2101).
     * It skips, with a log row, an owner who has no profiles row.
     *
     * @param int $ownerId Owner user ID
     * @param string $bouncedAddress The address that bounced
     * @param string $bouncedAt When the bounce happened, in AppConstants::DATETIME_FORMAT
     * @param string $source One of {@see DeliverabilityFlagLog::SOURCES}, normally
     *                       `webhook`, `reconciliation` or `import`
     * @return bool True when a bounce now applies and none applied before. A
     *              bounce stored for an old address did not apply.
     * @throws \InvalidArgumentException If $source is not a permitted value
     * @throws CarValidationException If the address is empty or the time is not valid
     * @throws CarDatabaseException If a query fails
     */
    public function setBouncedFromEvent(int $ownerId, string $bouncedAddress, string $bouncedAt, string $source): bool
    {
        // EmailEventApplier::apply() calls this inside its transaction. The
        // profile lock then holds until the event row and this flag commit.
        DeliverabilityFlagLog::assertValidSource($source);

        $old = $this->repo->setProfileBounced($ownerId, $bouncedAddress, $bouncedAt);
        if ($old === null) {
            $this->logEventSkip('setBouncedFromEvent', $ownerId, 'the owner has no profiles row');
            return false;
        }

        return $this->flagLog->record(0, $ownerId, DeliverabilityFlagLog::FLAG_BOUNCED, $old, 1, $source);
    }

    /**
     * Record a block from a delivery event on an owner's profile
     *
     * A spam complaint, an unsubscribe or a blocklist entry means Brevo will
     * not deliver to the address. This method sets `email_blocked` with the
     * address, the time and the cause. It skips, with a log row, an owner
     * who has no profiles row.
     *
     * @param int $ownerId Owner user ID
     * @param string $blockedAddress The blocked address
     * @param string|null $cause One of {@see CarRepository::BLOCKED_CAUSES}, or null when unknown
     * @param string $blockedAt When the block happened, in AppConstants::DATETIME_FORMAT
     * @param string $source One of {@see DeliverabilityFlagLog::SOURCES}, normally
     *                       `webhook`, `reconciliation` or `import`
     * @return bool True when a block now applies and none applied before. A
     *              block stored for an old address did not apply.
     * @throws \InvalidArgumentException If $source is not a permitted value
     * @throws CarValidationException If the address is empty, the cause is not permitted or the time is not valid
     * @throws CarDatabaseException If a query fails
     */
    public function setSuppressedFromEvent(
        int $ownerId,
        string $blockedAddress,
        ?string $cause,
        string $blockedAt,
        string $source
    ): bool {
        // EmailEventApplier::apply() calls this inside its transaction. The
        // profile lock then holds until the event row and this flag commit.
        DeliverabilityFlagLog::assertValidSource($source);

        $old = $this->repo->setProfileBlocked($ownerId, $blockedAddress, $cause, $blockedAt);
        if ($old === null) {
            $this->logEventSkip('setSuppressedFromEvent', $ownerId, 'the owner has no profiles row');
            return false;
        }

        return $this->flagLog->record(0, $ownerId, DeliverabilityFlagLog::FLAG_BLOCKED, $old, 1, $source);
    }

    /**
     * Log a delivery event that changed no profile. The row has no address.
     *
     * @param string $method Calling method name
     * @param int $ownerId Owner user ID, also the log row's user ID
     * @param string $why Why the event changed no profile
     */
    private function logEventSkip(string $method, int $ownerId, string $why): void
    {
        // Brevo event tracking, not a bounce: the same category as the
        // applier's log of an event that it did not record, so all skipped
        // events of the three event paths land in one searchable category.
        logger($ownerId, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
            'CarVerificationManager::%s: event for owner %d not applied to a profile: %s',
            $method,
            $ownerId,
            $why
        ));
    }

    /**
     * Record an owner's opt-out from verification email (owner-initiated)
     *
     * Sets `profiles.email_opted_out` and `email_opted_out_at` on the owner's
     * one profile row. It writes one audit row with source `owner` when the
     * flag changes. Nothing
     * is written to a car: verification reads the profile, so a car the
     * owner registers later is also excluded (#2319). The opt-out is set
     * also when `email_blocked` or `email_bounced` is set, so it outlives a
     * later clear of those flags. A repeat opt-out changes nothing.
     *
     * Runs no transaction of its own. The caller opens one, so the profile
     * write and the audit row commit together.
     *
     * @param int $ownerId Owner user ID. The owner is also the acting user.
     * @return bool True when the opt-out changed from 0 to 1
     * @throws CarDatabaseException If the owner has no profiles row (nothing
     *                              is written), or a query fails.
     *                              getUserMessage() is safe to show.
     */
    public function setSuppressedForOwner(int $ownerId): bool
    {
        $old = $this->requireProfile(
            $this->repo->setProfileOptedOut($ownerId, true),
            $ownerId,
            'setSuppressedForOwner',
            'Your opt-out could not be recorded. Please try again or contact support.'
        );

        return $this->flagLog->record(
            $ownerId,
            $ownerId,
            DeliverabilityFlagLog::FLAG_OPTED_OUT,
            $old,
            1,
            DeliverabilityFlagLog::SOURCE_OWNER
        );
    }

    /**
     * Mark an owner's address as bounced (admin Mark Bounced, #1884)
     *
     * Sets `profiles.email_bounced`, `email_bounced_address` and
     * `email_bounced_at` (now) on the owner's one profile row, under a row
     * lock. It writes one audit row with source `admin` when the flag changes.
     * Nothing is written
     * to a car. A repeat on the same address changes nothing. A bounce on
     * another address is rewritten with the new address and time.
     *
     * Runs no transaction of its own. The caller opens one.
     *
     * NO user_id IN THE WRITE PATH: the profile UPDATE is keyed by user_id and
     * never sets it, so this admin action cannot reassign anything.
     *
     * @param int $ownerId Owner user ID
     * @param string $bouncedAddress The owner's current address
     * @param int $actingUserId The admin who took the action
     * @return bool True when a bounce now applies and none applied before. A
     *              bounce stored for an old address did not apply.
     * @throws CarValidationException If $bouncedAddress is empty
     * @throws CarDatabaseException If the owner has no profiles row (nothing
     *                              is written), or a query fails
     */
    public function setBouncedForOwner(int $ownerId, string $bouncedAddress, int $actingUserId): bool
    {
        if (trim($bouncedAddress) === '') {
            throw new CarValidationException('A bounced email address is required to mark this owner as bounced.');
        }

        $old = $this->requireProfile(
            $this->repo->setProfileBounced($ownerId, $bouncedAddress, date(AppConstants::DATETIME_FORMAT)),
            $ownerId,
            'setBouncedForOwner',
            'The bounce could not be recorded. Please try again or contact support.'
        );

        return $this->flagLog->record(
            $actingUserId,
            $ownerId,
            DeliverabilityFlagLog::FLAG_BOUNCED,
            $old,
            1,
            DeliverabilityFlagLog::SOURCE_ADMIN
        );
    }

    /**
     * Clear an owner's bounce (admin Clear Bounced, #1884)
     *
     * Clears `profiles.email_bounced`, its address and time on the owner's one
     * profile row, under a row lock, and sets `email_bounce_cleared_at`, so
     * soft bounces before the clear do not escalate again. Writes one audit
     * row with source `admin` when the flag changes. Nothing is written to a car.
     *
     * NOT TO BE CONFUSED WITH {@see self::clearFlagsForConfirmedEmailChange()},
     * the automatic clear after a confirmed email change to another address.
     *
     * Runs no transaction of its own. The caller opens one.
     *
     * @param int $ownerId Owner user ID
     * @param int $actingUserId The admin who took the action
     * @return bool True when the flag changed from 1 to 0
     * @throws CarDatabaseException If the owner has no profiles row (nothing
     *                              is written), or a query fails
     */
    public function clearBouncedForOwner(int $ownerId, int $actingUserId): bool
    {
        $old = $this->requireProfile(
            $this->repo->clearProfileBounced($ownerId),
            $ownerId,
            'clearBouncedForOwner',
            'The bounce could not be cleared. Please try again or contact support.'
        );

        return $this->flagLog->record(
            $actingUserId,
            $ownerId,
            DeliverabilityFlagLog::FLAG_BOUNCED,
            $old,
            0,
            DeliverabilityFlagLog::SOURCE_ADMIN
        );
    }

    /**
     * Clear an owner's bounce and block after a confirmed email change (#2356)
     *
     * Calls {@see CarRepository::clearFlagsForConfirmedEmailChange()}, which
     * locks the profile row and clears `email_bounced` and `email_blocked`
     * only when the normalized `users.email` differs from
     * `email_address_seen`. The opt-out stays. For each flag that changes
     * from 1 to 0, this method writes one audit row with source
     * `email_change`. The verifySuccess hook (sync_owner_email_on_verify.php)
     * is the only caller.
     *
     * A cleared flag that had no recorded address is a data anomaly. This
     * method logs it with the user ID and the flag name only.
     *
     * Runs its own transaction, so the row lock, the writes and the audit
     * rows commit together. Inside an outer transaction, the repository
     * joins it. Then the anomaly log runs after this method's commit, but
     * still inside the outer transaction. An outer rollback also removes
     * the anomaly row.
     *
     * @param int $actingUserId User who made the change (the owner)
     * @param int $userId       Owner user ID
     * @return bool True when one or more flags changed from 1 to 0
     * @throws CarDatabaseException If a query or an audit row insert fails.
     *                              Nothing is written.
     */
    public function clearFlagsForConfirmedEmailChange(int $actingUserId, int $userId): bool
    {
        $this->repo->beginTransaction();
        try {
            $result = $this->repo->clearFlagsForConfirmedEmailChange($userId);
            $changed = $result !== null && $result['changed'];
            $bouncedCleared = $changed && $result['bounced'] === 1;
            $blockedCleared = $changed && $result['blocked'] === 1;

            if ($bouncedCleared) {
                $this->flagLog->record(
                    $actingUserId,
                    $userId,
                    DeliverabilityFlagLog::FLAG_BOUNCED,
                    1,
                    0,
                    DeliverabilityFlagLog::SOURCE_EMAIL_CHANGE
                );
            }
            if ($blockedCleared) {
                $this->flagLog->record(
                    $actingUserId,
                    $userId,
                    DeliverabilityFlagLog::FLAG_BLOCKED,
                    1,
                    0,
                    DeliverabilityFlagLog::SOURCE_EMAIL_CHANGE
                );
            }
            $this->repo->commit();
        } catch (\Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }

        // After the commit: logger() writes through the same connection, so
        // a rollback would delete an anomaly row written inside the
        // transaction.
        if ($bouncedCleared && $result['bouncedHadNoAddress']) {
            $this->logFlagWithNoAddress($userId, DeliverabilityFlagLog::FLAG_BOUNCED);
        }
        if ($blockedCleared && $result['blockedHadNoAddress']) {
            $this->logFlagWithNoAddress($userId, DeliverabilityFlagLog::FLAG_BLOCKED);
        }

        return $bouncedCleared || $blockedCleared;
    }

    /**
     * Log a cleared flag that had no recorded address (data anomaly)
     *
     * The row holds the user ID and the flag name only. It holds no address.
     */
    private function logFlagWithNoAddress(int $userId, string $flag): void
    {
        logger($userId, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, sprintf(
            'CarVerificationManager::clearFlagsForConfirmedEmailChange: profile of user %d had %s=1 '
            . 'with no recorded address. The confirmed email change cleared it (data-integrity anomaly).',
            $userId,
            $flag
        ));
    }

    /**
     * Clear an owner's block and opt-out (admin Clear Suppression)
     *
     * Until #2357 gives the admin separate Clear Block and Clear Opt-out
     * actions, this one action clears both `email_blocked` (with its
     * address, time and cause) and `email_opted_out` (with its time) on the
     * owner's one profile row. It writes one audit row per flag that changed,
     * with source `admin`. Nothing is written to a car. The bounce stays.
     *
     * Runs no transaction of its own. The caller opens one.
     *
     * @param int $ownerId Owner user ID
     * @param int $actingUserId The admin who took the action
     * @return bool True when at least one flag changed from 1 to 0
     * @throws CarDatabaseException If the owner has no profiles row (nothing
     *                              is written), or a query fails
     */
    public function clearSuppressedForOwner(int $ownerId, int $actingUserId): bool
    {
        $userMessage = 'The opt-out could not be cleared. Please try again or contact support.';

        $oldBlocked = $this->requireProfile(
            $this->repo->clearProfileBlocked($ownerId),
            $ownerId,
            'clearSuppressedForOwner',
            $userMessage
        );
        $oldOptedOut = $this->requireProfile(
            $this->repo->setProfileOptedOut($ownerId, false),
            $ownerId,
            'clearSuppressedForOwner',
            $userMessage
        );

        $blockedChanged = $this->flagLog->record(
            $actingUserId,
            $ownerId,
            DeliverabilityFlagLog::FLAG_BLOCKED,
            $oldBlocked,
            0,
            DeliverabilityFlagLog::SOURCE_ADMIN
        );
        $optedOutChanged = $this->flagLog->record(
            $actingUserId,
            $ownerId,
            DeliverabilityFlagLog::FLAG_OPTED_OUT,
            $oldOptedOut,
            0,
            DeliverabilityFlagLog::SOURCE_ADMIN
        );

        return $blockedChanged || $optedOutChanged;
    }

    /**
     * Resume verification email for the owner (Account Settings, #1895)
     *
     * Clears only `profiles.email_opted_out` and `email_opted_out_at`, the
     * flag that the owner set. A block (`email_blocked`) and a bounce stay,
     * because the owner did not cause them. An admin, or a confirmed change
     * to another address, clears a block or a bounce.
     * Writes one audit row with source `owner` when the flag changes.
     * Nothing is written to a car.
     *
     * Runs no transaction of its own. The caller opens one. The profile row
     * is locked before the write, so a double-submit is serialized.
     *
     * @param int $ownerId Owner user ID (the logged-in user, from the session).
     *                     The owner is also the acting user.
     * @return bool True when the opt-out changed from 1 to 0
     * @throws CarDatabaseException If the owner has no profiles row (nothing
     *                              is written), or a query fails.
     *                              getUserMessage() is safe to show.
     */
    public function clearSuppressedForOwnerByOwner(int $ownerId): bool
    {
        $old = $this->requireProfile(
            $this->repo->setProfileOptedOut($ownerId, false),
            $ownerId,
            'clearSuppressedForOwnerByOwner',
            'Verification emails could not be resumed. Please try again or contact support.'
        );

        return $this->flagLog->record(
            $ownerId,
            $ownerId,
            DeliverabilityFlagLog::FLAG_OPTED_OUT,
            $old,
            0,
            DeliverabilityFlagLog::SOURCE_OWNER
        );
    }

    /**
     * Turn a profile writer's "no profiles row" result into an exception
     *
     * No logger() call here: the owner methods run inside the caller's
     * transaction, and logger() writes through the same connection, so a
     * rollback would delete the log row. The detail goes into the exception
     * message, and the caller logs it after the rollback.
     *
     * @param int|null $old The old flag value that the writer returned
     * @return int The old flag value (0 or 1)
     * @throws CarDatabaseException If $old is null (the owner has no profiles row)
     */
    private function requireProfile(?int $old, int $ownerId, string $method, string $userMessage): int
    {
        if ($old === null) {
            throw new CarDatabaseException(
                sprintf(
                    'CarVerificationManager::%s: owner %d has no profiles row; nothing was written',
                    $method,
                    $ownerId
                ),
                0,
                null,
                $userMessage
            );
        }

        return $old;
    }
}
