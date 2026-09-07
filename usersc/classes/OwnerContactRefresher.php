<?php

declare(strict_types=1);

namespace ElanRegistry;

/**
 * Refreshes a car's denormalized owner-contact columns from the owner's
 * current profile (issue #1962).
 *
 * This exists as a class rather than inline in `app/api/cars/save.php` for one
 * reason: `save.php` cannot be loaded by a test. Every path through it ends in
 * `exit` via {@see ApiResponse::send()}, so any test of the refresh logic that
 * lived there would have to hand-copy it, and a hand-copied test passes just as
 * happily when the production code is deleted. Keeping the logic here lets the
 * tests call the same code the endpoint calls.
 *
 * All nine columns from {@see Owner::ownerContactFields()} are refreshed,
 * `website` included (issue #1963 — it used to be carved out here because the
 * car-edit page had its own website input; that input is gone and the profile
 * is now the only place `website` can be set).
 *
 * `website` is the one field of the nine that appears in
 * `Car::CLEARABLE_FIELDS` (see `usersc/classes/Car/Car.php`), which changes
 * how an *empty* profile value behaves on this path. For the other eight,
 * `Car::update()`'s `array_filter` drops `''`/`null`, so a blank profile value
 * is silently a no-op. For `website` it is written through, so an owner who
 * clears their profile website blanks it on every one of their cars. That is a
 * deliberate, user-confirmed decision (issue #1963), not an oversight: it
 * matches the blank-propagation {@see Owner::syncOwnerFieldsToCars()} already
 * performs on profile save, so the two paths agree.
 *
 * A profile `website` that would fail `CarValidator`'s validation (not a
 * well-formed http(s) URL) is skipped rather than merged — merging it would
 * reach `Car::update()`/`Car::create()` and throw `CarValidationException`,
 * blocking every future edit (or the add-car flow) for every car this owner
 * has, not just ones that touch `website`. Legacy data and #1961's
 * bulk-promoted orphan websites were never run through the account-settings
 * validator, so an invalid value can genuinely exist on a profile. On the
 * edit path the car keeps its existing `website` in that case, same as when
 * the owner fails to load; on the add-car path (no existing car row) the new
 * car simply has no website until the profile's is fixed.
 *
 * Callers should check {@see hasValidWebsite()} before calling `refresh()`
 * so they can log the skip — `refresh()` itself deliberately does not log,
 * for the same reason `hasLoadableOwner()` does not (see below).
 *
 * @see \ElanRegistry\Owner::ownerContactFields() for the nine-column list
 * @see \ElanRegistry\Owner::syncOwnerFieldsToCars() the profile-save path,
 *      which writes the same bundle but persists it differently (see
 *      "Divergence from the sync path" below)
 */
class OwnerContactRefresher
{
    /**
     * Merge the owner's current contact values over a car's existing details.
     *
     * The caller is responsible for having built `$cardetails` from the CAR's
     * database row and for passing the Owner constructed from that row's
     * `user_id` — never from the logged-in session user. An admin or editor may
     * be editing another member's car, and using the session user would write
     * staff's contact details onto the member's record. This method cannot
     * enforce that itself: by the time it is called, both arguments are already
     * chosen. {@see \ElanRegistry\Owner} carries no notion of "who is logged
     * in", which is what makes the mistake possible upstream and invisible
     * here.
     *
     * Returns `$cardetails` unchanged when the owner failed to load, so a car
     * with a dangling or null `user_id` stays editable and keeps whatever
     * contact data it already holds rather than being blanked. Callers that
     * want to log or surface that case should test {@see hasLoadableOwner()}
     * first — this method deliberately does not log, so that it stays pure and
     * callable from a test without a database-backed logger.
     *
     * Divergence from the sync path: {@see Owner::syncOwnerFieldsToCars()}
     * writes its bundle straight through `CarRepository::updateCarForOwner()`,
     * which persists blanks — clearing your city there clears it on your cars.
     * The edit path routes through `Car::update()`, whose `array_filter` drops
     * `''`/`null` for any field not in `Car::CLEARABLE_FIELDS`, so blank owner
     * values are silently no-ops here — except `website`, the one field of the
     * nine that is in `CLEARABLE_FIELDS` and so propagates a clear on both
     * paths (see the class docblock). The asymmetry is deliberate but easy to
     * trip over; see the tests in `CarEditOwnerColumnRefreshTest`.
     *
     * Never writes `mtime` or `owner_last_updated`. `ownerContactFields()`
     * excludes both, and `owner_last_updated` in particular drives verification
     * -email eligibility (#1873/#1953) — a mechanical refresh must not reset
     * that clock. `Car::update()` handles it separately for genuine owner
     * self-edits.
     *
     * @param array<string, mixed> $cardetails Car details built from the car's
     *                                         DB row.
     * @param Owner                $carOwner   Owner constructed from that row's
     *                                         `user_id`.
     * @return array<string, mixed> `$cardetails` with the owner-contact columns
     *                              refreshed, or unchanged if the owner did not
     *                              load.
     */
    public function refresh(array $cardetails, Owner $carOwner): array
    {
        if (!$this->hasLoadableOwner($carOwner)) {
            return $cardetails;
        }

        foreach ($carOwner->ownerContactFields() as $key => $value) {
            if ($key === 'website' && !self::isValidWebsite($value)) {
                // A profile website that fails Car::CLEARABLE_FIELDS'
                // validation (usersc/classes/Car/CarValidator.php) would
                // otherwise reach Car::update() and throw
                // CarValidationException, blocking every future edit to
                // every car this owner has — including edits that never
                // touch website. Legacy data and #1961's bulk-promoted
                // orphan websites were never run through the account-
                // settings validator, so this is a live risk, not a
                // hypothetical. Leave the car's existing website untouched
                // instead of merging a value that would brick the save.
                continue;
            }
            $cardetails[$key] = $value;
        }

        return $cardetails;
    }

    /**
     * Whether $value would pass Car::CLEARABLE_FIELDS' website validation
     * (see CarValidator::validateAndSanitizeFields()) — null/empty is valid
     * (clears the field), anything else must be a well-formed http(s) URL.
     *
     * Public: this is the canonical write-side validation rule (see
     * {@see \ElanRegistry\OwnerView::websiteUrl()}'s docblock), and
     * {@see \ElanRegistry\Owner::syncOwnerFieldsToCars()} reuses it to skip
     * an invalid profile website the same way {@see refresh()} does, rather
     * than writing it through to `CarRepository::updateCarForOwner()`'s raw
     * SQL (which, unlike `Car::update()`, performs no validation at all) and
     * planting a value that would then block every future
     * `Car::update()`/`Car::create()` call for that owner's cars.
     *
     * @param mixed $value
     * @return bool
     */
    public static function isValidWebsite($value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * Whether the owner loaded, and so whether {@see refresh()} will do
     * anything.
     *
     * Split out so the endpoint can log the skip (it has the car ID and the
     * session user for the log line; this class has neither) without
     * duplicating the null test.
     *
     * Calling `refresh()` without checking this first is **safe, not a bug**:
     * `refresh()` performs the same test internally and returns the details
     * untouched. The only consequence of skipping this call is that the skip
     * goes unlogged — an orphaned or null `user_id` is a data-integrity
     * problem worth a log line, so the endpoint checks. Read the pair as
     * "ask if you want to say something about it", never as "guard required
     * or the merge will blank the car's columns".
     *
     * @param Owner $carOwner
     * @return bool
     */
    public function hasLoadableOwner(Owner $carOwner): bool
    {
        return $carOwner->data() !== null;
    }

    /**
     * Whether the owner's current profile website would survive {@see refresh()}
     * unskipped — i.e. whether it is empty/null or a well-formed http(s) URL.
     *
     * Split out for the same reason as {@see hasLoadableOwner()}: `refresh()`
     * performs this check internally and silently `continue`s past an invalid
     * website, so calling `refresh()` without checking this first is safe, not
     * a bug. The only consequence of skipping this call is that the skip goes
     * unlogged — and per the class docblock, legacy/#1961-promoted invalid
     * profile websites are a real, ongoing condition worth a log line so it
     * doesn't silently and permanently block that one field's refresh with no
     * trace anywhere.
     *
     * Returns `true` when the owner did not load — {@see hasLoadableOwner()}
     * already covers and logs that case, so this method has nothing to add
     * for it.
     *
     * @param Owner $carOwner
     * @return bool
     */
    public function hasValidWebsite(Owner $carOwner): bool
    {
        if (!$this->hasLoadableOwner($carOwner)) {
            return true;
        }

        return self::isValidWebsite($carOwner->ownerContactFields()['website'] ?? null);
    }
}
