<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use ElanRegistry\Car\VerificationEligibility;
use ElanRegistry\Exceptions\CarValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral tests for {@see VerificationEligibility::skipReason()} (#1884).
 *
 * Each branch is exercised by constructing a car-data object and asserting on
 * the real return value, not by grepping source text. A source-text assertion
 * passes straight through a logic inversion (`<= 2` instead of `>= 2`, a
 * flipped `&&`/`||`) and fails on a harmless rename. Calling the real method
 * catches the inversion and ignores the reformat.
 *
 * The attempt cap: findVerificationEligible()'s SQL and skipReason()'s PHP
 * mirror must both enforce "at most 2 sends per rolling 12-month window".
 * CarVerificationEligibilityTest (integration) runs the SQL side against a
 * live database.
 *
 * The 60-day cooldown is the cap's other half and is mirrored the same way: a
 * send writes only vericode_sent_at, so without it the staleness rule alone
 * re-admits the identical batch on consecutive nights and both allowed yearly
 * sends land ~24 hours apart.
 *
 * The batch-size cap of `verification_send_batch` is tested in
 * VerificationBatchSenderTest (capToBatchSize()).
 */
final class VerificationEligibilitySkipReasonTest extends TestCase
{
    /**
     * Build a car-data row that passes every branch (i.e. IS eligible),
     * letting each test override only the field(s) it means to exercise.
     *
     * @param array<string, mixed> $overrides
     */
    private function makeEligibleCar(array $overrides = []): object
    {
        $defaults = [
            'id'                          => 42,
            'solddate'                    => null,
            'email'                       => 'owner@example.com',
            'user_id'                     => 7,
            'last_verified'               => null,
            'owner_last_updated'          => date('Y-m-d H:i:s', strtotime('-2 years')),
            'vericode_sent_at'            => null,
            'verification_attempts'       => 0,
            'verification_attempts_since' => null,
        ];

        return (object) array_merge($defaults, $overrides);
    }

    public function testReturnsNullWhenEveryConditionIsSatisfied(): void
    {
        $this->assertNull(VerificationEligibility::skipReason($this->makeEligibleCar()));
    }

    public function testReturnsMarkedSoldWhenSolddateIsSet(): void
    {
        $car = $this->makeEligibleCar(['solddate' => '2026-01-01']);

        $this->assertSame('Marked sold', VerificationEligibility::skipReason($car));
    }

    /**
     * The deliverability flags belong to the owner's profile (#2101). A single
     * `cars` row cannot supply them, so skipReason() does not judge them.
     * CarVerificationSendService::sendOne() reads the profile instead. A car
     * object that still carries the old per-car properties must not change
     * the result.
     */
    public function testIgnoresLegacyPerCarFlagPropertiesBecauseTheProfileOwnsThem(): void
    {
        $car = $this->makeEligibleCar(['email_bounced' => 1, 'email_blocked' => 1, 'email_opted_out' => 1]);

        $this->assertNull(VerificationEligibility::skipReason($car));
    }

    /**
     * The send mails the owner's `users.email`. `cars.email` is only a copy,
     * so an empty copy must not skip the car. sendOne() checks the account email.
     */
    public function testIgnoresEmptyCarEmailBecauseTheSendUsesTheAccountEmail(): void
    {
        $this->assertNull(VerificationEligibility::skipReason($this->makeEligibleCar(['email' => ''])));
        $this->assertNull(VerificationEligibility::skipReason($this->makeEligibleCar(['email' => '   '])));
    }

    public function testReturnsNoOwnerOnFileWhenUserIdIsZero(): void
    {
        $car = $this->makeEligibleCar(['user_id' => 0]);

        $this->assertSame('No owner on file', VerificationEligibility::skipReason($car));
    }

    public function testReturnsNoOwnerOnFileWhenUserIdIsNegative(): void
    {
        $car = $this->makeEligibleCar(['user_id' => -1]);

        $this->assertSame('No owner on file', VerificationEligibility::skipReason($car));
    }

    public function testReturnsRecentlyVerifiedOrUpdatedWhenLastVerifiedIsFresh(): void
    {
        $car = $this->makeEligibleCar(['last_verified' => date('Y-m-d H:i:s', strtotime('-1 day'))]);

        $this->assertSame('Recently verified or updated', VerificationEligibility::skipReason($car));
    }

    public function testReturnsRecentlyVerifiedOrUpdatedWhenOwnerLastUpdatedIsFresh(): void
    {
        $car = $this->makeEligibleCar(['owner_last_updated' => date('Y-m-d H:i:s', strtotime('-1 day'))]);

        $this->assertSame('Recently verified or updated', VerificationEligibility::skipReason($car));
    }

    public function testChecksAreAppliedInDocumentedPriorityOrder(): void
    {
        // A car that is BOTH sold AND without an owner must report "Marked sold" —
        // the first-matching-branch ordering the docblock promises, not
        // whichever branch happens to be reordered by a future edit.
        $car = $this->makeEligibleCar([
            'solddate' => '2026-01-01',
            'user_id'  => 0,
        ]);

        $this->assertSame('Marked sold', VerificationEligibility::skipReason($car));
    }

    public function testReturnsCooldownReasonWhenSentWithinTheLastSixtyDays(): void
    {
        // The FRD's 60-day re-send cooldown. A send writes only
        // vericode_sent_at — not last_verified, not owner_last_updated — so
        // without this branch a car emailed 10 days ago still reads as stale
        // and is offered for sending again the very next night.
        $car = $this->makeEligibleCar([
            'vericode_sent_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
        ]);

        $this->assertSame(
            'Verification email sent within the last 60 days',
            VerificationEligibility::skipReason($car)
        );
    }

    public function testAllowsACarWhoseSixtyDayCooldownHasExpired(): void
    {
        // The permissive side of the same boundary: 61 days of owner silence
        // re-admits the car (subject to the attempt cap below), rather than
        // making it wait out the rest of the annual cycle.
        $car = $this->makeEligibleCar([
            'vericode_sent_at' => date('Y-m-d H:i:s', strtotime('-61 days')),
        ]);

        $this->assertNull(VerificationEligibility::skipReason($car));
    }

    public function testCooldownCheckRejectsMysqlZeroDateVericodeSentAt(): void
    {
        // Same trap as verification_attempts_since: strtotime('0000-00-00')
        // returns a valid negative timestamp rather than false, which would
        // read as "cooldown long expired" and reopen the over-send this check
        // exists to prevent.
        $car = $this->makeEligibleCar(['vericode_sent_at' => '0000-00-00 00:00:00']);

        $this->expectException(CarValidationException::class);
        VerificationEligibility::skipReason($car);
    }

    public function testCooldownCheckRejectsMalformedVericodeSentAt(): void
    {
        $car = $this->makeEligibleCar(['vericode_sent_at' => 'not a date at all']);

        $this->expectException(CarValidationException::class);
        VerificationEligibility::skipReason($car);
    }

    public function testCooldownIsCheckedBeforeTheAttemptCap(): void
    {
        // A car that is both inside its cooldown AND at the attempt cap must
        // report the cooldown — the first-matching-branch ordering, pinned so
        // a future reorder is visible rather than silent.
        $car = $this->cappedCar();
        $car->vericode_sent_at = date('Y-m-d H:i:s', strtotime('-10 days'));
        $car->verification_attempts_since = date('Y-m-d H:i:s', strtotime('-1 month'));

        $this->assertSame(
            'Verification email sent within the last 60 days',
            VerificationEligibility::skipReason($car)
        );
    }

    public function testSkipReasonEnforcesAttemptCap(): void
    {
        // The rule: at most 2 sends per rolling 12-month window. A car at the
        // cap with an in-window `since` timestamp must be skipped with a
        // specific reason, not silently re-entered into every batch.
        $car = $this->cappedCar();
        $car->verification_attempts_since = date('Y-m-d H:i:s', strtotime('-1 month'));

        $this->assertSame('Attempt cap reached for this year', VerificationEligibility::skipReason($car));
    }

    public function testSkipReasonAllowsACarBelowTheAttemptCap(): void
    {
        $car = $this->cappedCar();
        $car->verification_attempts = 1;
        $car->verification_attempts_since = date('Y-m-d H:i:s', strtotime('-1 month'));

        $this->assertNull(VerificationEligibility::skipReason($car));
    }

    public function testSkipReasonAllowsAnAtCapCarWhoseWindowHasRolledOver(): void
    {
        // Same car, but its window opened more than a year ago — the cap is
        // per rolling 12 months, so it is eligible again.
        $car = $this->cappedCar();
        $car->verification_attempts_since = date('Y-m-d H:i:s', strtotime('-13 months'));

        $this->assertNull(VerificationEligibility::skipReason($car));
    }

    public function testSkipReasonRejectsMysqlZeroDateAttemptsSince(): void
    {
        // Behavioral, not source-inspection: VerificationEligibility is a real
        // requireable class (unlike the app/admin/index.php function it was
        // extracted from), so the zero-date trap can be exercised directly.
        //
        // PHP's strtotime('0000-00-00 00:00:00') does NOT return false — it
        // returns a valid, bogus, negative timestamp. Without an explicit
        // guard ahead of strtotime(), that value sails past the
        // `$sinceTs === false` check and then compares as older than a year,
        // silently bypassing the attempt cap for that car on every batch.
        $car = $this->cappedCar();
        $car->verification_attempts_since = '0000-00-00 00:00:00';

        $this->expectException(CarValidationException::class);
        VerificationEligibility::skipReason($car);
    }

    public function testSkipReasonRejectsZeroDateWithoutTimePortion(): void
    {
        // MySQL renders a zero DATE column as '0000-00-00' with no time part;
        // the guard is a prefix check precisely so both spellings are caught.
        $car = $this->cappedCar();
        $car->verification_attempts_since = '0000-00-00';

        $this->expectException(CarValidationException::class);
        VerificationEligibility::skipReason($car);
    }

    public function testSkipReasonStillReportsAttemptCapForAValidRecentTimestamp(): void
    {
        // Control for the two zero-date tests: a well-formed, in-window
        // timestamp on an at-cap car must still produce the ordinary cap
        // reason, proving the new guard did not swallow the normal path.
        $car = $this->cappedCar();
        $car->verification_attempts_since = date('Y-m-d H:i:s', strtotime('-2 months'));

        $this->assertSame('Attempt cap reached for this year', VerificationEligibility::skipReason($car));
    }

    public function testSkipReasonRejectsMalformedAttemptsSince(): void
    {
        $car = $this->cappedCar();
        $car->verification_attempts_since = 'not a date at all';

        $this->expectException(CarValidationException::class);
        VerificationEligibility::skipReason($car);
    }

    /**
     * A car that passes every earlier clause in skipReason() and sits exactly
     * at the 2-send cap, so the attempt-cap branch is the one under test.
     */
    private function cappedCar(): object
    {
        return (object) [
            'id'                          => 4242,
            'solddate'                    => null,
            'email'                       => 'owner@example.com',
            'user_id'                     => 7,
            // Stale on both halves of the freshness rule, so isFresh() is false
            // and execution reaches the attempt-cap clause.
            'last_verified'               => date('Y-m-d H:i:s', strtotime('-5 years')),
            'owner_last_updated'          => date('Y-m-d H:i:s', strtotime('-5 years')),
            // Never sent to, so the 60-day cooldown branch is not what these
            // tests trip on — the attempt cap is the branch under test.
            'vericode_sent_at'            => null,
            'verification_attempts'       => 2,
            'verification_attempts_since' => null,
        ];
    }
}
