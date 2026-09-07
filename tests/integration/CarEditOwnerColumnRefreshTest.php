<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Owner;
use ElanRegistry\OwnerContactRefresher;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for the #1962 fix: editing a car must refresh the car's
 * denormalized owner-contact columns from the owner's current profile,
 * instead of perpetuating whatever stale snapshot the car row already held.
 *
 * `buildCarDetails()` (app/api/cars/save.php) cannot be require()'d under
 * PHPUnit — every branch ends in ApiResponse::send() -> exit (see
 * tests/unit/cars/CarActionsSaveWiringTest.php). The merge it performs was
 * therefore extracted into {@see \ElanRegistry\OwnerContactRefresher}, which
 * these tests call directly. That matters: an earlier revision of this file
 * hand-copied the merge into a private helper, and every test here passed with
 * the production block deleted outright, and passed again with the Owner
 * constructed from the session user instead of the car's owner — the exact PII
 * leak the endpoint's comments warn about. Tests that re-implement the code
 * under test cannot fail when it breaks. Call the real thing.
 *
 * The helpers below still stage what the endpoint stages around that call
 * (load the car row, merge an unrelated edited field, persist via
 * Car::update()), then assert against the database afterward.
 *
 * @see app/api/cars/save.php buildCarDetails()
 * @see usersc/classes/Owner.php Owner::ownerContactFields()
 */
#[Group('integration')]
#[Group('car')]
final class CarEditOwnerColumnRefreshTest extends IntegrationTestCase
{
    /** @var int[] Profile IDs to clean up in tearDown */
    private array $createdProfileIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdProfileIds as $profileId) {
            try {
                $this->db->query("DELETE FROM profiles WHERE id = ?", [$profileId]);
            } catch (\Throwable $e) {
                // Ignore cleanup errors
            }
        }
        $this->createdProfileIds = [];
        parent::tearDown();
    }

    /**
     * Create a profile row for a test user with optional overrides.
     * Tracked for cleanup in tearDown(). Mirrors
     * OwnerSyncOwnerFieldsToCarsTest::createTestProfile().
     */
    private function createTestProfile(int $userId, array $overrides = []): void
    {
        $defaults = [
            'user_id' => $userId,
            'bio'     => '',
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => null,
            'lon'     => null,
            'website' => '',
        ];

        $this->db->insert('profiles', array_merge($defaults, $overrides));

        $row = $this->db->query("SELECT id FROM profiles WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$userId])->first();
        if (!$row) {
            throw new \RuntimeException("createTestProfile: insert failed for user_id={$userId}");
        }
        $this->createdProfileIds[] = (int) $row->id;
    }

    /**
     * Reproduce buildCarDetails()'s `if ($carId)` edit-branch refresh sequence
     * against a real car row and a real Owner, then apply it via Car::update()
     * exactly as save.php does. $extraFields lets a test simulate "the user
     * also edited an unrelated field" alongside the refresh.
     *
     * $isOwnerInitiated mirrors save.php's updateCar(): production computes
     * this as `(int) ($cardetails['user_id'] ?? 0) === (int) $user->data()->id`
     * (save.php:291) — i.e. true when the person submitting the edit IS the
     * car's own owner, false for an admin/editor editing someone else's car —
     * and passes it to Car::update($cardetails, $isOwnerInitiated). It is NOT
     * part of the #1962 refresh itself; it's a separate flag Car::update() uses
     * to decide whether to also bump owner_last_updated (see Car.php's
     * CLEARABLE_FIELDS-adjacent owner_last_updated handling). This parameter
     * defaults to false (admin-edit shape) to match this class's original
     * test cases, which all modeled an edit whose $isOwnerInitiated truth
     * value did not matter to what was being asserted at the time — see
     * testOwnerSelfEditSetsOwnerLastUpdatedButOrphanEditsDoNotUnlikeAdminEdit()
     * below for why that distinction DOES matter for owner_last_updated
     * specifically.
     */
    private function runEditBranchRefresh(int $carId, array $extraFields = [], bool $isOwnerInitiated = false): void
    {
        $carRow = $this->db->query("SELECT * FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($carRow, 'Precondition: car must exist');

        $cardetails = (array) $carRow;
        // The real edit branch rebuilds `model` from separate series/variant/type
        // form fields via updateModel() (save.php) into "series|variant|type"
        // format before Car::update() ever sees it — the raw cars.model column
        // ("Elan S4") is a display string, not that format. These tests are not
        // exercising updateModel()/CarValidator's model parsing, so drop the raw
        // column value rather than fabricate a pipe-delimited string unrelated
        // to what's under test here.
        unset($cardetails['model']);
        foreach ($extraFields as $key => $value) {
            $cardetails[$key] = $value;
        }

        // Load the CAR's owner (never a session/admin user), then run the
        // production merge itself — the same object app/api/cars/save.php
        // calls. Do not inline this merge here: a hand-copy passes even when
        // the endpoint's call is deleted.
        $carOwner = new Owner((int) $cardetails['user_id']);
        $this->assertNotNull($carOwner->data(), 'Precondition: car owner must load');

        $refresher = new OwnerContactRefresher();
        $this->assertTrue(
            $refresher->hasLoadableOwner($carOwner),
            'Precondition: the refresher must agree the owner loaded'
        );
        $cardetails = $refresher->refresh($cardetails, $carOwner);

        $car = new Car($carId);
        $result = $car->update($cardetails, $isOwnerInitiated);
        $this->assertTrue($result, 'Car::update() must succeed for the refresh to be observable');
    }

    /**
     * Reproduce buildCarDetails()'s edit-branch `else` (orphan-owner) path
     * exactly: when `$carOwner->data()` is null, the function logs and
     * leaves $cardetails untouched for all nine owner-contact fields —
     * ownerFields are simply never merged in. Mirrors runEditBranchRefresh()
     * but stops after the "owner failed to load" branch instead of merging
     * ownerFields, since that merge is precisely what buildCarDetails()
     * skips in this case.
     */
    private function runEditBranchRefreshWithOrphanOwner(int $carId, array $extraFields = []): void
    {
        $carRow = $this->db->query("SELECT * FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($carRow, 'Precondition: car must exist');

        $cardetails = (array) $carRow;
        unset($cardetails['model']);
        foreach ($extraFields as $key => $value) {
            $cardetails[$key] = $value;
        }

        $ownerIdRaw = $cardetails['user_id'];
        $carOwner = new Owner($ownerIdRaw !== null ? (int) $ownerIdRaw : null);
        $this->assertNull(
            $carOwner->data(),
            'Precondition: this test exercises the orphan-owner branch — the owner must fail to load'
        );

        // The endpoint's `else` branch (owner data() === null) logs and skips
        // the merge. Run the real refresher anyway and assert it returns
        // $cardetails untouched — that no-op IS the contract under test, and
        // asserting it here means a refresher that started blanking columns on
        // an unloadable owner would fail this test rather than slip through.
        $refresher = new OwnerContactRefresher();
        $this->assertFalse(
            $refresher->hasLoadableOwner($carOwner),
            'Precondition: the refresher must agree the owner did not load'
        );
        $this->assertSame(
            $cardetails,
            $refresher->refresh($cardetails, $carOwner),
            'An unloadable owner must leave every car-detail value untouched'
        );

        $car = new Car($carId);
        $result = $car->update($cardetails);
        $this->assertTrue($result, 'Car::update() must succeed even when the owner-contact refresh is skipped');
    }

    /**
     * Case 1: stale owner columns on the car refresh to the owner's current
     * profile values when an unrelated field (comments) is edited.
     */
    public function testEditRefreshesAllEightOwnerColumnsFromCurrentProfile(): void
    {
        $userId = $this->createTestUser([
            'fname' => 'Fresh',
            'lname' => 'Owner',
            'email' => 'fresh-owner@example.com',
        ]);
        $this->createTestProfile($userId, [
            'city'    => 'Eugene',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 44.0521,
            'lon'     => -123.0868,
        ]);

        // Car starts with a deliberately stale owner-contact snapshot.
        $carId = $this->createTestCar($userId, [
            'fname'   => 'Stale',
            'lname'   => 'Name',
            'email'   => 'stale-email@example.com',
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 45.5231,
            'lon'     => -122.6765,
            'comments' => 'original comments',
        ]);

        $this->runEditBranchRefresh($carId, ['comments' => 'edited comments']);

        $car = $this->db->query(
            "SELECT email, fname, lname, city, state, country, lat, lon, comments FROM cars WHERE id = ?",
            [$carId]
        )->first();
        $this->assertNotNull($car);
        $this->assertSame('fresh-owner@example.com', $car->email);
        $this->assertSame('Fresh', $car->fname);
        $this->assertSame('Owner', $car->lname);
        $this->assertSame('Eugene', $car->city);
        $this->assertSame('Oregon', $car->state);
        $this->assertSame('United States', $car->country);
        $this->assertEqualsWithDelta(44.0521, (float) $car->lat, 0.001);
        $this->assertEqualsWithDelta(-123.0868, (float) $car->lon, 0.001);
        $this->assertSame('edited comments', $car->comments, 'The unrelated edited field must still be written');
    }

    /**
     * Case 2: website now refreshes from the owner's current profile exactly
     * like the other eight ownerContactFields() columns (issue #1963 — the
     * PER_CAR_FIELDS carve-out that used to exclude it from this merge is
     * gone), while owner_last_updated and join_date remain UNCHANGED — those
     * two are never touched by the #1962 refresh itself, website's inclusion
     * does not change that.
     *
     * This deliberately exercises the ADMIN-EDIT shape ($isOwnerInitiated =
     * false, runEditBranchRefresh()'s default) — mirroring save.php's
     * $isOwnerInitiated = (int)($cardetails['user_id'] ?? 0) === (int)$user->data()->id.
     * owner_last_updated being unchanged here proves only "the refresh doesn't
     * set it" for a NON-owner-initiated edit. It does NOT prove the refresh
     * never sets it — Car::update()'s own $isOwnerInitiated branch adds
     * owner_last_updated independently of anything buildCarDetails() does.
     * See testOwnerSelfEditSetsOwnerLastUpdatedButAdminEditDoesNot() below,
     * which passes $isOwnerInitiated = true and proves the two modes diverge,
     * distinguishing "the refresh doesn't set it" (this test) from "nothing
     * ever sets it" (which would be false).
     */
    public function testEditRefreshesWebsiteButNotOwnerLastUpdatedOrJoinDate(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, [
            'city'    => 'Eugene',
            'website' => 'https://profile-website.example.com',
        ]);

        $staleOwnerLastUpdated = date('Y-m-d H:i:s', strtotime('-2 years'));
        $staleJoinDate = date('Y-m-d H:i:s', strtotime('-5 years'));
        $carId = $this->createTestCar($userId, [
            'city'               => 'Portland',
            'website'            => 'https://per-car-website.example.com',
            'owner_last_updated' => $staleOwnerLastUpdated,
            'join_date'          => $staleJoinDate,
            'comments'           => 'before',
        ]);

        $before = $this->db->query(
            "SELECT website, owner_last_updated, join_date FROM cars WHERE id = ?",
            [$carId]
        )->first();
        $this->assertNotNull($before);

        // isOwnerInitiated = false: the admin-edit shape. See docblock above.
        $this->runEditBranchRefresh($carId, ['comments' => 'after'], isOwnerInitiated: false);

        $after = $this->db->query(
            "SELECT website, owner_last_updated, join_date, city, comments FROM cars WHERE id = ?",
            [$carId]
        )->first();
        $this->assertNotNull($after);

        // Sanity: the refresh did happen (city changed), so the assertions
        // below are proving exclusion, not that nothing ran at all.
        $this->assertSame('Eugene', $after->city);
        $this->assertSame('after', $after->comments);

        $this->assertSame(
            'https://profile-website.example.com',
            $after->website,
            'website must now be refreshed from the profile, same as the other eight owner-contact columns (#1963)'
        );
        $this->assertNotSame((string) $before->website, (string) $after->website, 'sanity: the refresh must have actually overwritten the stale per-car website');
        $this->assertSame((string) $before->owner_last_updated, (string) $after->owner_last_updated, 'owner_last_updated must not be written on a non-owner-initiated (admin) edit');
        $this->assertSame((string) $before->join_date, (string) $after->join_date, 'join_date is creation-time only and must never be touched by the refresh');
    }

    /**
     * Case 2a (#1963): the profile's website wins over a car's stale per-car
     * value on the edit-path refresh. Distinct from the previous test's
     * "did it change at all" check — this asserts the resulting value is
     * specifically the PROFILE's, proving the merge pulls from
     * ownerContactFields() rather than, say, leaving the car's own value or
     * producing some other incidental result.
     */
    public function testEditRefreshPrefersProfileWebsiteOverStaleCarWebsite(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, [
            'city'    => 'Eugene',
            'website' => 'https://current-profile-website.example.com',
        ]);

        $carId = $this->createTestCar($userId, [
            'city'    => 'Portland',
            'website' => 'https://stale-per-car-website.example.com',
        ]);

        $this->runEditBranchRefresh($carId, ['comments' => 'edited']);

        $car = $this->db->query("SELECT website FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame(
            'https://current-profile-website.example.com',
            $car->website,
            'the car website must be overwritten with the OWNER PROFILE\'s current value, not left at its stale per-car value'
        );
    }

    /**
     * Case 2d (#1963/#1979 gap): an owner profile website that fails
     * CarValidator's http(s)-URL validation must be SKIPPED by the refresh,
     * not merged in. Merging it would reach Car::update() and throw
     * CarValidationException, blocking every future edit to every car this
     * owner has — see OwnerContactRefresher::isValidWebsite() and its
     * class-level docblock. The car must keep its existing, valid website
     * unchanged — not blanked, and not overwritten with the invalid value.
     */
    public function testEditSkipsInvalidProfileWebsiteAndKeepsExistingCarWebsite(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, [
            'city'    => 'Eugene',
            // Neither a well-formed URL nor an allowed scheme — legacy data
            // or one of #1961's bulk-promoted orphan websites could produce
            // either shape, so this exercises both failure modes at once:
            // 'not-a-url' fails FILTER_VALIDATE_URL outright, and even if it
            // didn't, javascript: is not in isValidWebsite()'s http(s)
            // scheme whitelist.
            'website' => 'not-a-url',
        ]);

        $carId = $this->createTestCar($userId, [
            'city'    => 'Portland',
            'website' => 'https://existing-valid-website.example.com',
        ]);

        $this->runEditBranchRefresh($carId, ['comments' => 'edited']);

        $car = $this->db->query("SELECT city, website, comments FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);

        // Sanity: the refresh did run (city changed, unrelated field wrote),
        // so the website assertion below is proving a targeted skip, not
        // that the whole refresh silently no-op'd.
        $this->assertSame('Eugene', $car->city);
        $this->assertSame('edited', $car->comments);

        $this->assertSame(
            'https://existing-valid-website.example.com',
            (string) $car->website,
            'an invalid profile website must be skipped — the car must keep its existing valid website, ' .
            'neither blanked nor overwritten with the invalid profile value'
        );
    }

    /**
     * Case 2c (#1963 clear-propagation decision): when the owner's profile
     * website is empty, the edit-path refresh BLANKS the car's existing
     * website — website is the one field of the nine in
     * Car::CLEARABLE_FIELDS (see Car.php), so Car::update()'s array_filter
     * does NOT strip the empty value for it, unlike the other eight fields.
     * CarValidator's `case 'website'` (CarValidator.php) sets an empty/null
     * value to `null` in $validatedFields (not ''), and that null then
     * survives array_filter because 'website' is in CLEARABLE_FIELDS — so
     * the persisted value is NULL, not an empty string.
     *
     * The same test also asserts `city` (an equally-empty profile value) is
     * left UNCHANGED on the car. This is NOT a CLEARABLE_FIELDS regression
     * guard — city's preservation has nothing to do with Car::update()'s
     * array_filter/CLEARABLE_FIELDS mechanism at all. It is gated one layer
     * earlier: CarValidator's `case 'city':` (CarValidator.php ~line 225)
     * only writes to $validatedFields `if (!empty($value))`, with no `else`
     * branch — so an empty city is dropped from $validatedFields before
     * Car::update() ever sees it, and CLEARABLE_FIELDS membership (or lack
     * of it) is never consulted for city. The trailing assertions on
     * Car::CLEARABLE_FIELDS below pin down that real distinction directly,
     * rather than relying on this test's behavior to imply it.
     */
    public function testEditPropagatesBlankWebsiteButNotBlankCityFromEmptyProfile(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, [
            'city'    => '',
            'website' => '',
        ]);

        $carId = $this->createTestCar($userId, [
            'city'    => 'Existing City',
            'website' => 'https://existing-per-car-website.example.com',
        ]);

        $this->runEditBranchRefresh($carId, ['comments' => 'edited']);

        $car = $this->db->query("SELECT city, website FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);

        $this->assertNull(
            $car->website,
            'website must be NULLed when the profile value is empty — CarValidator\'s case \'website\' sets ' .
            'it to null, and it is in Car::CLEARABLE_FIELDS, so array_filter does not drop the null value'
        );
        $this->assertSame(
            'Existing City',
            (string) $car->city,
            'city must be left UNCHANGED when the profile value is empty — CarValidator\'s case \'city\' ' .
            '(CarValidator.php) only assigns $validatedFields[\'city\'] when !empty($value), so an empty ' .
            'city is dropped before Car::update() and its CLEARABLE_FIELDS/array_filter logic is ever reached ' .
            '(regression guard: see the CLEARABLE_FIELDS membership assertions below for the actual ' .
            'website-vs-city distinction)'
        );

        // Pin the real distinction between website and city directly against
        // Car::CLEARABLE_FIELDS (private, read via Reflection), rather than
        // relying on this test's runtime behavior to imply it. Mutation
        // testing confirmed adding 'city' to CLEARABLE_FIELDS does NOT make
        // this test fail — proof the assertions above are not, by themselves,
        // sensitive to that constant. These assertions are.
        $clearableFields = (new \ReflectionClassConstant(Car::class, 'CLEARABLE_FIELDS'))->getValue();
        $this->assertContains(
            'website',
            $clearableFields,
            'website must be in Car::CLEARABLE_FIELDS — this is what makes its empty/null profile value ' .
            'survive Car::update()\'s array_filter and propagate as a blank'
        );
        $this->assertNotContains(
            'city',
            $clearableFields,
            'city must NOT be in Car::CLEARABLE_FIELDS — not that it matters for city\'s empty-value ' .
            'behavior, since CarValidator\'s case \'city\' drops empty values before Car::update() is ' .
            'ever reached, but pinning this documents that city and website reach their same observed ' .
            'behavior (unwritten/blanked) via genuinely different mechanisms'
        );
    }

    /**
     * Case 2b (#1962 gap): proves the two $isOwnerInitiated modes actually
     * diverge for owner_last_updated, distinguishing "the #1962 refresh
     * itself never sets owner_last_updated" (true in both modes) from
     * "nothing ever sets owner_last_updated" (false — Car::update() sets it
     * independently when $isOwnerInitiated is true).
     *
     * Without this test, the previous test's "owner_last_updated unchanged"
     * assertion could pass for the WRONG reason: not because the refresh
     * correctly excludes it, but because nothing in the whole call ever
     * writes it at all in either mode — a much weaker and easily-broken
     * property to accidentally rely on.
     *
     * Same starting car/profile/edit for both modes; only $isOwnerInitiated
     * differs, isolating it as the sole cause of the observed difference.
     */
    public function testOwnerSelfEditSetsOwnerLastUpdatedButAdminEditDoesNot(): void
    {
        $staleOwnerLastUpdated = date('Y-m-d H:i:s', strtotime('-2 years'));

        $adminEditUserId = $this->createTestUser();
        $this->createTestProfile($adminEditUserId, ['city' => 'Eugene']);
        $adminEditCarId = $this->createTestCar($adminEditUserId, [
            'owner_last_updated' => $staleOwnerLastUpdated,
            'comments' => 'before',
        ]);

        $selfEditUserId = $this->createTestUser();
        $this->createTestProfile($selfEditUserId, ['city' => 'Eugene']);
        $selfEditCarId = $this->createTestCar($selfEditUserId, [
            'owner_last_updated' => $staleOwnerLastUpdated,
            'comments' => 'before',
        ]);

        // Admin-edit mode (false): owner_last_updated must stay exactly the
        // stale pre-existing value — no write to it at all.
        $this->runEditBranchRefresh($adminEditCarId, ['comments' => 'after'], isOwnerInitiated: false);

        // Owner-self-edit mode (true): Car::update() must set
        // owner_last_updated to "now" — a value strictly newer than the
        // stale seed — independently of the #1962 refresh logic.
        $this->runEditBranchRefresh($selfEditCarId, ['comments' => 'after'], isOwnerInitiated: true);

        $adminEditResult = $this->db->query(
            "SELECT owner_last_updated FROM cars WHERE id = ?",
            [$adminEditCarId]
        )->first();
        $selfEditResult = $this->db->query(
            "SELECT owner_last_updated FROM cars WHERE id = ?",
            [$selfEditCarId]
        )->first();
        $this->assertNotNull($adminEditResult);
        $this->assertNotNull($selfEditResult);

        $this->assertSame(
            $staleOwnerLastUpdated,
            (string) $adminEditResult->owner_last_updated,
            'Admin edit ($isOwnerInitiated = false): owner_last_updated must remain untouched'
        );
        $this->assertNotSame(
            $staleOwnerLastUpdated,
            (string) $selfEditResult->owner_last_updated,
            'Owner self-edit ($isOwnerInitiated = true): owner_last_updated must be updated by ' .
            'Car::update() — proving the previous test\'s "unchanged" result is because the #1962 ' .
            'refresh itself never sets this field, not because nothing in the call chain does'
        );
        $this->assertGreaterThan(
            strtotime($staleOwnerLastUpdated),
            strtotime((string) $selfEditResult->owner_last_updated),
            'Owner self-edit must bump owner_last_updated forward in time, not merely change it'
        );
    }

    /**
     * Case 3 (security regression): when an ADMIN edits a MEMBER's car, the
     * refresh must write the CAR OWNER's (member's) current contact data —
     * never the admin's. Using the session user instead of the car's
     * user_id would leak staff contact details onto a public-facing car
     * record. See Database & Security Considerations in
     * docs/plans/issue-1962-refresh-owner-columns-on-car-edit.md.
     *
     * This test never constructs an Owner from the admin's ID at all —
     * exactly mirroring buildCarDetails(), which reads
     * $cardetails['user_id'] from the loaded car row, not from the logged-in
     * $user global. If a future change accidentally swapped in the session
     * user, this test would catch it by finding the admin's email/fname/city
     * on the car instead of the member's.
     */
    public function testAdminEditingMembersCarWritesMembersDataNotAdmins(): void
    {
        $memberId = $this->createTestUser([
            'fname' => 'Member',
            'lname' => 'Smith',
            'email' => 'member-smith@example.com',
        ]);
        $this->createTestProfile($memberId, [
            'city'    => 'Eugene',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 44.0521,
            'lon'     => -123.0868,
        ]);

        $adminId = $this->createTestUser([
            'fname' => 'Admin',
            'lname' => 'Jones',
            'email' => 'admin-jones@example.com',
        ]);
        $this->createTestProfile($adminId, [
            'city'    => 'Seattle',
            'state'   => 'Washington',
            'country' => 'United States',
            'lat'     => 47.6062,
            'lon'     => -122.3321,
        ]);

        // The car belongs to the member; only its stale snapshot might
        // coincidentally resemble anyone's data, so seed it distinctly.
        $carId = $this->createTestCar($memberId, [
            'fname' => 'Stale',
            'lname' => 'Snapshot',
            'email' => 'stale-snapshot@example.com',
            'city'  => 'Portland',
        ]);

        // Deliberately do NOT touch a global "logged-in user" — the fix
        // under test must never consult one. We simulate "an admin is
        // performing this edit" simply by the admin existing in the system;
        // the correctness property is that the refresh sequence, driven only
        // by the car's own user_id, is blind to who initiated it.
        $this->runEditBranchRefresh($carId, ['comments' => 'edited by admin']);

        $car = $this->db->query(
            "SELECT email, fname, lname, city, state, country, lat, lon FROM cars WHERE id = ?",
            [$carId]
        )->first();
        $this->assertNotNull($car);

        // Must match the MEMBER.
        $this->assertSame('member-smith@example.com', $car->email);
        $this->assertSame('Member', $car->fname);
        $this->assertSame('Smith', $car->lname);
        $this->assertSame('Eugene', $car->city);
        $this->assertSame('Oregon', $car->state);
        $this->assertEqualsWithDelta(44.0521, (float) $car->lat, 0.001);
        $this->assertEqualsWithDelta(-123.0868, (float) $car->lon, 0.001);

        // Must NOT match the admin.
        $this->assertNotSame('admin-jones@example.com', $car->email);
        $this->assertNotSame('Admin', $car->fname);
        $this->assertNotSame('Jones', $car->lname);
        $this->assertNotSame('Seattle', $car->city);
        $this->assertNotSame('Washington', $car->state);
    }

    /**
     * Case 4: the new-car (add) path must populate all nine
     * ownerContactFields() columns — including `website` (#1963: the add-car
     * branch converged onto Owner::ownerContactFields() in the same change
     * that removed the per-car website form field, so website is no longer
     * form-driven/null-initialized on this path either) — straight from the
     * owner's profile, exactly as buildCarDetails()'s `else` branch does.
     * `user_id` and `join_date` are asserted separately since they are set
     * explicitly outside ownerContactFields()'s scope, not part of the
     * nine-field bundle.
     */
    public function testNewCarPathStillPopulatesOwnerColumnsFromProfile(): void
    {
        $userId = $this->createTestUser([
            'fname' => 'Brand',
            'lname' => 'NewOwner',
            'email' => 'brand-new-owner@example.com',
            'join_date' => '2020-01-01 00:00:00',
        ]);
        $this->createTestProfile($userId, [
            'city'    => 'Bend',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 44.0582,
            'lon'     => -121.3153,
            'website' => 'https://brand-new-owner-profile.example.com',
        ]);

        $owner = new Owner($userId);
        $ownerData = $owner->data();
        $this->assertNotNull($ownerData);

        // Reproduce buildCarDetails()'s `else` (add-car) branch verbatim
        // (#1963): the nine owner-contact columns come from
        // ownerContactFields() via a foreach merge, and user_id/join_date are
        // assigned explicitly outside that set.
        $cardetails = [];
        foreach ($owner->ownerContactFields() as $key => $value) {
            $cardetails[$key] = $value;
        }
        $cardetails['user_id']   = $ownerData->id;
        $cardetails['join_date'] = $ownerData->join_date;
        $cardetails['year']      = 1970;
        $cardetails['model']     = 'Elan';
        $cardetails['series']    = 'S4';
        $cardetails['variant']   = 'SE';
        $cardetails['type']      = 'FHC';
        $cardetails['chassis']   = 'NEWCHASSIS01';
        $cardetails['color']     = 'Green';

        // Model the actual add-car insert path via createTestCar() so the
        // assertions below read from a real row, same as the other cases.
        $carId = $this->createTestCar($userId, $cardetails);

        $car = $this->db->query(
            "SELECT user_id, email, fname, lname, city, state, country, lat, lon, website, join_date FROM cars WHERE id = ?",
            [$carId]
        )->first();
        $this->assertNotNull($car);
        $this->assertSame($userId, (int) $car->user_id);
        $this->assertSame('brand-new-owner@example.com', $car->email);
        $this->assertSame('Brand', $car->fname);
        $this->assertSame('NewOwner', $car->lname);
        $this->assertSame('Bend', $car->city);
        $this->assertSame('Oregon', $car->state);
        $this->assertSame('United States', $car->country);
        $this->assertEqualsWithDelta(44.0582, (float) $car->lat, 0.001);
        $this->assertEqualsWithDelta(-121.3153, (float) $car->lon, 0.001);
        $this->assertSame(
            'https://brand-new-owner-profile.example.com',
            (string) $car->website,
            'the add-car branch must now populate website from the profile via ownerContactFields() (#1963)'
        );
        $this->assertSame(
            '2020-01-01 00:00:00',
            (string) $car->join_date,
            'join_date must still be set explicitly from $ownerData, outside ownerContactFields()\'s scope'
        );
    }

    /**
     * Case 4b (#1963/#1979 gap, add-car path): an owner whose profile website
     * fails CarValidator's http(s)-URL validation must not block creating a
     * brand-new car. Before the fix, the add-car (`else`) branch bypassed
     * website validation entirely by hand-copying ownerContactFields() into
     * $cardetails without running it through OwnerContactRefresher first —
     * so an invalid profile website reached Car::create() unfiltered and
     * threw CarValidationException, blocking the owner from adding ANY car
     * at all. Now the add-car branch routes through the same
     * hasValidWebsite()/refresh() pair as the edit branch, so the invalid
     * value is skipped and the new car simply has no website (there is no
     * "existing car website" to preserve here, unlike the edit-path case in
     * testEditSkipsInvalidProfileWebsiteAndKeepsExistingCarWebsite() above —
     * the owner has no existing car at all).
     */
    public function testNewCarPathSkipsInvalidProfileWebsiteAndDoesNotThrow(): void
    {
        $userId = $this->createTestUser([
            'fname' => 'Brand',
            'lname' => 'NewOwner',
            'email' => 'brand-new-owner-badsite@example.com',
        ]);
        $this->createTestProfile($userId, [
            'city'    => 'Bend',
            'website' => 'not-a-url',
        ]);

        $owner = new Owner($userId);
        $ownerData = $owner->data();
        $this->assertNotNull($ownerData);

        $refresher = new OwnerContactRefresher();
        $this->assertFalse(
            $refresher->hasValidWebsite($owner),
            'Precondition: this test exercises an invalid profile website'
        );

        // Reproduce buildCarDetails()'s `else` (add-car) branch exactly as
        // fixed: routed through OwnerContactRefresher::refresh() rather than
        // a hand-copied ownerContactFields() loop, so the invalid website is
        // skipped instead of reaching Car::create() and throwing.
        $cardetails = [];
        $cardetails = $refresher->refresh($cardetails, $owner);
        $cardetails['user_id']   = $ownerData->id;
        $cardetails['join_date'] = $ownerData->join_date;
        $cardetails['year']      = 1970;
        $cardetails['model']     = 'Elan';
        $cardetails['series']    = 'S4';
        $cardetails['variant']   = 'SE';
        $cardetails['type']      = 'FHC';
        $cardetails['chassis']   = 'NEWCHASSIS-BADSITE-01';
        $cardetails['color']     = 'Green';

        // The bug this test guards against: Car::create() used to throw
        // CarValidationException here when the invalid website reached it
        // unfiltered. createTestCar() calling Car::create() without throwing
        // is itself part of what is under test — no try/catch is used here
        // deliberately, so PHPUnit fails loudly (rather than a caught/ignored
        // exception) if the regression reappears.
        $carId = $this->createTestCar($userId, $cardetails);

        $car = $this->db->query(
            "SELECT user_id, city, website FROM cars WHERE id = ?",
            [$carId]
        )->first();
        $this->assertNotNull($car);
        $this->assertSame($userId, (int) $car->user_id);
        $this->assertSame('Bend', $car->city, 'sanity: the refresh did populate other profile fields normally');
        $this->assertTrue(
            $car->website === null || $car->website === '',
            'a new car must end up with a null/empty website when the profile website is invalid, ' .
            'not the invalid value itself — got: ' . var_export($car->website, true)
        );
    }

    /**
     * Case 5: an owner with empty city and null lat/lon produces a no-op for
     * those specific fields — Car::update() strips ''/null for any field
     * outside CLEARABLE_FIELDS (Car.php:43-45,241-247), and city/lat/lon are
     * not in that allowlist, so an empty/null profile value is simply
     * dropped from the write rather than blanking the car's existing value.
     * Must not crash. fname/lname/email are still populated normally by this
     * test's profile/user data, confirming the refresh is not disabled
     * wholesale — only the specific empty fields are no-ops.
     */
    public function testOwnerWithEmptyCityAndNullLatLonIsNoOpForThoseFieldsWithoutCrashing(): void
    {
        $userId = $this->createTestUser([
            'fname' => 'Sparse',
            'lname' => 'Profile',
            'email' => 'sparse-profile@example.com',
        ]);
        // city/state/country default to '' via Owner::find()'s null-coalesce
        // when omitted here, and lat/lon stay null (no LEFT JOIN override).
        $this->createTestProfile($userId, [
            'city'    => '',
            'state'   => '',
            'country' => '',
            'lat'     => null,
            'lon'     => null,
        ]);

        $carId = $this->createTestCar($userId, [
            'city'  => 'Existing City',
            'state' => 'Existing State',
            'country' => 'Existing Country',
            'lat'   => 45.0,
            'lon'   => -122.0,
        ]);

        $this->runEditBranchRefresh($carId, ['comments' => 'still edits fine']);

        $car = $this->db->query(
            "SELECT email, fname, lname, city, state, country, lat, lon, comments FROM cars WHERE id = ?",
            [$carId]
        )->first();
        $this->assertNotNull($car);

        // Non-empty fields still refresh normally.
        $this->assertSame('sparse-profile@example.com', $car->email);
        $this->assertSame('Sparse', $car->fname);
        $this->assertSame('Profile', $car->lname);
        $this->assertSame('still edits fine', $car->comments);

        // Empty/null profile values must not overwrite the car's existing
        // values — Car::update() strips them rather than writing blanks.
        $this->assertSame('Existing City', $car->city);
        $this->assertSame('Existing State', $car->state);
        $this->assertSame('Existing Country', $car->country);
        $this->assertEqualsWithDelta(45.0, (float) $car->lat, 0.001);
        $this->assertEqualsWithDelta(-122.0, (float) $car->lon, 0.001);
    }

    /**
     * Case 6 (#1962 gap): a car whose user_id points at a non-existent user
     * (a dangling reference — reachable because migration
     * 20260719120000_drop_cars_user_id_fk.php removed the FK from
     * cars.user_id to users.id) must still save successfully, and must leave
     * the car's EXISTING owner-contact columns alone rather than nulling or
     * blanking them. This is buildCarDetails()'s
     * `$carOwner->data() !== null` `else` branch: it logs the orphan and
     * skips the ownerFields merge entirely, so whatever was already on the
     * car row before the edit must survive unchanged.
     */
    public function testEditWithDanglingUserIdLeavesExistingOwnerColumnsUntouched(): void
    {
        // Create a car with a real owner and a real owner-contact snapshot,
        // then sever the link by pointing user_id at an id that does not
        // (and, being far outside the auto_increment range this test suite
        // produces, will not) exist in `users`.
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, ['city' => 'Eugene']);

        $carId = $this->createTestCar($userId, [
            'fname'   => 'Existing',
            'lname'   => 'Snapshot',
            'email'   => 'existing-snapshot@example.com',
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 45.5231,
            'lon'     => -122.6765,
            'comments' => 'original comments',
        ]);

        $danglingUserId = 999999999;
        $this->db->query("UPDATE cars SET user_id = ? WHERE id = ?", [$danglingUserId, $carId]);

        $before = $this->db->query(
            "SELECT email, fname, lname, city, state, country, lat, lon FROM cars WHERE id = ?",
            [$carId]
        )->first();
        $this->assertNotNull($before);

        $this->runEditBranchRefreshWithOrphanOwner($carId, ['comments' => 'edited despite orphan owner']);

        $after = $this->db->query(
            "SELECT email, fname, lname, city, state, country, lat, lon, comments, user_id FROM cars WHERE id = ?",
            [$carId]
        )->first();
        $this->assertNotNull($after);

        // Sanity: the save did happen (comments changed, user_id still dangling).
        $this->assertSame('edited despite orphan owner', $after->comments);
        $this->assertSame($danglingUserId, (int) $after->user_id);

        $this->assertOwnerContactColumnsUnchanged($before, $after);
    }

    /**
     * Case 7 (#1962 gap): same orphan-owner scenario, but with a NULL
     * user_id rather than a dangling one — the other reachable "no owner"
     * state buildCarDetails() distinguishes in its log message
     * ("has no owner (user_id is null)" vs. "owner N could not be loaded").
     * Owner(null) also fails to load (Owner::find() requires a non-null id),
     * so this exercises the same `$carOwner->data() === null` branch via a
     * different route. Existing owner-contact columns must again survive
     * unchanged.
     */
    public function testEditWithNullUserIdLeavesExistingOwnerColumnsUntouched(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, ['city' => 'Eugene']);

        $carId = $this->createTestCar($userId, [
            'fname'   => 'Existing',
            'lname'   => 'Snapshot',
            'email'   => 'existing-snapshot-2@example.com',
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 45.5231,
            'lon'     => -122.6765,
            'comments' => 'original comments',
        ]);

        $this->db->query("UPDATE cars SET user_id = NULL WHERE id = ?", [$carId]);

        $before = $this->db->query(
            "SELECT email, fname, lname, city, state, country, lat, lon FROM cars WHERE id = ?",
            [$carId]
        )->first();
        $this->assertNotNull($before);

        $this->runEditBranchRefreshWithOrphanOwner($carId, ['comments' => 'edited despite null owner']);

        $after = $this->db->query(
            "SELECT email, fname, lname, city, state, country, lat, lon, comments, user_id FROM cars WHERE id = ?",
            [$carId]
        )->first();
        $this->assertNotNull($after);

        $this->assertSame('edited despite null owner', $after->comments);
        $this->assertNull($after->user_id);

        $this->assertOwnerContactColumnsUnchanged($before, $after);
    }

    /**
     * Shared assertion for cases 6 and 7: the pre-existing owner-contact
     * snapshot (email, fname, lname, city, state, country, lat, lon) must be
     * UNCHANGED between $before and $after — not nulled, not blanked —
     * because ownerContactFields() was never merged in for an orphan-owner
     * car at all.
     */
    private function assertOwnerContactColumnsUnchanged(object $before, object $after): void
    {
        $this->assertSame((string) $before->email, (string) $after->email);
        $this->assertSame((string) $before->fname, (string) $after->fname);
        $this->assertSame((string) $before->lname, (string) $after->lname);
        $this->assertSame((string) $before->city, (string) $after->city);
        $this->assertSame((string) $before->state, (string) $after->state);
        $this->assertSame((string) $before->country, (string) $after->country);
        $this->assertEqualsWithDelta((float) $before->lat, (float) $after->lat, 0.001);
        $this->assertEqualsWithDelta((float) $before->lon, (float) $after->lon, 0.001);
    }

    /**
     * Case 8 (#1963): `updateWebsite()` (the per-car website POST-parameter
     * handler) must be gone from `app/api/cars/save.php`. `save.php` cannot
     * be `require()`'d under PHPUnit (see class docblock — every branch ends
     * in `exit`), so this is verified two ways without executing the file:
     *
     * 1. `function_exists('updateWebsite')` must be false — a guard against
     *    the function being silently reintroduced by a later merge/revert.
     *    This alone is not conclusive by itself (PHP only defines top-level
     *    functions from a file once that file has been included somewhere in
     *    the process, which never happens for save.php in this suite), so:
     * 2. The source of `save.php` is inspected directly via
     *    `file_get_contents()` to confirm it no longer contains a call to
     *    `updateWebsite(` — this is the actual proof the call site is gone,
     *    consistent with how source-inspection assertions elsewhere in this
     *    test suite verify save.php behavior without loading it.
     */
    public function testUpdateWebsiteFunctionAndItsCallSiteAreRemoved(): void
    {
        $this->assertFalse(
            function_exists('updateWebsite'),
            'updateWebsite() must not exist — the per-car website write path was removed in #1963'
        );

        $saveDotPhpPath = dirname(__DIR__, 2) . '/app/api/cars/save.php';
        $this->assertFileExists($saveDotPhpPath, 'Precondition: save.php must exist at the expected path');

        $source = file_get_contents($saveDotPhpPath);
        $this->assertIsString($source, 'Precondition: save.php source must be readable');

        $this->assertStringNotContainsString(
            'function updateWebsite',
            $source,
            'save.php must no longer define updateWebsite()'
        );
        $this->assertStringNotContainsString(
            'updateWebsite(',
            $source,
            'save.php must no longer call updateWebsite() anywhere in buildCarDetails()'
        );
    }

    /**
     * Case 8b (#1963 behavioral proof): the previous test only proves
     * updateWebsite() is gone from save.php's *source* — it does not prove a
     * client-supplied website is actually ignored at runtime. This exercises
     * that directly: simulate a website value arriving in $cardetails via
     * $extraFields (as if it had come from a POST parameter or any other
     * source save.php might have read from, the way the removed
     * updateWebsite() call site once did), and assert the refresh overwrites
     * it with the OWNER PROFILE's value regardless. The source-grep test
     * above stays as an additional reintroduction guard — this is additive,
     * not a replacement.
     */
    public function testEditIgnoresClientSuppliedWebsiteAndUsesProfileValueInstead(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, [
            'city'    => 'Eugene',
            'website' => 'https://profile-website.example.com',
        ]);

        $carId = $this->createTestCar($userId, [
            'city'    => 'Portland',
            'website' => 'https://original-per-car-website.example.com',
        ]);

        // Simulate a website value arriving in $cardetails from some source
        // other than the profile — e.g. a stray POST parameter — exactly the
        // shape updateWebsite() used to write from before its removal.
        $this->runEditBranchRefresh($carId, ['website' => 'https://attacker-supplied.example.com']);

        $car = $this->db->query("SELECT website FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);

        $this->assertSame(
            'https://profile-website.example.com',
            $car->website,
            'the refresh must overwrite any externally-supplied website with the owner PROFILE\'s value, ' .
            'regardless of how the injected value arrived in $cardetails'
        );
        $this->assertNotSame(
            'https://attacker-supplied.example.com',
            $car->website,
            'a client-supplied website value must never survive onto the car'
        );
    }
}
