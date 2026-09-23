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
     * Scoped to EVERY car the owner has (findByOwner()), sold included, per
     * #1883's acceptance criteria ("syncs email_suppressed = 1 to every car
     * they have" / "An owner with four cars clicking this once means all four
     * stop" — no unsold qualifier). A sold car is never a verification-email
     * candidate (findVerificationEligible() excludes solddate IS NOT NULL), so
     * suppressing it has no effect on future sends, but the fan-out and its
     * audit trail must still cover it: the confirmation page's car count
     * (verify_car.php) is built from findByOwner() too, and a narrower
     * fan-out would silently under-deliver on what that count promises the
     * owner and leave sold cars with no EMAIL SUPPRESSED cars_hist row.
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

        foreach ($this->repo->findByOwner($ownerId) as $carRef) {
            $carData = $this->repo->findById((int) $carRef->id);

            if ($carData === null) {
                // findByOwner() listed this id moments ago, so null here
                // means the row disappeared mid-fan-out (concurrent merge or
                // GDPR erasure) — or a genuine findByOwner()/findById()
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

    /**
     * Flag every car this owner has as bounced against $bouncedAddress
     * (admin-initiated Mark Bounced, #1884). Runs no transaction of its own —
     * the caller wraps this and the per-car cars_hist inserts in one
     * transaction, matching setSuppressedForOwner()'s contract.
     *
     * Scoped to EVERY car the owner has (findByOwner()), sold included, for the
     * same reason the opt-out fan-out is: a sold car is never a
     * verification-email candidate, but the admin tool's car count is built from
     * findByOwner() too, and a narrower fan-out would under-deliver on that
     * count and leave sold cars with no EMAIL BOUNCED cars_hist row.
     *
     * TWO WRITES, TWO MEANINGS. Besides the per-car fan-out this sets
     * `profiles.email_bounced` / `profiles.email_bounced_address` once for the
     * owner. The per-car flag is the fan-out target and is also written by the
     * Brevo webhook/sync paths; the profile flag is the authoritative record
     * that *this owner's address bounced*, and stays correct when their car list
     * later changes or the per-car flags drift. The profile write happens FIRST,
     * before any car is touched, so an owner with no profiles row fails the
     * whole operation cleanly rather than part-way through the fan-out.
     *
     * ADDRESS-AWARE FAN-OUT: unlike setSuppressedForOwner(), a car that is
     * already bounced is skipped only when the recorded address is IDENTICAL.
     * A car carrying a stale address (the owner changed their email since the
     * last bounce) is rewritten to the new one rather than skipped.
     *
     * NO user_id IN THE WRITE PATH: nothing this method reaches — neither
     * setBounced()/updateBounced() nor
     * CarRepository::updateProfileEmailBounced() — includes `user_id` in any
     * UPDATE it issues. That is a structural fact about the code, not a runtime
     * check, and it is stated here so a future edit does not "helpfully" add a
     * user_id column to one of those updates: this is an admin-initiated bounce
     * recording, and it must never be able to reassign a car's owner as a side
     * effect.
     *
     * @param int $ownerId Owner user ID
     * @param string $bouncedAddress The address the bounce was reported against
     * @return array<object> PRE-CHANGE snapshots of the car rows actually
     *                        changed — each is a clone taken before setBounced()
     *                        wrote email_bounced=1 and the address onto it, so
     *                        the caller's cars_hist row records the OLD values
     *                        (matching the cars_update trigger's OLD.*
     *                        convention). Cars already bounced against this same
     *                        address are skipped — idempotent at the per-car
     *                        level. An empty array does NOT mean nothing
     *                        happened: the owner-level profile flag may still
     *                        have been written.
     * @throws CarValidationException If $bouncedAddress is empty
     * @throws CarDatabaseException If a database update fails, or if the owner
     *                              has no `profiles` row to record the bounce on
     *                              (nothing is written in that case — the check
     *                              runs before the fan-out)
     */
    public function setBouncedForOwner(int $ownerId, string $bouncedAddress): array
    {
        if ($bouncedAddress === '') {
            throw new CarValidationException('A bounced email address is required to mark this owner as bounced.');
        }

        $this->bounceOwnerProfile($ownerId, $bouncedAddress);

        $changed = [];

        foreach ($this->repo->findByOwner($ownerId) as $carRef) {
            $carData = $this->repo->findById((int) $carRef->id);

            if ($carData === null) {
                // findByOwner() listed this id moments ago, so null here
                // means the row disappeared mid-fan-out (concurrent merge or
                // GDPR erasure) — or a genuine findByOwner()/findById()
                // inconsistency. Skipping is correct (nothing to flag), but
                // it must never be silent: without this log line it is
                // indistinguishable from a real bug.
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, sprintf(
                    'CarVerificationManager::setBouncedForOwner: car %d listed for owner %d '
                    . 'but no longer readable; skipped from bounce fan-out',
                    (int) $carRef->id,
                    $ownerId
                ));
                continue;
            }

            if ((int) $carData->email_bounced === 1 && $carData->email_bounced_address === $bouncedAddress) {
                continue; // Already bounced against this exact address — idempotent, expected.
            }

            // Snapshot BEFORE mutation: setBounced() writes email_bounced=1 and
            // the address onto $carData in place, and the caller uses this
            // returned object to build a cars_hist audit row — which must record
            // the pre-change state (matches the cars_update trigger's OLD.*
            // convention), not the post-change state setBounced() leaves behind.
            $before = clone $carData;

            $this->setBounced($carData, $bouncedAddress);
            $changed[] = $before;
        }

        return $changed;
    }

    /**
     * Record the owner-level bounce on `profiles.email_bounced` /
     * `profiles.email_bounced_address` (#1884)
     *
     * Reads the current flag first, and when it is already set reads the stored
     * address too — both to keep a repeat bounce against the same address a true
     * no-op and because a MySQL UPDATE that changes nothing reports 0 affected
     * rows, which this method would otherwise mistake for a missing profiles
     * row. A flag that is already 1 but carries a DIFFERENT address is not a
     * no-op: the owner changed their email since the last bounce, so the stored
     * address is stale and is rewritten.
     *
     * A missing profiles row is an error, not a skip. `users` and `profiles`
     * are not strictly 1:1 in this schema, so an owner can genuinely have no
     * profiles row; when that happens there is nowhere to record the bounce, and
     * silently flagging only their cars would lose the record the moment their
     * car list changed. Inserting a row instead is not an option either —
     * profiles carries NOT NULL columns with no defaults, so it would mean
     * inventing owner data.
     *
     * @param int $ownerId Owner user ID
     * @param string $bouncedAddress The address the bounce was reported against
     * @return void
     * @throws CarDatabaseException If the owner has no profiles row, or the
     *                              read or write fails
     */
    private function bounceOwnerProfile(int $ownerId, string $bouncedAddress): void
    {
        $currentFlag = $this->repo->findProfileEmailBounced($ownerId);

        if ($currentFlag === null) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, sprintf(
                'CarVerificationManager::setBouncedForOwner: owner %d has no profiles row; '
                . 'bounce aborted before any car was flagged',
                $ownerId
            ));
            throw new CarDatabaseException(
                'The bounce could not be recorded. Please try again or contact support.'
            );
        }

        if ($currentFlag === 1) {
            $currentAddress = $this->repo->findProfileEmailBouncedAddress($ownerId);

            if ($currentAddress === $bouncedAddress) {
                return; // Already bounced against this exact address — idempotent, expected.
            }
            // Otherwise the stored address is stale (the owner's email changed
            // since the last bounce) — fall through and rewrite it.
        }

        if (!$this->repo->updateProfileEmailBounced($ownerId, true, $bouncedAddress)) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, sprintf(
                'CarVerificationManager::setBouncedForOwner: profiles.email_bounced update '
                . 'affected 0 rows for owner %d (row read as %d moments earlier): %s',
                $ownerId,
                $currentFlag,
                $this->repo->errorString() ?: 'unknown'
            ));
            throw new CarDatabaseException(
                'The bounce could not be recorded. Please try again or contact support.'
            );
        }
    }

    /**
     * Clear the bounced flag on an owner's profile and on every car they have
     * (admin reversal of Mark Bounced, #1884). Runs no transaction of its own —
     * the caller wraps this and the per-car cars_hist inserts in one
     * transaction, matching setBouncedForOwner()'s contract.
     *
     * NOT TO BE CONFUSED WITH {@see CarRepository::clearBouncedForUser()}. The
     * names are one word apart and the two are different methods for different
     * triggers:
     *   - CarRepository::clearBouncedForUser() is automatic and conditional. It
     *     fires when an owner confirms an email change, and clears only cars
     *     whose recorded bounced address no longer matches their new confirmed
     *     address — a single UPDATE scoped by user_id, deliberately leaving a
     *     bounce recorded against the address they still use. It does not touch
     *     the profiles row.
     *   - clearBouncedForOwner() (this method) is an admin's explicit reversal
     *     via the Mark Bounced admin tool. It is unconditional on address: every
     *     bounced car is cleared regardless of which address it bounced against,
     *     and the owner-level profiles flag is cleared too.
     *
     * TWO WRITES, TWO MEANINGS, as in setBouncedForOwner(): the profile flag is
     * the authoritative owner-level record, the per-car flags are the fan-out
     * target. The profile write happens FIRST, so an owner with no profiles row
     * fails the whole reversal cleanly rather than part-way through the fan-out.
     * The two writes are independently idempotent: a profile flag already at 0
     * is skipped without skipping the fan-out, so per-car flags that have
     * drifted out of step with the profile (e.g. set by the Brevo path) are
     * still cleared.
     *
     * @param int $ownerId Owner user ID
     * @return array<object> PRE-CHANGE snapshots of the car rows actually
     *                        changed — each is a clone taken before
     *                        clearBounced() wrote email_bounced=0 onto it, so
     *                        the caller's cars_hist row records the OLD values
     *                        (matching the cars_update trigger's OLD.*
     *                        convention). Cars that are not flagged are skipped.
     *                        An empty array does NOT mean nothing happened: the
     *                        owner-level profile flag may still have been cleared.
     * @throws CarDatabaseException If a database update fails, or if the owner
     *                              has no `profiles` row (nothing is written in
     *                              that case — the check runs before the fan-out)
     */
    public function clearBouncedForOwner(int $ownerId): array
    {
        $currentFlag = $this->repo->findProfileEmailBounced($ownerId);

        if ($currentFlag === null) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, sprintf(
                'CarVerificationManager::clearBouncedForOwner: owner %d has no profiles row; '
                . 'bounce reversal aborted before any car was cleared',
                $ownerId
            ));
            throw new CarDatabaseException(
                'The bounce could not be cleared. Please try again or contact support.'
            );
        }

        // Skipped when already 0 — both to keep a repeat reversal a true no-op
        // and because a MySQL UPDATE that changes nothing reports 0 affected
        // rows, indistinguishable from a missing row. The fan-out below runs
        // either way.
        if ($currentFlag === 1 && !$this->repo->updateProfileEmailBounced($ownerId, false, null)) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, sprintf(
                'CarVerificationManager::clearBouncedForOwner: profiles.email_bounced update '
                . 'affected 0 rows for owner %d (row read as %d moments earlier): %s',
                $ownerId,
                $currentFlag,
                $this->repo->errorString() ?: 'unknown'
            ));
            throw new CarDatabaseException(
                'The bounce could not be cleared. Please try again or contact support.'
            );
        }

        $changed = [];

        foreach ($this->repo->findByOwner($ownerId) as $carRef) {
            $carData = $this->repo->findById((int) $carRef->id);

            if ($carData === null) {
                // findByOwner() listed this id moments ago — see the same case
                // in setBouncedForOwner(). Skipping is correct, but never silent.
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, sprintf(
                    'CarVerificationManager::clearBouncedForOwner: car %d listed for owner %d '
                    . 'but no longer readable; skipped from bounce-reversal fan-out',
                    (int) $carRef->id,
                    $ownerId
                ));
                continue;
            }

            if ((int) $carData->email_bounced !== 1) {
                continue; // Nothing to clear — idempotent, expected, not an anomaly.
            }

            // Snapshot BEFORE mutation, so the caller's cars_hist row records
            // the pre-change state (cars_update trigger's OLD.* convention).
            $before = clone $carData;

            $this->clearBounced($carData);
            $changed[] = $before;
        }

        return $changed;
    }

    /**
     * Clear the suppressed flag on an owner's profile and on every car they have
     * (admin reversal of an opt-out, #1884). Runs no transaction of its own —
     * the caller wraps this and the per-car cars_hist inserts in one
     * transaction, matching setSuppressedForOwner()'s contract.
     *
     * The inverse of {@see setSuppressedForOwner()}: where that records an
     * owner-initiated opt-out, this is an admin undoing one on their behalf.
     *
     * TWO WRITES, TWO MEANINGS, as in setSuppressedForOwner(): the profile flag
     * is the authoritative owner-level record, the per-car flags are the fan-out
     * target and are also written by the Brevo webhook/sync paths. The profile
     * write happens FIRST, so an owner with no profiles row fails the whole
     * reversal cleanly rather than part-way through the fan-out. The two writes
     * are independently idempotent: a profile flag already at 0 is skipped
     * without skipping the fan-out, so per-car flags that have drifted out of
     * step with the profile are still cleared.
     *
     * @param int $ownerId Owner user ID
     * @return array<object> PRE-CHANGE snapshots of the car rows actually
     *                        changed — each is a clone taken before
     *                        clearSuppressed() wrote email_suppressed=0 onto it,
     *                        so the caller's cars_hist row records the OLD value
     *                        (matching the cars_update trigger's OLD.*
     *                        convention). Cars that are not suppressed are
     *                        skipped. An empty array does NOT mean nothing
     *                        happened: the owner-level profile flag may still
     *                        have been cleared.
     * @throws CarDatabaseException If a database update fails, or if the owner
     *                              has no `profiles` row (nothing is written in
     *                              that case — the check runs before the fan-out)
     */
    public function clearSuppressedForOwner(int $ownerId): array
    {
        $currentFlag = $this->repo->findProfileEmailSuppressed($ownerId);

        if ($currentFlag === null) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, sprintf(
                'CarVerificationManager::clearSuppressedForOwner: owner %d has no profiles row; '
                . 'opt-out reversal aborted before any car was cleared',
                $ownerId
            ));
            throw new CarDatabaseException(
                'The opt-out could not be cleared. Please try again or contact support.'
            );
        }

        // Skipped when already 0 — both to keep a repeat reversal a true no-op
        // and because a MySQL UPDATE that changes nothing reports 0 affected
        // rows, indistinguishable from a missing row. The fan-out below runs
        // either way.
        if ($currentFlag === 1 && !$this->repo->updateProfileEmailSuppressed($ownerId, false)) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, sprintf(
                'CarVerificationManager::clearSuppressedForOwner: profiles.email_suppressed update '
                . 'affected 0 rows for owner %d (row read as %d moments earlier): %s',
                $ownerId,
                $currentFlag,
                $this->repo->errorString() ?: 'unknown'
            ));
            throw new CarDatabaseException(
                'The opt-out could not be cleared. Please try again or contact support.'
            );
        }

        $changed = [];

        foreach ($this->repo->findByOwner($ownerId) as $carRef) {
            $carData = $this->repo->findById((int) $carRef->id);

            if ($carData === null) {
                // findByOwner() listed this id moments ago — see the same case
                // in setSuppressedForOwner(). Skipping is correct, but never silent.
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, sprintf(
                    'CarVerificationManager::clearSuppressedForOwner: car %d listed for owner %d '
                    . 'but no longer readable; skipped from opt-out-reversal fan-out',
                    (int) $carRef->id,
                    $ownerId
                ));
                continue;
            }

            if ((int) $carData->email_suppressed !== 1) {
                continue; // Nothing to clear — idempotent, expected, not an anomaly.
            }

            // Snapshot BEFORE mutation, so the caller's cars_hist row records
            // the pre-change state (cars_update trigger's OLD.* convention).
            $before = clone $carData;

            $this->clearSuppressed($carData);
            $changed[] = $before;
        }

        return $changed;
    }
}
