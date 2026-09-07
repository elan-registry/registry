<?php

declare(strict_types=1);

use ElanRegistry\OwnerSyncResult;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OwnerSyncResult (#1954).
 *
 * Pure value object — no DB, no collaborators. Covers the three-way
 * updated/failed/skipped split: count/phrase helpers for the `skipped`
 * bucket, the updated `totalCount()` denominator, and — the key behavior
 * this issue exists for — that `isCompleteSuccess()` stays keyed on
 * `$failed` alone, so a skip-only result reads as complete success.
 *
 * @see usersc/classes/OwnerSyncResult.php
 * @see https://github.com/elan-registry/registry/issues/1954
 */
#[Group('fast')]
#[Group('unit')]
final class OwnerSyncResultTest extends TestCase
{
    public function testSkippedCount_EmptySkipped_ReturnsZero(): void
    {
        $result = new OwnerSyncResult(updated: [1, 2], failed: [], skipped: []);

        $this->assertSame(0, $result->skippedCount());
    }

    public function testSkippedCount_NonEmptySkipped_ReturnsCount(): void
    {
        $result = new OwnerSyncResult(updated: [], failed: [], skipped: [5, 12, 19]);

        $this->assertSame(3, $result->skippedCount());
    }

    public function testSkippedCarsPhrase_EmptySkipped_ReturnsEmptyString(): void
    {
        $result = new OwnerSyncResult(updated: [1], failed: [], skipped: []);

        $this->assertSame('', $result->skippedCarsPhrase());
    }

    public function testSkippedCarsPhrase_OneSkippedCar_ReturnsSingularPhrase(): void
    {
        $result = new OwnerSyncResult(updated: [], failed: [], skipped: [2107]);

        $this->assertSame('Car 2107 no longer owned; not updated.', $result->skippedCarsPhrase());
    }

    public function testSkippedCarsPhrase_MultipleSkippedCars_ReturnsPluralPhrase(): void
    {
        $result = new OwnerSyncResult(updated: [], failed: [], skipped: [5, 12]);

        $this->assertSame('Cars 5, 12 no longer owned; not updated.', $result->skippedCarsPhrase());
    }

    public function testTotalCount_AllThreeBucketsNonEmpty_SumsAllThree(): void
    {
        $result = new OwnerSyncResult(
            updated: [1, 2],
            failed: [3],
            skipped: [4, 5, 6]
        );

        $this->assertSame(6, $result->totalCount());
    }

    /**
     * A skip-only outcome (every car left this owner mid-sync, nothing
     * updated, nothing failed) must have totalCount() equal skippedCount()
     * exactly — the boundary case behind process-owner-sync-location.php's
     * "No cars were synchronized." wording (#1954). The integration-level
     * version of this scenario lives in
     * OwnerSyncOwnerFieldsToCarsOwnershipScopingTest, which already exercises
     * a single-car, 100%-skipped sync end to end; this pins the numeric
     * contract at the value-object level.
     */
    public function testTotalCount_SkipOnlyOutcome_EqualsSkippedCount(): void
    {
        $result = new OwnerSyncResult(updated: [], failed: [], skipped: [7]);

        $this->assertSame(0, $result->updatedCount());
        $this->assertSame(0, $result->failedCount());
        $this->assertSame($result->skippedCount(), $result->totalCount());
        $this->assertTrue($result->isCompleteSuccess());
    }

    public function testIsCompleteSuccess_EmptyFailedWithNonEmptySkipped_ReturnsTrue(): void
    {
        // The behavior this whole issue is about: a skip-only outcome (car
        // no longer owned mid-sync, not a real error) must read as complete
        // success — isCompleteSuccess() is keyed on $failed alone.
        $result = new OwnerSyncResult(updated: [1], failed: [], skipped: [2, 3]);

        $this->assertTrue($result->isCompleteSuccess());
    }

    public function testIsCompleteSuccess_NonEmptyFailedWithEmptySkipped_ReturnsFalse(): void
    {
        $result = new OwnerSyncResult(updated: [1], failed: [2], skipped: []);

        $this->assertFalse($result->isCompleteSuccess());
    }

    public function testIsCompleteSuccess_NonEmptyFailedWithNonEmptySkipped_ReturnsFalse(): void
    {
        $result = new OwnerSyncResult(updated: [1], failed: [2], skipped: [3]);

        $this->assertFalse($result->isCompleteSuccess());
    }

    /**
     * successMessage() is what process-owner-sync-location.php's success path
     * actually calls at runtime — the endpoint itself calls send()/exit and
     * cannot be included directly in PHPUnit (see AdminOwnerManagementTest's
     * class docblock), so this is the closest runtime verification available
     * of the exact wording an admin sees (#1954).
     */
    public function testSuccessMessage_AllUpdatedNoSkips_ReturnsPlainSuccessSentence(): void
    {
        $result = new OwnerSyncResult(updated: [1, 2, 3], failed: [], skipped: []);

        $this->assertSame('Successfully synchronized owner details to 3 car(s).', $result->successMessage());
    }

    public function testSuccessMessage_UpdatedWithSkips_AppendsSkippedPhrase(): void
    {
        $result = new OwnerSyncResult(updated: [1], failed: [], skipped: [7]);

        $this->assertSame(
            'Successfully synchronized owner details to 1 car(s). Car 7 no longer owned; not updated.',
            $result->successMessage()
        );
    }

    /**
     * The boundary case this method exists for: zero updated must not read
     * as "synchronized ... to 0 car(s)." when the sync had nothing wrong with
     * it — every car just changed hands before the write (#1954).
     */
    public function testSuccessMessage_SkipOnlyOutcome_ReadsAsNoCarsSynchronized(): void
    {
        $result = new OwnerSyncResult(updated: [], failed: [], skipped: [7, 8]);

        $this->assertSame(
            'No cars were synchronized. Cars 7, 8 no longer owned; not updated.',
            $result->successMessage()
        );
    }

    /**
     * Pins the exact concatenated string produced by
     * `failedCarsPhrase() . ' ' . skippedCarsPhrase()` — the pattern all three
     * call sites (process-owner-sync-location.php x2, user_settings.php) build
     * by hand when both buckets are non-empty. A single assertion here catches
     * a spacing/ordering regression in either phrase without needing to
     * duplicate it at each call site.
     */
    public function testFailedAndSkippedPhrasesConcatenateWithSingleSpace(): void
    {
        $result = new OwnerSyncResult(updated: [1], failed: [5], skipped: [12]);

        $combined = $result->failedCarsPhrase() . ' ' . $result->skippedCarsPhrase();

        $this->assertSame(
            'Car 5 could not be updated. Car 12 no longer owned; not updated.',
            $combined
        );
    }
}
