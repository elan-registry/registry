<?php
declare(strict_types=1);

namespace ElanRegistry;

/**
 * Per-car outcome of Owner::syncOwnerFieldsToCars().
 *
 * Carries the car IDs that were successfully synchronized, those that were
 * skipped, and those that failed, so a caller can report "updated on 5 of 6
 * cars — car 2107 could not be updated" without re-querying or re-deriving
 * counts.
 *
 * A car counts as updated when its row was left holding the owner's current
 * values — including the common case where the row already matched and no
 * UPDATE was needed. A car counts as skipped when ownership changed mid-sync:
 * the car left this owner between the initial car list snapshot and the
 * write, so the previous owner's data was correctly not written — this is
 * expected behavior, not an error. A car counts as failed when its
 * transaction was rolled back for a real error: the history insert failed or
 * the UPDATE itself errored.
 *
 * @author Jim Boone
 */
final class OwnerSyncResult
{
    /**
     * @param list<int> $updated Car IDs left holding the owner's current values
     * @param list<int> $failed  Car IDs whose sync was rolled back due to a real error
     * @param list<int> $skipped Car IDs no longer owned by this owner mid-sync — not a failure
     */
    public function __construct(
        public readonly array $updated = [],
        public readonly array $failed = [],
        public readonly array $skipped = []
    ) {
    }

    /**
     * Number of cars successfully synchronized.
     *
     * @return int
     */
    public function updatedCount(): int
    {
        return count($this->updated);
    }

    /**
     * Number of cars whose sync was rolled back.
     *
     * @return int
     */
    public function failedCount(): int
    {
        return count($this->failed);
    }

    /**
     * Number of cars skipped because ownership changed mid-sync.
     *
     * @return int
     */
    public function skippedCount(): int
    {
        return count($this->skipped);
    }

    /**
     * Total number of cars the sync attempted.
     *
     * @return int
     */
    public function totalCount(): int
    {
        return count($this->updated) + count($this->failed) + count($this->skipped);
    }

    /**
     * Human-readable phrase naming the cars that could not be updated.
     *
     * Singular: "Car 2107 could not be updated."
     * Plural:   "Cars 5, 12 could not be updated."
     *
     * Lives here so the two call sites that report a partial sync cannot drift
     * apart or reintroduce the "Car 5, 12" mis-pluralization.
     *
     * @return string Empty string when nothing failed
     */
    public function failedCarsPhrase(): string
    {
        return self::carsPhrase($this->failed, 'could not be updated.');
    }

    /**
     * Human-readable phrase naming the cars skipped because ownership changed mid-sync.
     *
     * Singular: "Car 2107 no longer owned; not updated."
     * Plural:   "Cars 5, 12 no longer owned; not updated."
     *
     * Lives here so callers that want to mention skips informationally cannot
     * drift apart or reintroduce the "Car 5, 12" mis-pluralization.
     *
     * @return string Empty string when nothing was skipped
     */
    public function skippedCarsPhrase(): string
    {
        return self::carsPhrase($this->skipped, 'no longer owned; not updated.');
    }

    /**
     * Shared sentence-builder behind failedCarsPhrase() and skippedCarsPhrase(),
     * so the two phrasings cannot drift apart in pluralization or punctuation
     * if a third bucket is ever added.
     *
     * @param list<int> $carIds  Car IDs to name; an empty list yields ''
     * @param string    $trailer Text following the noun/id-list, e.g. "could not be updated."
     * @return string Empty string when $carIds is empty
     */
    private static function carsPhrase(array $carIds, string $trailer): string
    {
        if ($carIds === []) {
            return '';
        }

        $noun = count($carIds) === 1 ? 'Car' : 'Cars';

        return sprintf('%s %s %s', $noun, implode(', ', $carIds), $trailer);
    }

    /**
     * Whether every attempted car synchronized successfully.
     *
     * True for an owner with no cars at all — nothing was attempted, so nothing failed.
     *
     * @return bool
     */
    public function isCompleteSuccess(): bool
    {
        return $this->failed === [];
    }

    /**
     * Human-readable success message for a call site where
     * {@see isCompleteSuccess()} is true (no real failures — updated and/or
     * skipped cars only).
     *
     * A skip-only outcome (updatedCount() === 0) is worded as "No cars were
     * synchronized." rather than "Successfully synchronized ... to 0 car(s)."
     * — the latter reads as a failure to a reader even though nothing here
     * needed updating (#1954). Currently called only by
     * process-owner-sync-location.php; lives here rather than inline so that
     * wording is unit-testable without exercising the AJAX endpoint, which
     * calls send()/exit and cannot be included directly in PHPUnit.
     *
     * usersc/user_settings.php deliberately does NOT call this: it reports
     * success only when updatedCount() > 0 and stays silent on a skip-only
     * outcome, since a car the owner no longer owns isn't actionable for them
     * (see the comment at that call site). That is intentionally different
     * wording for a different audience, not drift to be reconciled here.
     *
     * Callers with a failure (isCompleteSuccess() === false) build their own
     * message from {@see failedCarsPhrase()} — this method assumes success.
     *
     * @return string
     */
    public function successMessage(): string
    {
        if ($this->updatedCount() === 0) {
            return trim('No cars were synchronized. ' . $this->skippedCarsPhrase());
        }

        $message = "Successfully synchronized owner details to {$this->updatedCount()} car(s).";
        if ($this->skippedCount() > 0) {
            $message .= ' ' . $this->skippedCarsPhrase();
        }

        return $message;
    }
}
