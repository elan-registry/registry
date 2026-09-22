<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\Exceptions\CarValidationException;

/**
 * VerificationEligibility - Explains why an already-loaded car row is no
 * longer due a verification email
 *
 * Extracted from app/admin/index.php's former eligibilitySkipReason()
 * function (#1884) so the rule set is directly unit-testable by constructing
 * car-data objects and asserting on real return values, instead of only via
 * source-text inspection of an unrequireable file.
 *
 * This encodes the SAME rules as {@see CarRepository::findVerificationEligible()}'s
 * WHERE clause — it is not a second, independent definition of eligibility
 * and must never be allowed to become one. If that SQL changes, this changes
 * with it.
 *
 * It exists only because the preview (GET) and the send (POST) are two
 * separate requests, and a car can leave the eligible set in between: the
 * owner verifies or edits it, a bounce webhook fires, they opt out, or the
 * car is marked sold. Re-running the query would tell us the row is gone
 * from the result set but not WHY — a set-based answer cannot explain one
 * specific row — and the admin report needs a per-car reason. So the check
 * is applied in PHP against the row already loaded by findById(), with no
 * second query.
 *
 * The owner-liveness clauses of the SQL (INNER JOIN users, the `noowner`
 * exclusion) are deliberately NOT duplicated here: they need a join this
 * class has no row for. They stay enforced downstream —
 * {@see CarVerificationSendService::sendOne()} loads the owner and returns a
 * failure result both when the users row is gone AND when it resolves to the
 * `noowner` system account (a live row, so the "gone" case alone does not
 * catch it — see that method's own comment). Either way this lands in the
 * report's Failed section rather than Skipped.
 *
 * @package ElanRegistry\Car
 * @since v2.30.3
 * @see https://github.com/elan-registry/registry/issues/1884
 */
final class VerificationEligibility
{
    // No instances — this is a pure-function holder, like CarValidator's
    // static validation methods.
    private function __construct()
    {
    }

    /**
     * @param object $carData Car row as loaded by CarRepository::findById()
     * @return string|null Short reason the car is no longer eligible, or null if it still is
     * @throws CarValidationException If the row carries a malformed timestamp (via isFresh(), or a
     *                                 malformed/zero-date verification_attempts_since)
     */
    public static function skipReason(object $carData): ?string
    {
        // cars.solddate IS NULL
        if (!empty($carData->solddate)) {
            return 'Marked sold';
        }

        // cars.email_bounced = 0
        if (!empty($carData->email_bounced)) {
            return 'Email bounced';
        }

        // cars.email_suppressed = 0
        if (!empty($carData->email_suppressed)) {
            return 'Email suppressed';
        }

        // cars.email IS NOT NULL AND cars.email != ''
        if (trim((string) ($carData->email ?? '')) === '') {
            return 'No email on file';
        }

        // cars.user_id IS NOT NULL
        if ((int) ($carData->user_id ?? 0) <= 0) {
            return 'No owner on file';
        }

        // NOT freshnessSql('cars') — the PHP counterpart of the same rule, so
        // the staleness definition is not re-derived by hand here.
        if (CarRepository::isFresh(
            $carData->last_verified ?? null,
            (string) ($carData->owner_last_updated ?? '')
        )) {
            return 'Recently verified or updated';
        }

        // Mirrors findVerificationEligible()'s attempt-cap clause: 2 sends
        // per rolling 12-month window, then the car waits out the rest of
        // the year. Kept in sync with that SQL and with
        // incrementVerificationAttempts()'s own reset logic.
        //
        // strtotime() failure is NOT treated as "outside the window": PHP's
        // strtotime() returns false on a malformed/unparseable value, and
        // `false > strtotime('-1 year')` evaluates to false — silently
        // treating a corrupt timestamp as "not within the window" would
        // bypass the attempt cap entirely for that car on every batch.
        // Throwing here routes into the caller's EligibilityCheckFailed
        // catch, which skips the car rather than risk over-sending.
        $attemptsSince = $carData->verification_attempts_since ?? null;
        if ($attemptsSince !== null) {
            $attemptsSinceValue = (string) $attemptsSince;

            // MySQL's zero-date needs its own guard BEFORE strtotime(): PHP
            // does not return false for '0000-00-00 00:00:00', it returns a
            // valid (bogus, negative) timestamp that sails past the
            // `$sinceTs === false` check below and then compares as "older
            // than a year", bypassing the cap exactly as a corrupt value
            // would. Same trap, same guard, as
            // VerificationSettings::lastCronRequestAt() applies to its own
            // column.
            if (str_starts_with($attemptsSinceValue, '0000-00-00')) {
                throw new CarValidationException(
                    'VerificationEligibility::skipReason: zero-date verification_attempts_since '
                    . var_export($attemptsSince, true) . ' on car ' . (int) ($carData->id ?? 0)
                );
            }

            $sinceTs = strtotime($attemptsSinceValue);
            if ($sinceTs === false) {
                throw new CarValidationException(
                    'VerificationEligibility::skipReason: malformed verification_attempts_since '
                    . var_export($attemptsSince, true) . ' on car ' . (int) ($carData->id ?? 0)
                );
            }
            if ($sinceTs > strtotime('-1 year') && (int) ($carData->verification_attempts ?? 0) >= 2) {
                return 'Attempt cap reached for this year';
            }
        }

        return null;
    }
}
