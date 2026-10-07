<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Owner;
use ElanRegistry\OwnerContactRefresher;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1962: editing a car refreshes its denormalized owner-contact columns from
 * the owner's current profile.
 *
 * save.php cannot be required under PHPUnit (every branch exits), so the tests
 * call the real {@see \ElanRegistry\OwnerContactRefresher}. Do not hand-copy
 * the merge: a copy passes even when the endpoint's call is deleted.
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

    /** Create a profile row for a test user; tracked for cleanup in tearDown(). */
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
     * Run buildCarDetails()'s edit-branch refresh against a real car row and
     * Owner, then persist via Car::update() as save.php does.
     *
     * $isOwnerInitiated mirrors save.php's updateCar(): true when the editor is
     * the car's owner. Car::update() uses it to bump owner_last_updated.
     */
    private function runEditBranchRefresh(int $carId, array $extraFields = [], bool $isOwnerInitiated = false): void
    {
        $carRow = $this->db->query("SELECT * FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($carRow, 'Precondition: car must exist');

        $cardetails = (array) $carRow;
        // The raw cars.model display string is not the "series|variant|type"
        // format Car::update() expects, and model parsing is not under test.
        unset($cardetails['model']);
        foreach ($extraFields as $key => $value) {
            $cardetails[$key] = $value;
        }

        // Load the CAR's owner, never the session user (PII leak otherwise).
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
     * buildCarDetails()'s orphan-owner branch: when $carOwner->data() is null,
     * the owner-contact fields are not merged.
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

        // A refresher that blanked columns for an unloadable owner must fail here.
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

    /** Stale owner columns refresh from the profile when an unrelated field is edited. */
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
     * #1963: website refreshes like the other contact columns; owner_last_updated
     * and join_date stay unchanged for an admin edit ($isOwnerInitiated = false).
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

        $this->runEditBranchRefresh($carId, ['comments' => 'after'], isOwnerInitiated: false);

        $after = $this->db->query(
            "SELECT website, owner_last_updated, join_date, city, comments FROM cars WHERE id = ?",
            [$carId]
        )->first();
        $this->assertNotNull($after);

        // Proves the refresh ran, so the assertions below prove exclusion.
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

    /** #1963: the profile's website wins over the car's stale per-car value. */
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
     * #1963/#1979: an invalid profile website is skipped. Merging it would make
     * Car::update() throw and block every later edit of the owner's cars.
     */
    public function testEditSkipsInvalidProfileWebsiteAndKeepsExistingCarWebsite(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, [
            'city'    => 'Eugene',
            // Fails FILTER_VALIDATE_URL and the http(s) scheme check.
            'website' => 'not-a-url',
        ]);

        $carId = $this->createTestCar($userId, [
            'city'    => 'Portland',
            'website' => 'https://existing-valid-website.example.com',
        ]);

        $this->runEditBranchRefresh($carId, ['comments' => 'edited']);

        $car = $this->db->query("SELECT city, website, comments FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);

        // Proves the refresh ran, so the website assertion proves a targeted skip.
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
     * #1963: an empty profile website clears the car website to NULL, because
     * website is in Car::CLEARABLE_FIELDS. An empty city is left unchanged,
     * because CarValidator drops an empty city before Car::update().
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

        // Mutation testing showed the assertions above do not catch 'city'
        // being added to CLEARABLE_FIELDS. These assertions do.
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
     * #1962: without this test, the previous test's "owner_last_updated
     * unchanged" assertion could pass because nothing ever writes it.
     * Only $isOwnerInitiated differs between the two runs.
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

        $this->runEditBranchRefresh($adminEditCarId, ['comments' => 'after'], isOwnerInitiated: false);

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
     * Security: when an admin edits a member's car, the refresh writes the
     * member's contact data, never the admin's (staff PII on a public record).
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

        $carId = $this->createTestCar($memberId, [
            'fname' => 'Stale',
            'lname' => 'Snapshot',
            'email' => 'stale-snapshot@example.com',
            'city'  => 'Portland',
        ]);

        // No session user is set: the refresh must depend only on the car's user_id.
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
     * #1963: the add-car path fills all nine ownerContactFields() columns,
     * including website, from the profile.
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

        // Mirrors buildCarDetails()'s add-car branch.
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
     * #1963/#1979: an invalid profile website must not block adding a new car.
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

        // No try/catch: Car::create() used to throw here (#1979).
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
     * Empty city and null lat/lon are not in CLEARABLE_FIELDS, so Car::update()
     * drops them instead of blanking the car's values.
     */
    public function testOwnerWithEmptyCityAndNullLatLonIsNoOpForThoseFieldsWithoutCrashing(): void
    {
        $userId = $this->createTestUser([
            'fname' => 'Sparse',
            'lname' => 'Profile',
            'email' => 'sparse-profile@example.com',
        ]);
        // city/state/country default to '' and lat/lon stay null.
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

        $this->assertSame('sparse-profile@example.com', $car->email);
        $this->assertSame('Sparse', $car->fname);
        $this->assertSame('Profile', $car->lname);
        $this->assertSame('still edits fine', $car->comments);

        $this->assertSame('Existing City', $car->city);
        $this->assertSame('Existing State', $car->state);
        $this->assertSame('Existing Country', $car->country);
        $this->assertEqualsWithDelta(45.0, (float) $car->lat, 0.001);
        $this->assertEqualsWithDelta(-122.0, (float) $car->lon, 0.001);
    }

    /**
     * #1962: a dangling user_id (no FK since 20260719120000) must save and
     * leave the existing owner-contact columns unchanged.
     */
    public function testEditWithDanglingUserIdLeavesExistingOwnerColumnsUntouched(): void
    {
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

        $this->assertSame('edited despite orphan owner', $after->comments);
        $this->assertSame($danglingUserId, (int) $after->user_id);

        $this->assertOwnerContactColumnsUnchanged($before, $after);
    }

    /** #1962: a NULL user_id must leave the existing owner-contact columns unchanged. */
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
     * #1963: the per-car website write path (updateWebsite()) was removed. A
     * website value in $cardetails, from any source, must not reach the car.
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

    /**
     * #1963: save.php must not write a client-supplied website again. save.php
     * cannot be loaded under PHPUnit (every branch ends in exit), so this reads
     * its source. #2333 replaces it with a test of extracted code.
     */
    public function testSaveDotPhpNoLongerCallsUpdateWebsite(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/api/cars/save.php');
        $this->assertIsString($source, 'save.php must be readable');

        $this->assertStringNotContainsString('updateWebsite(', $source,
            'save.php must not call or define updateWebsite() (#1963)');
    }
}
