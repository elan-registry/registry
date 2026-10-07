<?php

declare(strict_types=1);

namespace Tests\Unit\Cars;

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailNoticeBuilder;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EmailNoticeBuilder::buildForOwner(), mocking CarRepository.
 *
 * Ground truth for these tests (confirmed against the real implementation,
 * not the original plan):
 * - A suppressed car's cause/date comes from
 *   CarRepository::findLatestEmailEventsByCarIdsAndEvents() filtered to
 *   EmailEventApplier::SUPPRESSION_EVENTS ('spam', 'unsubscribed') — NOT the
 *   car's latest event of any type. A matching row is CAUSE_BREVO_COMPLAINT,
 *   dated from that event's occurred_at. No matching row is CAUSE_OWNER_OPTOUT,
 *   dated from the latest 'EMAIL SUPPRESSED' cars_hist row (null if none).
 * - A bounced car's date is the later of the latest 'EMAIL BOUNCED' cars_hist
 *   row and the latest matching row from
 *   findLatestEmailEventsByCarIdsAndEvents() filtered to
 *   EmailEventApplier::HARD_BOUNCE_EVENTS. Null if neither exists.
 * - Two cars sharing an address (case-insensitively deduped): brevo_complaint
 *   wins over owner_optout regardless of order; for the same cause, the
 *   later date wins (self::laterDate(), which treats null as "the other
 *   side wins", not as earliest).
 * - A flagged car with an empty/blank `email` falls back to
 *   `email_bounced_address` only when `bounced` is true; if that's also
 *   empty, the car is skipped entirely (no address to show).
 * - Constructor takes `CarRepository $repo` (mockable, not injected via a
 *   factory or static call).
 */
#[Group('fast')]
final class EmailNoticeBuilderTest extends TestCase
{
    /**
     * @param array<object> $cars
     * @param array<int, object> $suppressionEvents Keyed by car id — the row
     *        findLatestEmailEventsByCarIdsAndEvents() returns when called with
     *        EmailEventApplier::SUPPRESSION_EVENTS
     * @param array<int, object> $hardBounceEvents Keyed by car id — the row
     *        findLatestEmailEventsByCarIdsAndEvents() returns when called with
     *        EmailEventApplier::HARD_BOUNCE_EVENTS
     */
    private function repoReturning(
        array $cars,
        array $suppressionEvents = [],
        array $hardBounceEvents = [],
        array $suppressedHist = [],
        array $bouncedHist = []
    ): CarRepository {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('findVerificationStateByOwner')->willReturn($cars);
        // findLatestEmailEventsByCarIdsAndEvents is called twice: once for
        // suppressed car ids + SUPPRESSION_EVENTS, once for bounced car ids +
        // HARD_BOUNCE_EVENTS. Distinguish by the $events argument (2nd parameter).
        $repo->method('findLatestEmailEventsByCarIdsAndEvents')
            ->willReturnCallback(function (array $carIds, array $events) use ($suppressionEvents, $hardBounceEvents) {
                if ($carIds === []) {
                    return [];
                }
                return $events === EmailEventApplier::SUPPRESSION_EVENTS ? $suppressionEvents : $hardBounceEvents;
            });
        // findLatestHistoryOperationByCarIds is called twice: once for
        // OPERATION_SUPPRESSED car ids, once for OPERATION_BOUNCED car ids.
        // Distinguish by the $operations argument (2nd parameter).
        $repo->method('findLatestHistoryOperationByCarIds')
            ->willReturnCallback(function (array $carIds, array $operations) use ($suppressedHist, $bouncedHist) {
                if ($carIds === []) {
                    return [];
                }
                return in_array('EMAIL SUPPRESSED', $operations, true) ? $suppressedHist : $bouncedHist;
            });

        return $repo;
    }

    private static function car(
        int $id,
        ?string $email,
        bool $suppressed,
        bool $bounced,
        ?string $bouncedAddress = null
    ): object {
        return (object) [
            'id' => $id,
            'email' => $email,
            'email_suppressed' => $suppressed ? 1 : 0,
            'email_bounced' => $bounced ? 1 : 0,
            'email_bounced_address' => $bouncedAddress,
        ];
    }

    private static function histRow(int $carId, string $operation, string $timestamp): object
    {
        return (object) ['car_id' => $carId, 'operation' => $operation, 'timestamp' => $timestamp];
    }

    private static function eventRow(int $carId, string $event, string $occurredAt, ?string $reason = null): object
    {
        return (object) ['car_id' => $carId, 'event' => $event, 'occurred_at' => $occurredAt, 'reason' => $reason];
    }

    // --- 0 flagged cars -----------------------------------------------

    public function testReturnsNullWhenOwnerHasNoFlaggedCars(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'clean@example.com', false, false),
        ]);
        $builder = new EmailNoticeBuilder($repo);

        $this->assertNull($builder->buildForOwner(1));
    }

    public function testReturnsNullWhenOwnerHasNoCarsAtAll(): void
    {
        $repo = $this->repoReturning([]);
        $builder = new EmailNoticeBuilder($repo);

        $this->assertNull($builder->buildForOwner(1));
    }

    // --- suppressed-only -------------------------------------------------

    public function testSuppressedOnlyCarProducesSuppressedEntryAndNoBounced(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', true, false),
        ]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertTrue($result['hasSuppressed']);
        $this->assertFalse($result['hasBounced']);
        $this->assertCount(1, $result['addresses']);
        $this->assertSame('owner@example.com', $result['addresses'][0]['address']);
        $this->assertNotNull($result['addresses'][0]['suppressed']);
        $this->assertNull($result['addresses'][0]['bounced']);
    }

    // --- bounced-only -----------------------------------------------------

    public function testBouncedOnlyCarProducesBouncedEntryAndNoSuppressed(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', false, true),
        ]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertFalse($result['hasSuppressed']);
        $this->assertTrue($result['hasBounced']);
        $this->assertCount(1, $result['addresses']);
        $this->assertNull($result['addresses'][0]['suppressed']);
        $this->assertNotNull($result['addresses'][0]['bounced']);
    }

    // --- both on different cars -------------------------------------------

    public function testBothSuppressedAndBouncedOnDifferentCarsProduceTwoAddresses(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'suppressed@example.com', true, false),
            self::car(2, 'bounced@example.com', false, true),
        ]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertTrue($result['hasSuppressed']);
        $this->assertTrue($result['hasBounced']);
        $this->assertCount(2, $result['addresses']);

        $byAddress = [];
        foreach ($result['addresses'] as $entry) {
            $byAddress[$entry['address']] = $entry;
        }
        $this->assertNotNull($byAddress['suppressed@example.com']['suppressed']);
        $this->assertNull($byAddress['suppressed@example.com']['bounced']);
        $this->assertNull($byAddress['bounced@example.com']['suppressed']);
        $this->assertNotNull($byAddress['bounced@example.com']['bounced']);
    }

    // --- both on same address ------------------------------------------

    public function testBothSuppressedAndBouncedOnSameAddressMergeIntoOneEntry(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'shared@example.com', true, true),
        ]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['addresses'], 'One car with both flags must still be one address entry');
        $this->assertTrue($result['hasSuppressed']);
        $this->assertTrue($result['hasBounced']);
        $this->assertNotNull($result['addresses'][0]['suppressed']);
        $this->assertNotNull($result['addresses'][0]['bounced']);
    }

    // --- cause = owner_optout (no matching event row) ----------------------

    public function testCauseIsOwnerOptoutWhenNoMatchingEmailEventRow(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', true, false),
        ], suppressionEvents: [], suppressedHist: [1 => self::histRow(1, 'EMAIL SUPPRESSED', '2026-05-01 10:00:00')]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertSame(EmailNoticeBuilder::CAUSE_OWNER_OPTOUT, $result['addresses'][0]['suppressed']['cause']);
        $this->assertSame('2026-05-01', $result['addresses'][0]['suppressed']['date']);
    }

    // --- cause = spam (Brevo complaint) ------------------------------------

    public function testCauseIsBrevoComplaintWhenLatestEventIsSpam(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', true, false),
        ], suppressionEvents: [1 => self::eventRow(1, 'spam', '2026-05-02 09:00:00')]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertSame(EmailNoticeBuilder::CAUSE_BREVO_COMPLAINT, $result['addresses'][0]['suppressed']['cause']);
        $this->assertSame('2026-05-02', $result['addresses'][0]['suppressed']['date']);
    }

    // --- cause = unsubscribed (Brevo complaint) ----------------------------

    public function testCauseIsBrevoComplaintWhenLatestEventIsUnsubscribed(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', true, false),
        ], suppressionEvents: [1 => self::eventRow(1, 'unsubscribed', '2026-05-03 09:00:00')]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertSame(EmailNoticeBuilder::CAUSE_BREVO_COMPLAINT, $result['addresses'][0]['suppressed']['cause']);
        $this->assertSame('2026-05-03', $result['addresses'][0]['suppressed']['date']);
    }

    // --- ambiguous/no-signal cause ------------------------------------------

    public function testAmbiguousCauseDefaultsToOwnerOptoutWithNullDate(): void
    {
        // Flag set, but no er_email_events row and no matching cars_hist row —
        // legacy data with no audit trail.
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', true, false),
        ]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertSame(EmailNoticeBuilder::CAUSE_OWNER_OPTOUT, $result['addresses'][0]['suppressed']['cause']);
        $this->assertNull($result['addresses'][0]['suppressed']['date']);
    }

    // --- the filtered lookup has no matching row: owner_optout --------------

    public function testCauseIsOwnerOptoutWhenFilteredSuppressionLookupHasNoMatchingRow(): void
    {
        // findLatestEmailEventsByCarIdsAndEvents() is filtered to
        // SUPPRESSION_EVENTS by the repository query itself, so a car whose
        // only email events are non-suppression types (e.g. a hard bounce)
        // is simply absent from its result — the suppression cause must
        // still fall back to owner_optout.
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', true, false),
        ], suppressionEvents: []);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertSame(EmailNoticeBuilder::CAUSE_OWNER_OPTOUT, $result['addresses'][0]['suppressed']['cause']);
    }

    // --- >3 addresses, overflow count ---------------------------------------

    public function testMoreThanThreeAddressesAreCappedWithCorrectOverflowCount(): void
    {
        $cars = [];
        for ($i = 1; $i <= 5; $i++) {
            $cars[] = self::car($i, "owner{$i}@example.com", true, false);
        }
        $repo = $this->repoReturning($cars);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertCount(EmailNoticeBuilder::MAX_ADDRESSES, $result['addresses']);
        $this->assertSame(2, $result['overflowCount'], '5 addresses - 3 shown = 2 overflow');
        // hasSuppressed/hasBounced must cover the FULL set, not only the 3 shown.
        $this->assertTrue($result['hasSuppressed']);
    }

    // --- overflow count unaffected by duplicate cars sharing an address -----

    public function testOverflowCountCountsDistinctAddressesNotCars(): void
    {
        // Two cars share one address, plus three more distinct addresses: 4
        // distinct addresses total, not 5 cars. Only 1 should overflow.
        $cars = [
            self::car(1, 'shared@example.com', true, false),
            self::car(2, 'shared@example.com', false, true),
            self::car(3, 'owner3@example.com', true, false),
            self::car(4, 'owner4@example.com', true, false),
            self::car(5, 'owner5@example.com', true, false),
        ];
        $repo = $this->repoReturning($cars);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        // 4 distinct addresses (shared, owner3, owner4, owner5), capped at 3, 1 overflow.
        $this->assertSame(1, $result['overflowCount']);
        $this->assertCount(3, $result['addresses']);
    }

    // --- sold car with a flag is included ------------------------------------

    public function testSoldCarWithAFlagIsStillIncluded(): void
    {
        // findVerificationStateByOwner() is documented to have no solddate
        // filter, so a sold car's row looks identical to an unsold one here —
        // confirming the builder applies no sold-car exclusion of its own.
        $repo = $this->repoReturning([
            self::car(1, 'sold-car-owner@example.com', true, false),
        ]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['addresses']);
    }

    // --- empty car email falls back to email_bounced_address (bounced only) -

    public function testEmptyCarEmailFallsBackToBouncedAddressWhenBounced(): void
    {
        $repo = $this->repoReturning([
            self::car(1, '', false, true, 'bounced-fallback@example.com'),
        ]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame('bounced-fallback@example.com', $result['addresses'][0]['address']);
    }

    public function testEmptyCarEmailAndNoBounceFlagIsSkippedWithNoFallback(): void
    {
        // Suppressed, but email is empty and there's no bounce, so no
        // email_bounced_address fallback applies either — nothing to show.
        $repo = $this->repoReturning([
            self::car(1, '', true, false),
        ]);
        $builder = new EmailNoticeBuilder($repo);

        $this->assertNull($builder->buildForOwner(1));
    }

    public function testEmptyCarEmailAndEmptyBouncedAddressIsSkippedEntirely(): void
    {
        $repo = $this->repoReturning([
            self::car(1, '', false, true, ''),
        ]);
        $builder = new EmailNoticeBuilder($repo);

        $this->assertNull($builder->buildForOwner(1));
    }

    // --- same address, cause merge rules: brevo_complaint wins --------------

    public function testBrevoComplaintWinsOverOwnerOptoutOnSharedAddress(): void
    {
        // Car 1 is owner_optout (no event), car 2 is brevo_complaint (spam event).
        // Both share the same address.
        $repo = $this->repoReturning([
            self::car(1, 'shared@example.com', true, false),
            self::car(2, 'shared@example.com', true, false),
        ], suppressionEvents: [2 => self::eventRow(2, 'spam', '2026-06-01 10:00:00')],
            suppressedHist: [1 => self::histRow(1, 'EMAIL SUPPRESSED', '2026-05-01 10:00:00')]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertCount(1, $result['addresses']);
        $this->assertSame(EmailNoticeBuilder::CAUSE_BREVO_COMPLAINT, $result['addresses'][0]['suppressed']['cause']);
    }

    public function testBrevoComplaintWinsRegardlessOfCarProcessingOrder(): void
    {
        // Same as above but with the complaint car processed first — the
        // array order is reversed vs. the previous test, proving merge order
        // doesn't matter.
        $repo = $this->repoReturning([
            self::car(2, 'shared@example.com', true, false),
            self::car(1, 'shared@example.com', true, false),
        ], suppressionEvents: [2 => self::eventRow(2, 'unsubscribed', '2026-06-01 10:00:00')],
            suppressedHist: [1 => self::histRow(1, 'EMAIL SUPPRESSED', '2026-05-01 10:00:00')]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertSame(EmailNoticeBuilder::CAUSE_BREVO_COMPLAINT, $result['addresses'][0]['suppressed']['cause']);
    }

    public function testSameCauseOnSharedAddressKeepsTheLaterDate(): void
    {
        // Both cars owner_optout, different dates — the later one wins.
        $repo = $this->repoReturning([
            self::car(1, 'shared@example.com', true, false),
            self::car(2, 'shared@example.com', true, false),
        ], suppressedHist: [
            1 => self::histRow(1, 'EMAIL SUPPRESSED', '2026-05-01 10:00:00'),
            2 => self::histRow(2, 'EMAIL SUPPRESSED', '2026-05-15 10:00:00'),
        ]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertSame(EmailNoticeBuilder::CAUSE_OWNER_OPTOUT, $result['addresses'][0]['suppressed']['cause']);
        $this->assertSame('2026-05-15', $result['addresses'][0]['suppressed']['date']);
    }

    // --- bounced date: cars_hist wins, event date is the fallback -----------

    public function testBouncedDateComesFromCarsHistWhenPresent(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', false, true),
        ], hardBounceEvents: [1 => self::eventRow(1, 'hard_bounce', '2026-07-01 09:00:00')],
            bouncedHist: [1 => self::histRow(1, 'EMAIL BOUNCED', '2026-07-05 09:00:00')]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertSame('2026-07-05', $result['addresses'][0]['bounced']['date']);
    }

    public function testBouncedDateFallsBackToEventDateWhenNoCarsHistRowAndLatestEventIsHardBounce(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', false, true),
        ], hardBounceEvents: [1 => self::eventRow(1, 'blocked', '2026-07-02 09:00:00')]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertSame('2026-07-02', $result['addresses'][0]['bounced']['date']);
    }

    public function testBouncedDateIsNullWhenNoCarsHistRowAndFilteredHardBounceLookupHasNoMatchingRow(): void
    {
        // findLatestEmailEventsByCarIdsAndEvents() is filtered to
        // HARD_BOUNCE_EVENTS by the repository query itself, so a car whose
        // only email events are non-hard-bounce types (e.g. a soft bounce)
        // is simply absent from its result.
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', false, true),
        ], hardBounceEvents: []);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertNull($result['addresses'][0]['bounced']['date']);
    }

    // --- string/null-typed DB fields handled --------------------------------

    public function testStringTypedFlagColumnsAreHandledLikeIntTypedOnes(): void
    {
        // PDO can return numeric columns as numeric strings depending on
        // driver/fetch mode. '1'/'0' strings must behave like int 1/0.
        $car = (object) [
            'id' => '1',
            'email' => 'owner@example.com',
            'email_suppressed' => '1',
            'email_bounced' => '0',
            'email_bounced_address' => null,
        ];
        $repo = $this->repoReturning([$car]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertTrue($result['hasSuppressed']);
        $this->assertFalse($result['hasBounced']);
    }

    public function testNullTypedOptionalColumnsDoNotCrash(): void
    {
        $car = (object) [
            'id' => 1,
            'email' => null,
            'email_suppressed' => null,
            'email_bounced' => 1,
            'email_bounced_address' => 'fallback@example.com',
        ];
        $repo = $this->repoReturning([$car]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame('fallback@example.com', $result['addresses'][0]['address']);
        $this->assertFalse($result['hasSuppressed']);
        $this->assertTrue($result['hasBounced']);
    }

    /**
     * Wrong-typed (not merely missing) structured value fed into the
     * builder's consumption of the repository-returned shape: the car id is
     * an array instead of an int/numeric-string. self::toInt() returns null
     * for any value that isn't int or a numeric string, so the builder's
     * documented failure mode is to SKIP the malformed row (treat it as if
     * it had no usable id), not to crash and not to silently coerce it into
     * a usable key.
     */
    public function testWrongTypedCarIdIsSkippedNotCoercedOrFatal(): void
    {
        $malformedCar = (object) [
            'id' => ['not', 'an', 'id'],
            'email' => 'owner@example.com',
            'email_suppressed' => 1,
            'email_bounced' => 0,
            'email_bounced_address' => null,
        ];
        $repo = $this->repoReturning([$malformedCar]);
        $builder = new EmailNoticeBuilder($repo);

        // The only flagged "car" has an unusable id and must be skipped
        // entirely, leaving zero flagged cars, i.e. buildForOwner() returns
        // null rather than throwing or fabricating an entry.
        $this->assertNull($builder->buildForOwner(1));
    }

    // --- CarDatabaseException propagation -----------------------------------

    public function testCarDatabaseExceptionFromFindVerificationStateByOwnerPropagates(): void
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('findVerificationStateByOwner')->willThrowException(
            new CarDatabaseException('boom')
        );
        $builder = new EmailNoticeBuilder($repo);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('boom');
        $builder->buildForOwner(1);
    }

    /**
     * The suppression-events lookup (findLatestEmailEventsByCarIdsAndEvents()
     * called with EmailEventApplier::SUPPRESSION_EVENTS) is the FIRST of the
     * two filtered-lookup call sites buildForOwner() makes. A suppressed-only
     * car reaches it with a non-empty $suppressedIds, before the hard-bounce
     * call site is ever reached, so a stub that throws unconditionally proves
     * this call site alone propagates.
     */
    public function testCarDatabaseExceptionFromSuppressionEventsLookupPropagates(): void
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('findVerificationStateByOwner')->willReturn([
            self::car(1, 'owner@example.com', true, false),
        ]);
        $repo->method('findLatestEmailEventsByCarIdsAndEvents')->willThrowException(
            new CarDatabaseException('suppression events boom')
        );
        $builder = new EmailNoticeBuilder($repo);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('suppression events boom');
        $builder->buildForOwner(1);
    }

    /**
     * The hard-bounce-events lookup is the SECOND filtered-lookup call site.
     * Using a bounced-only car (no suppressed ids) means the suppression call
     * site is reached with an empty array and never throws, so this test
     * isolates the hard-bounce call site specifically — it fails (does not
     * detect the propagation bug) if the suppression call site were the one
     * throwing instead.
     */
    public function testCarDatabaseExceptionFromHardBounceEventsLookupPropagates(): void
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('findVerificationStateByOwner')->willReturn([
            self::car(1, 'owner@example.com', false, true),
        ]);
        $repo->method('findLatestEmailEventsByCarIdsAndEvents')
            ->willReturnCallback(function (array $carIds, array $events) {
                if ($events === EmailEventApplier::SUPPRESSION_EVENTS) {
                    return [];
                }
                throw new CarDatabaseException('hard bounce events boom');
            });
        $builder = new EmailNoticeBuilder($repo);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('hard bounce events boom');
        $builder->buildForOwner(1);
    }

    public function testCarDatabaseExceptionFromFindLatestHistoryOperationByCarIdsPropagates(): void
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('findVerificationStateByOwner')->willReturn([
            self::car(1, 'owner@example.com', true, false),
        ]);
        $repo->method('findLatestEmailEventsByCarIdsAndEvents')->willReturn([]);
        $repo->method('findLatestHistoryOperationByCarIds')->willThrowException(
            new CarDatabaseException('hist boom')
        );
        $builder = new EmailNoticeBuilder($repo);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('hist boom');
        $builder->buildForOwner(1);
    }

    // --- regression: the filtered lookup must win over the latest-event-of-any-type ----

    /**
     * Reproduces the exact round-one bug: the car's LATEST er_email_events
     * row of any type is a non-suppression event ('opened') with a newer
     * occurred_at than an earlier 'spam' event. The old, unfiltered
     * "latest event of any type" lookup would see 'opened' and (being
     * outside EmailEventApplier::SUPPRESSION_EVENTS) wrongly report
     * CAUSE_OWNER_OPTOUT. The fix calls the EVENTS-FILTERED lookup, so the
     * stub below — standing in for that filtered query — is what a real
     * self-join would return: the 'spam' row, not 'opened'.
     */
    public function testCauseIsBrevoComplaintWhenLatestEventOfAnyTypeIsNewerButNotASuppressionEvent(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', true, false),
        ], suppressionEvents: [1 => self::eventRow(1, 'spam', '2026-05-02 09:00:00')]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertSame(EmailNoticeBuilder::CAUSE_BREVO_COMPLAINT, $result['addresses'][0]['suppressed']['cause']);
        $this->assertSame('2026-05-02', $result['addresses'][0]['suppressed']['date']);
    }

    /**
     * Inverse case for bounces: the car's latest event of any type is
     * non-hard-bounce, but there IS an earlier hard-bounce event. The old
     * unfiltered lookup would miss it (seeing only the latest event, which
     * is not a hard bounce) or pick the wrong row; the hard-bounce-filtered
     * lookup must still find the earlier hard-bounce event.
     */
    public function testBouncedDateFindsEarlierHardBounceEventEvenWhenLatestEventOfAnyTypeIsNot(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', false, true),
        ], hardBounceEvents: [1 => self::eventRow(1, 'hard_bounce', '2026-07-01 08:00:00')]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result['addresses'][0]['bounced']);
        $this->assertSame('2026-07-01', $result['addresses'][0]['bounced']['date']);
    }

    // --- missing coverage flagged by round-one review -------------------------

    /**
     * contentHash must change when a NEW flag (a bounce) is added to a car
     * whose address is already suppressed — not only when the address
     * itself changes. Same car, same address, only the bounced flag (and
     * its supporting hard-bounce event) differs between A and B.
     */
    public function testContentHashChangesWhenANewFlagIsAddedToAnAlreadyFlaggedAddress(): void
    {
        $repoA = $this->repoReturning([
            self::car(1, 'owner@example.com', true, false),
        ]);
        $repoB = $this->repoReturning([
            self::car(1, 'owner@example.com', true, true),
        ], hardBounceEvents: [1 => self::eventRow(1, 'hard_bounce', '2026-08-01 09:00:00')]);
        $builder = new EmailNoticeBuilder($repoA);
        $builderB = new EmailNoticeBuilder($repoB);

        $resultA = $builder->buildForOwner(1);
        $resultB = $builderB->buildForOwner(1);

        $this->assertNotNull($resultA);
        $this->assertNotNull($resultB);
        $this->assertFalse($resultA['hasBounced']);
        $this->assertTrue($resultB['hasBounced']);
        $this->assertNotSame(
            $resultA['contentHash'],
            $resultB['contentHash'],
            'Adding a bounce flag to an already-suppressed address must change the hash'
        );
    }

    /**
     * One owner, two DIFFERENT addresses: one with an opt-out cause, the
     * other with a Brevo-complaint cause. Distinct from the existing
     * same-address-merge tests — here the two causes must stay independent
     * per address, not merge.
     */
    public function testOneOwnerWithOptOutOnOneAddressAndBrevoComplaintOnADifferentAddress(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'optout@example.com', true, false),
            self::car(2, 'complaint@example.com', true, false),
        ], suppressionEvents: [2 => self::eventRow(2, 'spam', '2026-05-10 09:00:00')],
            suppressedHist: [1 => self::histRow(1, 'EMAIL SUPPRESSED', '2026-05-01 10:00:00')]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertCount(2, $result['addresses']);

        $byAddress = [];
        foreach ($result['addresses'] as $entry) {
            $byAddress[$entry['address']] = $entry;
        }
        $this->assertSame(
            EmailNoticeBuilder::CAUSE_OWNER_OPTOUT,
            $byAddress['optout@example.com']['suppressed']['cause']
        );
        $this->assertSame(
            EmailNoticeBuilder::CAUSE_BREVO_COMPLAINT,
            $byAddress['complaint@example.com']['suppressed']['cause']
        );
    }

    /**
     * hasBounced must be computed over the FULL flagged set, even when the
     * ONLY bounced address is the one that overflows past MAX_ADDRESSES (3).
     * Four suppressed-only addresses sort before the one bounced address
     * alphabetically ('z...' sorts last), so the bounced address is the one
     * cut by array_slice(). The existing overflow tests use all-suppressed
     * data and cannot catch a regression that moved the hasBounced/hasSuppressed
     * computation onto the sliced (shown) list instead of the full list.
     */
    public function testHasBouncedIsTrueEvenWhenTheOnlyBouncedAddressOverflows(): void
    {
        $cars = [
            self::car(1, 'a-suppressed@example.com', true, false),
            self::car(2, 'b-suppressed@example.com', true, false),
            self::car(3, 'c-suppressed@example.com', true, false),
            self::car(4, 'd-suppressed@example.com', true, false),
            self::car(5, 'z-bounced@example.com', false, true),
        ];
        $repo = $this->repoReturning($cars);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertCount(EmailNoticeBuilder::MAX_ADDRESSES, $result['addresses']);
        $this->assertSame(2, $result['overflowCount'], '5 distinct addresses - 3 shown = 2 overflow');
        $this->assertTrue($result['hasSuppressed']);
        $this->assertTrue($result['hasBounced'], 'hasBounced must cover the full set, not only the shown/sliced addresses');

        $shownAddresses = array_map(static fn (array $entry): string => $entry['address'], $result['addresses']);
        $this->assertNotContains(
            'z-bounced@example.com',
            $shownAddresses,
            'The bounced address must be the one that overflowed, proving this test exercises the overflow case'
        );
    }

    // --- contentHash changes with content ------------------------------------

    public function testContentHashChangesWhenAddressSetChanges(): void
    {
        $repoA = $this->repoReturning([self::car(1, 'a@example.com', true, false)]);
        $repoB = $this->repoReturning([self::car(1, 'b@example.com', true, false)]);
        $builder = new EmailNoticeBuilder($repoA);
        $builderB = new EmailNoticeBuilder($repoB);

        $resultA = $builder->buildForOwner(1);
        $resultB = $builderB->buildForOwner(1);

        $this->assertNotSame($resultA['contentHash'], $resultB['contentHash']);
    }

    public function testContentHashIsStableForIdenticalInput(): void
    {
        $repo1 = $this->repoReturning([self::car(1, 'a@example.com', true, false)]);
        $repo2 = $this->repoReturning([self::car(1, 'a@example.com', true, false)]);
        $builder1 = new EmailNoticeBuilder($repo1);
        $builder2 = new EmailNoticeBuilder($repo2);

        $this->assertSame(
            $builder1->buildForOwner(1)['contentHash'],
            $builder2->buildForOwner(1)['contentHash']
        );
    }

    // --- round-two regression: a stale suppression event must not win over a later clear-and-resuppress ----

    /**
     * Round-two bug: a car was Brevo-suppressed (old 'spam' event), the
     * owner (or an admin) cleared it, and the owner opted out again later
     * (newer 'EMAIL SUPPRESSED' cars_hist row, since the opt-out handler
     * writes no event row). The OLD code used isset() with no recency
     * check, so the stale 24-month-retained event always won, wrongly
     * showing CAUSE_BREVO_COMPLAINT for what is now the owner's own,
     * later opt-out. The fix must prefer the cars_hist row because it is
     * strictly newer than the event.
     */
    public function testStaleSuppressionEventDoesNotWinOverALaterOwnerOptOut(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', true, false),
        ], suppressionEvents: [1 => self::eventRow(1, 'spam', '2026-01-01 10:00:00')],
            suppressedHist: [1 => self::histRow(1, 'EMAIL SUPPRESSED', '2026-03-01 10:00:00')]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertSame(EmailNoticeBuilder::CAUSE_OWNER_OPTOUT, $result['addresses'][0]['suppressed']['cause']);
        $this->assertSame('2026-03-01', $result['addresses'][0]['suppressed']['date']);
    }

    /**
     * Inverse of the above, confirming the fix does not break the normal
     * case: an OLD 'EMAIL SUPPRESSED' cars_hist row exists, then Brevo
     * reports a NEWER 'spam' event (e.g. the owner was cleared, then
     * actually complained via Brevo afterward). The event is the current,
     * later signal and must still win.
     */
    public function testNewerSuppressionEventWinsOverAnOlderOwnerOptOutHistRow(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', true, false),
        ], suppressionEvents: [1 => self::eventRow(1, 'spam', '2026-03-01 10:00:00')],
            suppressedHist: [1 => self::histRow(1, 'EMAIL SUPPRESSED', '2026-01-01 10:00:00')]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertSame(EmailNoticeBuilder::CAUSE_BREVO_COMPLAINT, $result['addresses'][0]['suppressed']['cause']);
        $this->assertSame('2026-03-01', $result['addresses'][0]['suppressed']['date']);
    }

    /**
     * Same-calendar-day precision: a 'spam' event earlier the same day and
     * an 'EMAIL SUPPRESSED' hist row later the same day. A day-only
     * comparison (the old toDate()) would see the two dates as equal and
     * could not tell the hist row is actually later, risking the event
     * winning by default. The full-datetime comparison (toDateTime())
     * must see the hist row as later and pick CAUSE_OWNER_OPTOUT.
     */
    public function testSameDaySuppressionRecencyIsDecidedAtFullDatetimePrecision(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', true, false),
        ], suppressionEvents: [1 => self::eventRow(1, 'spam', '2026-03-01 09:00:00')],
            suppressedHist: [1 => self::histRow(1, 'EMAIL SUPPRESSED', '2026-03-01 14:00:00')]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertSame(EmailNoticeBuilder::CAUSE_OWNER_OPTOUT, $result['addresses'][0]['suppressed']['cause']);
        $this->assertSame('2026-03-01', $result['addresses'][0]['suppressed']['date']);
    }

    // --- round-two regression: the bounce date must use the same full-precision recency logic ----

    /**
     * Same recency fix on the bounce side: an OLD 'EMAIL BOUNCED' cars_hist
     * row, then a NEWER hard-bounce event (e.g. an admin cleared an
     * earlier bounce and the car hard-bounced again via webhook, which
     * writes no history row). The later event date must win.
     */
    public function testNewerHardBounceEventWinsOverAnOlderBouncedHistRow(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', false, true),
        ], hardBounceEvents: [1 => self::eventRow(1, 'hard_bounce', '2026-03-01 10:00:00')],
            bouncedHist: [1 => self::histRow(1, 'EMAIL BOUNCED', '2026-01-01 10:00:00')]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertSame('2026-03-01', $result['addresses'][0]['bounced']['date']);
    }

    /**
     * Inverse: an OLD hard-bounce event, then a NEWER 'EMAIL BOUNCED'
     * cars_hist row (an admin marked a fresh bounce after an earlier
     * webhook-reported bounce was cleared). The later hist date must win.
     */
    public function testNewerBouncedHistRowWinsOverAnOlderHardBounceEvent(): void
    {
        $repo = $this->repoReturning([
            self::car(1, 'owner@example.com', false, true),
        ], hardBounceEvents: [1 => self::eventRow(1, 'hard_bounce', '2026-01-01 10:00:00')],
            bouncedHist: [1 => self::histRow(1, 'EMAIL BOUNCED', '2026-03-01 10:00:00')]);
        $builder = new EmailNoticeBuilder($repo);

        $result = $builder->buildForOwner(1);

        $this->assertSame('2026-03-01', $result['addresses'][0]['bounced']['date']);
    }

    // Same-day precision has no standalone bounce-side test: the bounce
    // path returns only a Y-m-d date (no observable `cause` field), so a
    // same-calendar-day hist/event pair collapses to the identical date
    // string under both the old day-truncated logic and the new
    // full-datetime logic — such a test would pass under both versions and
    // prove nothing. testNewerHardBounceEventWinsOverAnOlderBouncedHistRow
    // above (different days, old hist vs. newer event) is what actually
    // discriminates: the old `hist ?? event` fallback always prefers the
    // hist row when present, which that test catches by using a hist row
    // that is both present AND older than the event.
}
