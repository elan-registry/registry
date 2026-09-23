<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use ElanRegistry\Car\VerificationEligibility;
use ElanRegistry\Exceptions\CarValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral tests for {@see VerificationEligibility::skipReason()} (#1884),
 * plus one remaining source-inspection guard for app/admin/index.php's
 * `verification_send_batch` server-side batch-size cap.
 *
 * TWO STYLES HERE, DELIBERATELY. skipReason() now lives in a real,
 * requireable class (it was extracted out of app/admin/index.php's former
 * eligibilitySkipReason() function specifically so it could be tested this
 * way), so every branch below is exercised by constructing a car-data object
 * and asserting on the real return value — not by grepping source text. A
 * source-text assertion (e.g. asserting the literal string ">= 2" appears
 * somewhere in the file) passes straight through an actual logic inversion
 * — `<= 2` instead of `>= 2`, or a flipped `&&`/`||` — while failing on a
 * harmless rename or reformat. Calling the real method and checking its
 * return value catches the inversion and ignores the reformat.
 *
 * The batch-size cap still lives inline in app/admin/index.php, which cannot
 * be require()'d in a unit test — it needs the full UserSpice framework
 * bootstrap (securePage(), $abs_us_root, etc.), the same constraint
 * tests/unit/admin/AdminContactSanitizationTest.php documents for
 * process-admin-contact.php. That one remains a source-inspection guard: it
 * cannot prove the cap runs, only that it is present, so a regression
 * removing it is still caught.
 *
 * The attempt cap: findVerificationEligible()'s SQL and skipReason()'s PHP
 * mirror must both enforce "at most 2 sends per rolling 12-month window"
 * (CarRepositoryTest::testFindVerificationEligibleQueryContainsExpectedConditions()
 * covers the SQL side of the same rule) — without it, a stale car re-enters
 * every batch with no limit, which is exactly the defect this cap exists to
 * close.
 *
 * The 60-day cooldown is the cap's other half and is mirrored the same way: a
 * send writes only vericode_sent_at, so without it the staleness rule alone
 * re-admits the identical batch on consecutive nights and both allowed yearly
 * sends land ~24 hours apart.
 */
final class VerificationEligibilitySkipReasonTest extends TestCase
{
    private const INDEX_FILE = 'app/admin/index.php';

    private function readIndexSource(): string
    {
        $filePath = dirname(__DIR__, 3) . '/' . self::INDEX_FILE;
        $this->assertFileExists($filePath, 'app/admin/index.php must exist (#1884)');

        return (string) file_get_contents($filePath);
    }

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
            'email_bounced'               => 0,
            'email_suppressed'            => 0,
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

    public function testReturnsEmailBouncedWhenBouncedFlagIsSet(): void
    {
        $car = $this->makeEligibleCar(['email_bounced' => 1]);

        $this->assertSame('Email bounced', VerificationEligibility::skipReason($car));
    }

    public function testReturnsEmailSuppressedWhenSuppressedFlagIsSet(): void
    {
        $car = $this->makeEligibleCar(['email_suppressed' => 1]);

        $this->assertSame('Email suppressed', VerificationEligibility::skipReason($car));
    }

    public function testReturnsNoEmailOnFileWhenEmailIsEmpty(): void
    {
        $car = $this->makeEligibleCar(['email' => '']);

        $this->assertSame('No email on file', VerificationEligibility::skipReason($car));
    }

    public function testReturnsNoEmailOnFileWhenEmailIsWhitespaceOnly(): void
    {
        $car = $this->makeEligibleCar(['email' => '   ']);

        $this->assertSame('No email on file', VerificationEligibility::skipReason($car));
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
        // A car that is BOTH sold AND bounced must report "Marked sold" —
        // the first-matching-branch ordering the docblock promises, not
        // whichever branch happens to be reordered by a future edit.
        $car = $this->makeEligibleCar([
            'solddate'      => '2026-01-01',
            'email_bounced' => 1,
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
            'email_bounced'               => 0,
            'email_suppressed'            => 0,
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

    public function testVerificationSendBatchCapsSubmittedIdsToConfiguredBatchSize(): void
    {
        $source = $this->readIndexSource();

        // The GET-time preview only renders batchSize() cars, but the POST
        // handler reads car_ids[] straight from the request. Without a
        // server-side cap, a hand-crafted POST carrying hundreds of ids turns
        // into hundreds of blocking synchronous Brevo calls in one request.
        $this->assertStringContainsString(
            '$vsSendBatchSize = (new VerificationSettings(dbi()))->batchSize();',
            $source,
            "The verification_send_batch handler must read the configured batch size rather "
                . 'than trusting the size of the submitted car_ids[] array'
        );

        $this->assertStringContainsString(
            '$submittedIds = array_slice($submittedIds, 0, $vsSendBatchSize);',
            $source,
            'An oversized car_ids[] must be truncated to the configured batch size before the '
                . 'per-car send loop runs'
        );

        // The truncation must be visible, not silent: a batch that quietly
        // drops half its cars with no log line and no admin-facing message is
        // indistinguishable from one that sent them all.
        $capBlockStart = strpos($source, 'count($submittedIds) > $vsSendBatchSize');
        $this->assertIsInt($capBlockStart, 'The cap must be applied with an explicit size comparison');
        $capBlock = substr($source, $capBlockStart, 900);

        $this->assertStringContainsString(
            'LogCategories::LOG_CATEGORY_CAR_VERIFICATION',
            $capBlock,
            'Truncation must be logged under the car-verification category'
        );
        $this->assertStringContainsString(
            '$errors[]',
            $capBlock,
            'Truncation must also surface to the admin, not just the system log'
        );
    }

    public function testFindVerificationEligibleSourceAlsoEnforcesTheSameCap(): void
    {
        $repoFile = dirname(__DIR__, 3) . '/usersc/classes/Car/CarRepository.php';
        $this->assertFileExists($repoFile);
        $source = (string) file_get_contents($repoFile);

        // Pinning the SQL fragment here too (in addition to
        // CarRepositoryTest's mock-based assertion) keeps this test's own
        // "both sides agree" claim self-contained and independently
        // verifiable without cross-referencing another test file.
        $this->assertStringContainsString(
            'cars.verification_attempts < 2',
            $source,
            'findVerificationEligible() must enforce the same 2-send cap VerificationEligibility::skipReason() checks'
        );

        // The FRD's 60-day cooldown was specified but never transcribed into
        // the shipped SQL, and the omission was invisible to every existing
        // test: staleness alone still matched, so the query kept returning
        // rows. Pinned here alongside the cap because the two clauses are one
        // rule — the cooldown spreads sends out, the cap bounds them.
        $this->assertStringContainsString(
            'cars.vericode_sent_at < NOW() - INTERVAL 60 DAY',
            $source,
            'findVerificationEligible() must enforce the same 60-day re-send cooldown '
                . 'VerificationEligibility::skipReason() checks'
        );
    }
}
