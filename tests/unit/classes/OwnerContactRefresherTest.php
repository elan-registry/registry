<?php

declare(strict_types=1);

namespace Tests\Unit\Classes;

use ElanRegistry\Owner;
use ElanRegistry\OwnerContactRefresher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for {@see OwnerContactRefresher} — the owner-contact merge that
 * `app/api/cars/save.php` runs when a car is edited (#1962).
 *
 * These are the tests that can distinguish "the refresh happened" from "the
 * refresh happened with the right owner". The integration suite persists
 * through `Car::update()`, whose filter silently drops `''`/`null` for
 * non-clearable fields, so a blanked value there is indistinguishable from an
 * untouched one. Here nothing is persisted and the returned array is inspected
 * directly, which is what makes the null-owner and wrong-owner cases provable.
 *
 * No database: `Owner` instances are built with their private `_data` set by
 * reflection, the same technique {@see OwnerContactFieldsTest} uses.
 */
#[Group('unit')]
final class OwnerContactRefresherTest extends TestCase
{
    /**
     * Build an Owner with `_data` populated, bypassing the constructor's DB
     * lookup. Passing null models an owner that failed to load.
     */
    private function makeOwner(?array $data): Owner
    {
        $reflection = new ReflectionClass(Owner::class);
        $owner = $reflection->newInstanceWithoutConstructor();

        // No setAccessible() call: it is a no-op since PHP 8.1 and deprecated
        // in 8.5, and PHPUnit reports the deprecation as a test issue.
        $property = $reflection->getProperty('_data');
        $property->setValue($owner, $data === null ? null : (object) $data);

        return $owner;
    }

    /**
     * @return array<string, mixed> A complete, distinctive owner profile.
     */
    private function ownerData(string $tag = 'member'): array
    {
        return [
            'id'      => 42,
            'fname'   => $tag . '-fname',
            'lname'   => $tag . '-lname',
            'email'   => $tag . '@example.com',
            'city'    => $tag . '-city',
            'state'   => $tag . '-state',
            'country' => $tag . '-country',
            'lat'     => 51.5,
            'lon'     => -0.12,
            'website' => 'https://' . $tag . '.example.com',
        ];
    }

    /**
     * All nine refreshable columns land on the car details, `website`
     * included (#1963 — the PER_CAR_FIELDS carve-out that used to exclude it
     * is gone; see the dedicated clear-propagation test below for the one
     * respect in which website still behaves differently from the other
     * eight).
     */
    public function testRefreshCopiesAllNineOwnerColumnsOntoCarDetails(): void
    {
        $cardetails = [
            'id'      => 7,
            'user_id' => 42,
            'fname'   => 'stale-fname',
            'lname'   => 'stale-lname',
            'email'   => 'stale@example.com',
            'city'    => 'stale-city',
            'state'   => 'stale-state',
            'country' => 'stale-country',
            'lat'     => 1.0,
            'lon'     => 2.0,
            'website' => 'https://stale-per-car-website.example.com',
        ];

        $result = (new OwnerContactRefresher())
            ->refresh($cardetails, $this->makeOwner($this->ownerData()));

        $this->assertSame('member-fname', $result['fname']);
        $this->assertSame('member-lname', $result['lname']);
        $this->assertSame('member@example.com', $result['email']);
        $this->assertSame('member-city', $result['city']);
        $this->assertSame('member-state', $result['state']);
        $this->assertSame('member-country', $result['country']);
        $this->assertSame(51.5, $result['lat']);
        $this->assertSame(-0.12, $result['lon']);
        $this->assertSame('https://member.example.com', $result['website']);
    }

    /**
     * `website` must be OVERWRITTEN by the owner's profile value, same as the
     * other eight columns (#1963 — this is the test that would fail if
     * OwnerContactRefresher::PER_CAR_FIELDS were reintroduced to exclude it).
     */
    public function testRefreshOverwritesPerCarWebsiteWithProfileWebsite(): void
    {
        $cardetails = ['user_id' => 42, 'website' => 'https://this-car.example.com'];

        $result = (new OwnerContactRefresher())
            ->refresh($cardetails, $this->makeOwner($this->ownerData()));

        $this->assertSame(
            'https://member.example.com',
            $result['website'],
            "The car's own website must be overwritten by the owner's current profile website"
        );
    }

    /**
     * The security case, stated as an assertion rather than as a comment.
     *
     * The endpoint must construct the Owner from the CAR's user_id, never the
     * logged-in session user — an admin editing a member's car would otherwise
     * write staff contact details onto the member's record. This test pins the
     * consequence: whichever Owner is handed in is the one whose data lands.
     * It is the failing test you get if that call site ever regresses to the
     * session user, provided the caller passes the owner it claims to.
     */
    public function testRefreshWritesTheSuppliedOwnersDataAndNoOtherOwners(): void
    {
        $cardetails = ['user_id' => 42, 'fname' => 'stale', 'email' => 'stale@example.com'];

        $memberResult = (new OwnerContactRefresher())
            ->refresh($cardetails, $this->makeOwner($this->ownerData('member')));
        $adminResult = (new OwnerContactRefresher())
            ->refresh($cardetails, $this->makeOwner($this->ownerData('admin')));

        $this->assertSame('member@example.com', $memberResult['email']);
        $this->assertSame('admin@example.com', $adminResult['email']);
        $this->assertNotSame(
            $memberResult['email'],
            $adminResult['email'],
            'Two different owners must produce two different results — if this ever ' .
            'passes identically, the refresher is ignoring the Owner it was given'
        );
    }

    /**
     * An owner that failed to load must leave the car details completely
     * alone, rather than blanking nine columns with nulls.
     *
     * `ownerContactFields()` returns all-nulls for an unloaded Owner, so this
     * is the guard that stops a car with a dangling user_id from having its
     * contact data wiped.
     */
    public function testRefreshWithUnloadableOwnerReturnsCarDetailsUnchanged(): void
    {
        $cardetails = [
            'id'      => 7,
            'user_id' => 999,
            'fname'   => 'existing-fname',
            'email'   => 'existing@example.com',
            'city'    => 'existing-city',
        ];

        $result = (new OwnerContactRefresher())
            ->refresh($cardetails, $this->makeOwner(null));

        // A whole-array identity check is the strongest form available here and
        // subsumes any per-field assertion: it fails if ANY of the nine columns
        // were blanked to null, and equally if an unrelated key were added or
        // dropped. Per-field follow-ups would be tautological (PHPStan flags
        // them as already-narrowed).
        $this->assertSame(
            $cardetails,
            $result,
            'Nothing may change when the owner did not load — in particular the ' .
            'all-null array from ownerContactFields() must never be merged, which ' .
            'would wipe the contact data of a car whose user_id is dangling'
        );
    }

    public function testHasLoadableOwnerReportsWhetherOwnerDataLoaded(): void
    {
        $refresher = new OwnerContactRefresher();

        $this->assertTrue($refresher->hasLoadableOwner($this->makeOwner($this->ownerData())));
        $this->assertFalse($refresher->hasLoadableOwner($this->makeOwner(null)));
    }

    /**
     * hasValidWebsite() mirrors hasLoadableOwner()'s split-out-for-logging
     * pattern (#1963/#1979): callers check it before calling refresh() so
     * they can log the skip, since refresh() itself silently `continue`s past
     * an invalid website without logging.
     */
    public function testHasValidWebsiteReturnsTrueForAWellFormedHttpsUrl(): void
    {
        $refresher = new OwnerContactRefresher();
        $owner = $this->makeOwner($this->ownerData());

        $this->assertTrue($refresher->hasValidWebsite($owner));
    }

    public function testHasValidWebsiteReturnsFalseForAnInvalidWebsite(): void
    {
        $refresher = new OwnerContactRefresher();

        // Neither a well-formed URL nor an allowed scheme — same two failure
        // modes exercised in
        // CarEditOwnerColumnRefreshTest::testEditSkipsInvalidProfileWebsiteAndKeepsExistingCarWebsite().
        $notAUrl = $this->makeOwner(array_merge($this->ownerData(), ['website' => 'not-a-url']));
        $javascriptScheme = $this->makeOwner(array_merge($this->ownerData(), ['website' => 'javascript:alert(1)']));

        $this->assertFalse($refresher->hasValidWebsite($notAUrl));
        $this->assertFalse($refresher->hasValidWebsite($javascriptScheme));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function emptyWebsiteProvider(): array
    {
        return [
            'empty string' => [''],
            'null'         => [null],
        ];
    }

    #[DataProvider('emptyWebsiteProvider')]
    public function testHasValidWebsiteReturnsTrueForEmptyOrNullWebsite(mixed $emptyValue): void
    {
        $refresher = new OwnerContactRefresher();
        $owner = $this->makeOwner(array_merge($this->ownerData(), ['website' => $emptyValue]));

        $this->assertTrue(
            $refresher->hasValidWebsite($owner),
            'An empty/null profile website is valid (it clears the field) — not merged as invalid'
        );
    }

    /**
     * Per the method's docblock: returns true when the owner did not load —
     * hasLoadableOwner() already covers and logs that case, so this method
     * has nothing to add for it. An unloadable owner must not ALSO be
     * reported as having an invalid website.
     */
    public function testHasValidWebsiteReturnsTrueWhenOwnerFailedToLoad(): void
    {
        $refresher = new OwnerContactRefresher();
        $owner = $this->makeOwner(null);

        $this->assertFalse($refresher->hasLoadableOwner($owner), 'Precondition: the owner must fail to load');
        $this->assertTrue(
            $refresher->hasValidWebsite($owner),
            'An unloadable owner must not be reported as having an invalid website — ' .
            'hasLoadableOwner() already covers and logs that case'
        );
    }

    /**
     * Neither column may ever be written by a mechanical refresh:
     * `owner_last_updated` drives verification-email eligibility
     * (#1873/#1953) and `mtime` is set by `Car::update()` itself.
     *
     * Seeded onto the owner deliberately, so this proves active exclusion
     * rather than the fields merely being absent from the fixture.
     */
    public function testRefreshNeverWritesMtimeOrOwnerLastUpdated(): void
    {
        $ownerData = $this->ownerData();
        $ownerData['mtime'] = '2020-01-01 00:00:00';
        $ownerData['owner_last_updated'] = '2020-01-01 00:00:00';

        $cardetails = ['user_id' => 42, 'owner_last_updated' => '2026-09-05 12:00:00'];

        $result = (new OwnerContactRefresher())
            ->refresh($cardetails, $this->makeOwner($ownerData));

        $this->assertArrayNotHasKey('mtime', $result);
        $this->assertSame(
            '2026-09-05 12:00:00',
            $result['owner_last_updated'],
            'The verification freshness clock must not be reset by a mechanical refresh'
        );
    }

    /**
     * Unrelated car columns must pass through untouched — the refresh is a
     * targeted merge, not a wholesale replacement.
     */
    public function testRefreshPreservesUnrelatedCarFields(): void
    {
        $cardetails = [
            'user_id'  => 42,
            'chassis'  => 'CHASSIS-123',
            'year'     => 1973,
            'comments' => 'a comment the member just edited',
        ];

        $result = (new OwnerContactRefresher())
            ->refresh($cardetails, $this->makeOwner($this->ownerData()));

        $this->assertSame('CHASSIS-123', $result['chassis']);
        $this->assertSame(1973, $result['year']);
        $this->assertSame('a comment the member just edited', $result['comments']);
    }
}
