<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\OwnerDatabaseException;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PassThroughDatabase;

/**
 * Integration tests for Owner::syncOwnerFieldsToCars() (#1873): happy path,
 * business rules, and every per-car failure branch.
 *
 * Supersedes AdminOwnerManagementTest::testSyncLocationToCarsCopiesCoordinatesToOwnedCar(),
 * which only checked lat/lon. This suite covers all nine synced fields
 * (fname, lname, email, city, state, country, lat, lon, website), the
 * OWNER_SYNC history-row semantics, the fold-in fix for the NOT NULL car
 * identity columns on that history row, and the no-op case. It also covers
 * the per-car transactional semantics that replaced #1618's
 * syncLocationToCars() bare loop: a failing history insert rolling back the
 * UPDATE, the mid-sync ownership-change guard, a genuine UPDATE failure
 * propagating, the outer-transaction guard, and the never-loaded Owner.
 * (getCarsOwned() throwing before the loop starts lives in
 * tests/unit/OwnerReadMethodsDatabaseFailureTest.php.)
 *
 * `operation='OWNER_SYNC'` is the assertion target everywhere a history row
 * is counted — the `cars_update` AFTER UPDATE trigger writes its own
 * `operation='UPDATE'` row on every changed car, so a changed car has TWO
 * cars_hist rows and only one of them is the application's.
 *
 * Owner constructs its own internal CarRepository with no injection point,
 * but Owner and CarRepository share the same DatabaseInterface instance — so
 * the failure tests hand Owner a PassThroughDatabase subclass (see the db*()
 * factories at the bottom) that sabotages one specific call while every
 * other query runs against the real connection.
 *
 * @see usersc/classes/Owner.php Owner::syncOwnerFieldsToCars()
 * @see usersc/classes/Owner.php Owner::carBelongsToOwner()
 */
#[Group('integration')]
#[Group('owner')]
final class OwnerSyncOwnerFieldsToCarsTest extends IntegrationTestCase
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
     * Tracked for cleanup in tearDown().
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
     * Count cars_hist rows for a car scoped to operation='OWNER_SYNC' —
     * never all cars_hist rows, since the cars_update trigger writes its own
     * operation='UPDATE' row for every changed car.
     */
    private function countOwnerSyncHistoryRows(int $carId): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = 'OWNER_SYNC'",
            [$carId]
        );
        if ($result->error()) {
            throw new \RuntimeException("countOwnerSyncHistoryRows query failed: " . $result->errorString());
        }
        return (int) $result->first()->cnt;
    }

    /**
     * Count cars_hist rows written by the `cars_update` trigger (operation='UPDATE'),
     * as opposed to the application's own OWNER_SYNC rows. The trigger fires per row
     * MATCHED, so any UPDATE touching the car — including a no-op — adds one.
     */
    private function countTriggerUpdateHistoryRows(int $carId): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = 'UPDATE'",
            [$carId]
        )->first()->cnt;
    }

    /**
     * All nine owner-contact fields land on every car for a multi-car owner.
     */
    public function testAllNineFieldsLandOnEveryCarForMultiCarOwner(): void
    {
        $userId = $this->createTestUser([
            'fname' => 'Original',
            'lname' => 'Name',
            'email' => 'original@example.com',
        ]);
        $this->createTestProfile($userId, [
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 45.5231,
            'lon'     => -122.6765,
            'website' => 'https://example.com',
        ]);
        $carId1 = $this->createTestCar($userId);
        $carId2 = $this->createTestCar($userId);

        // Change the owner's identity fields via a fresh Owner::update() call
        // so syncOwnerFieldsToCars() has new values to propagate.
        $owner = new Owner($userId);
        $this->assertNotNull($owner->data(), 'Owner must load successfully');
        $owner->update([
            'id'      => $userId,
            'fname'   => 'Synced',
            'lname'   => 'Owner',
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'website' => 'https://example.com',
        ]);
        // Reload to pick up the updated values plus the unchanged lat/lon.
        $owner = new Owner($userId);

        $result = $owner->syncOwnerFieldsToCars();

        $this->assertTrue($result->isCompleteSuccess());
        $this->assertSame([$carId1, $carId2], $result->updated);

        foreach ([$carId1, $carId2] as $carId) {
            $car = $this->db->query(
                "SELECT fname, lname, email, city, state, country, lat, lon, website FROM cars WHERE id = ?",
                [$carId]
            )->first();
            $this->assertNotNull($car);
            $this->assertSame('Synced', $car->fname);
            $this->assertSame('Owner', $car->lname);
            $this->assertSame('original@example.com', $car->email);
            $this->assertSame('Portland', $car->city);
            $this->assertSame('Oregon', $car->state);
            $this->assertSame('United States', $car->country);
            $this->assertEqualsWithDelta(45.5231, (float) $car->lat, 0.001);
            $this->assertEqualsWithDelta(-122.6765, (float) $car->lon, 0.001);
            $this->assertSame('https://example.com', $car->website);
        }
    }

    /**
     * An invalid profile website is skipped by the sync, leaving the car's
     * existing website untouched, rather than writing it through and
     * planting a value that would fail CarValidator on the car's next
     * unrelated edit.
     *
     * CarRepository::updateCarForOwner() writes via raw SQL with no
     * validation of its own, unlike Car::update() (the path
     * OwnerContactRefresher::refresh() feeds, which already had this
     * protection). Before this fix, syncOwnerFieldsToCars() had none:
     * profile save, email verification, and admin sync could all write an
     * invalid website straight onto every car the owner has, and the next
     * time anyone tried to save one of those cars for any unrelated reason,
     * CarValidator would reject the save because of a website value nobody
     * involved in that edit ever touched.
     *
     * The other eight owner-contact fields must still sync normally — only
     * the invalid field is dropped, not the whole car.
     */
    public function testInvalidWebsiteIsSkippedRatherThanWrittenThrough(): void
    {
        $userId = $this->createTestUser(['fname' => 'Original']);
        $this->createTestProfile($userId, [
            'city'    => 'Portland',
            'website' => 'not-a-valid-url',
        ]);
        $carId = $this->createTestCar($userId, ['website' => 'https://existing.example.com']);

        $owner = new Owner($userId);
        $owner->update(['id' => $userId, 'fname' => 'Synced']);
        $owner = new Owner($userId);

        $result = $owner->syncOwnerFieldsToCars();
        $this->assertTrue(
            $result->isCompleteSuccess(),
            'An invalid website must not fail the whole sync — it is dropped, not fatal'
        );
        $this->assertContains($carId, $result->updated);

        $car = $this->db->query("SELECT fname, city, website FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame('Synced', $car->fname, 'The other eight fields must still sync normally');
        $this->assertSame('Portland', $car->city);
        $this->assertSame(
            'https://existing.example.com',
            $car->website,
            "The car's existing website must survive untouched when the profile's website is invalid"
        );
    }

    /**
     * Exactly one OWNER_SYNC row per car per call; total rows for a changed
     * car is 2 (trigger's operation='UPDATE' + application's operation='OWNER_SYNC').
     */
    public function testExactlyOneOwnerSyncRowPerCarWithTwoTotalRowsForChangedCar(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, ['lat' => 45.5231, 'lon' => -122.6765]);
        $carId = $this->createTestCar($userId);

        $owner = new Owner($userId);
        $result = $owner->syncOwnerFieldsToCars();

        $this->assertContains($carId, $result->updated);
        $this->assertSame(1, $this->countOwnerSyncHistoryRows($carId), 'Exactly one OWNER_SYNC row must be written per car per call');

        $totalRows = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ?",
            [$carId]
        )->first()->cnt;
        $this->assertSame(2, (int) $totalRows, 'A changed car must have exactly two cars_hist rows: the trigger UPDATE row and the application OWNER_SYNC row');
    }

    /**
     * A save that changes location and name/website together yields ONE
     * OWNER_SYNC row per sync call, not one per changed field.
     */
    public function testSaveChangingLocationAndNameYieldsOneOwnerSyncRow(): void
    {
        $userId = $this->createTestUser(['fname' => 'Before']);
        $this->createTestProfile($userId, ['city' => 'Salem', 'website' => 'https://old.example.com']);
        $carId = $this->createTestCar($userId);

        $owner = new Owner($userId);
        $owner->update([
            'id'      => $userId,
            'fname'   => 'After',
            'city'    => 'Eugene',
            'website' => 'https://new.example.com',
        ]);
        $owner = new Owner($userId);

        $result = $owner->syncOwnerFieldsToCars();

        $this->assertContains($carId, $result->updated);
        $this->assertSame(1, $this->countOwnerSyncHistoryRows($carId), 'A single sync call touching multiple fields must write exactly one OWNER_SYNC row');
    }

    /**
     * owner_last_updated stays byte-identical after a sync, and the car is
     * still returned by findVerificationEligible() — using a car that starts
     * stale on BOTH owner_last_updated AND mtime, so the eligibility result
     * genuinely depends on freshnessSql() reading the (unchanged)
     * owner_last_updated rather than the (bumped) mtime, which #1953 removed
     * from the freshness expression entirely. Without a stale starting mtime,
     * the row would already be fresh on every column at creation, and the
     * eligibility assertion below would pass regardless of whether mtime still
     * influenced eligibility — this test also asserts the mtime bump happened,
     * to prove the criterion is being exercised at all.
     */
    public function testOwnerLastUpdatedUnchangedAndCarStaysVerificationEligible(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, ['lat' => 45.5231, 'lon' => -122.6765]);

        $staleDate = date('Y-m-d H:i:s', strtotime('-3 years'));
        $carId = $this->createTestCar($userId, [
            'email'              => 'owner-eligible@example.com',
            'owner_last_updated' => $staleDate,
            'mtime'              => $staleDate,
            'email_bounced'      => 0,
            'solddate'           => null,
            'last_verified'      => null,
        ]);

        $carBeforeSync = $this->db->query("SELECT mtime FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($carBeforeSync);
        $this->assertSame($staleDate, (string) $carBeforeSync->mtime, 'Precondition: the car must start stale on mtime too, or the sync bump this test relies on cannot be observed');

        $owner = new Owner($userId);
        $result = $owner->syncOwnerFieldsToCars();
        $this->assertContains($carId, $result->updated);

        $car = $this->db->query("SELECT owner_last_updated, mtime FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame($staleDate, (string) $car->owner_last_updated, 'syncOwnerFieldsToCars() must never write owner_last_updated');
        $this->assertGreaterThan($staleDate, (string) $car->mtime, 'Precondition: the sync must actually bump mtime forward, or this test proves nothing about surviving that bump');

        $repo = new \ElanRegistry\Car\CarRepository($this->db);
        $eligible = $repo->findVerificationEligible(1000, 0);
        $eligibleIds = array_map(static fn ($c) => (int) $c->id, $eligible);
        $this->assertContains($carId, $eligibleIds, 'A car with a non-NULL, stale owner_last_updated must remain verification-eligible after a sync, despite the sync bumping mtime from stale to fresh');
    }

    /**
     * #1953: cars.owner_last_updated is NOT NULL by schema, so the NULL
     * owner_last_updated case this class's docblock used to carve out as
     * "out of scope for this issue" is no longer a state the database can
     * hold at all. createTestCar() (IntegrationTestCase) inserts via
     * DB::insert() without naming owner_last_updated when the caller omits
     * it, so an omitted value now falls through to the column's own
     * CURRENT_TIMESTAMP default rather than landing NULL — asserting that
     * confirms the column-level fix, independent of any application code
     * path, closes the gap this class previously documented as unreachable
     * from here.
     *
     * Skipped, not failed, when the #1953 migration hasn't run locally —
     * mirrors CarVerificationTimestampMigrationTest's skip-guard pattern.
     */
    public function testNullOwnerLastUpdatedNoLongerReachableAfterSchemaChange(): void
    {
        $row = $this->db->query(
            "SELECT IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cars'
               AND COLUMN_NAME  = 'owner_last_updated'
             LIMIT 1"
        )->first();

        if (!$row || $row->IS_NULLABLE !== 'NO') {
            $this->markTestSkipped(
                'Migration 20260905172137 has not been applied — cars.owner_last_updated is ' .
                'still nullable. Run: composer migrate'
            );
        }

        $userId = $this->createTestUser();
        $this->createTestProfile($userId, ['lat' => 45.5231, 'lon' => -122.6765]);

        // Deliberately omits owner_last_updated so the column default applies,
        // rather than passing null (which would now fail the INSERT outright
        // and prove nothing about syncOwnerFieldsToCars() itself).
        $carId = $this->createTestCar($userId, [
            'email'         => 'no-null-owner-updated@example.com',
            'email_bounced' => 0,
            'solddate'      => null,
            'last_verified' => null,
        ]);

        $before = $this->db->query("SELECT owner_last_updated FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($before->owner_last_updated, 'owner_last_updated must never be NULL post-#1953');

        $owner = new Owner($userId);
        $owner->syncOwnerFieldsToCars();

        $after = $this->db->query("SELECT owner_last_updated FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($after->owner_last_updated, 'owner_last_updated must remain non-NULL after a sync');
        $this->assertSame(
            (string) $before->owner_last_updated,
            (string) $after->owner_last_updated,
            'syncOwnerFieldsToCars() must never write owner_last_updated'
        );
    }

    /**
     * Historical LOCATION_SYNC rows are untouched by an OWNER_SYNC-writing sync.
     */
    public function testHistoricalLocationSyncRowsUnchangedAfterSync(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, ['lat' => 45.5231, 'lon' => -122.6765]);
        $carId = $this->createTestCar($userId);

        $this->db->insert('cars_hist', [
            'operation' => 'LOCATION_SYNC',
            'car_id'    => $carId,
            'user_id'   => $userId,
            'model'     => '',
            'series'    => '',
            'variant'   => '',
            'type'      => '',
            'chassis'   => '',
            'ctime'     => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        $owner = new Owner($userId);
        $owner->syncOwnerFieldsToCars();

        $legacyRows = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = 'LOCATION_SYNC'",
            [$carId]
        )->first()->cnt;
        $this->assertSame(1, (int) $legacyRows, 'Historical LOCATION_SYNC rows must remain untouched by a new sync');
    }

    /**
     * Fold-in fix: the OWNER_SYNC history row carries the car's real
     * model/chassis/etc., not empty strings — cars_hist declares these
     * columns NOT NULL with no default, so the pre-fix code (which omitted
     * them) failed the insert outright under STRICT_TRANS_TABLES.
     */
    public function testOwnerSyncHistoryRowCarriesRealCarIdentityColumns(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, ['lat' => 45.5231, 'lon' => -122.6765]);
        $carId = $this->createTestCar($userId, [
            'model'   => 'Elan Sprint',
            'series'  => 'Sprint',
            'variant' => 'DHC',
            'year'    => 1972,
            'type'    => 'DHC',
            'chassis' => 'REALCHASSIS01',
            'color'   => 'Blue',
        ]);

        $owner = new Owner($userId);
        $result = $owner->syncOwnerFieldsToCars();
        $this->assertContains($carId, $result->updated);

        $histRow = $this->db->query(
            "SELECT model, series, variant, year, type, chassis, color FROM cars_hist WHERE car_id = ? AND operation = 'OWNER_SYNC'",
            [$carId]
        )->first();

        $this->assertNotNull($histRow, 'An OWNER_SYNC history row must exist');
        $this->assertSame('Elan Sprint', $histRow->model);
        $this->assertSame('Sprint', $histRow->series);
        $this->assertSame('DHC', $histRow->variant);
        $this->assertSame(1972, (int) $histRow->year);
        $this->assertSame('DHC', $histRow->type);
        $this->assertSame('REALCHASSIS01', $histRow->chassis);
        $this->assertSame('Blue', $histRow->color);
    }

    /**
     * Regression test for a milestone-review finding (commit cb6b1745): the
     * OWNER_SYNC history row's free-text `comments` must never embed the
     * owner's name or location.
     *
     * app/api/cars/history.php returns cars_hist rows — including `comments`
     * verbatim — to an unauthenticated caller (CSRF was dropped from that
     * endpoint under ADR-019 in favor of rate limiting alone). Before
     * cb6b1745, this comment was built as
     * "...Name: {fname} {lname}, City: {city}, State: {state}, Country:
     * {country}", so any anonymous request for a car's history could read
     * that owner's last name and location — contradicting this same
     * milestone's own privacy-policy update, which promises a member's last
     * name stays non-public. The fix made the comment a fixed sentence with
     * no owner data; this test pins that so the leak cannot silently
     * reappear (e.g. if a future change reintroduces per-field detail into
     * the comment for debugging convenience).
     */
    public function testOwnerSyncHistoryCommentCarriesNoOwnerIdentifyingData(): void
    {
        $userId = $this->createTestUser(['fname' => 'Distinctive', 'lname' => 'Surname']);
        $this->createTestProfile($userId, [
            'city'    => 'UnlikelyCityName',
            'state'   => 'UnlikelyStateName',
            'country' => 'UnlikelyCountryName',
        ]);
        $carId = $this->createTestCar($userId);

        $owner = new Owner($userId);
        $result = $owner->syncOwnerFieldsToCars();
        $this->assertContains($carId, $result->updated);

        $histRow = $this->db->query(
            "SELECT comments FROM cars_hist WHERE car_id = ? AND operation = 'OWNER_SYNC'",
            [$carId]
        )->first();

        $this->assertNotNull($histRow, 'An OWNER_SYNC history row must exist');
        $this->assertSame(
            'Car owner contact details synchronized with owner profile update.',
            $histRow->comments,
            'The OWNER_SYNC comment must be a fixed, non-identifying sentence — this endpoint '
                . 'is served unauthenticated by app/api/cars/history.php'
        );
        $this->assertStringNotContainsString('Surname', $histRow->comments);
        $this->assertStringNotContainsString('UnlikelyCityName', $histRow->comments);
        $this->assertStringNotContainsString('UnlikelyStateName', $histRow->comments);
        $this->assertStringNotContainsString('UnlikelyCountryName', $histRow->comments);
    }

    /**
     * A sync that updates one car and skips another (mid-sync ownership
     * change) reports both lists correctly, and the skip alone does not
     * make the result read as a failure.
     *
     * Simulates the snapshot-vs-write race directly: seed Owner's private
     * `_carsOwned` cache (via Reflection) with two cars, then reassign one of
     * them away from the owner before calling sync — reproducing "a car
     * present in the getCarsOwned() snapshot but no longer owned at write
     * time" without depending on timing.
     * testCarNoLongerOwnedIsNotOverwrittenAndSkippedAndLogged() covers this
     * scenario's logging/DB-proxy details in isolation; this test just
     * confirms the returned result carries both lists correctly in one call.
     */
    public function testPartialSyncReportsUpdatedAndSkippedCarIds(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, ['lat' => 45.5231, 'lon' => -122.6765]);
        $carId1 = $this->createTestCar($userId);
        $carId2 = $this->createTestCar($userId);

        $carRow1 = $this->db->query("SELECT * FROM cars WHERE id = ?", [$carId1])->first();
        $carRow2 = $this->db->query("SELECT * FROM cars WHERE id = ?", [$carId2])->first();

        // Reassign carId2 away from this owner so the write-time ownership
        // check fails for it, while the seeded snapshot below still lists it.
        $otherUserId = $this->createTestUser();
        $this->db->query("UPDATE cars SET user_id = ? WHERE id = ?", [$otherUserId, $carId2]);

        $owner = new Owner($userId);
        $ref = new \ReflectionClass(Owner::class);
        $carsOwnedProp = $ref->getProperty('_carsOwned');
        $carsOwnedProp->setValue($owner, [$carRow1, $carRow2]);

        $result = $owner->syncOwnerFieldsToCars();

        $this->assertSame([$carId1], $result->updated);
        $this->assertSame([$carId2], $result->skipped, 'The reassigned car must appear in skipped, not failed');
        $this->assertSame([], $result->failed, 'A mid-sync ownership change is not a failure');
        $this->assertTrue($result->isCompleteSuccess(), 'A skip-only result must read as complete success');
    }

    /**
     * True no-op case: a car whose nine synced fields AND mtime already match
     * what the sync would write reports success and writes NO OWNER_SYNC row,
     * because a sync that changed nothing is not a business event.
     *
     * The `cars_update` trigger still writes its own `operation='UPDATE'` row:
     * it is AFTER UPDATE ... FOR EACH ROW and MySQL fires it per row MATCHED,
     * not per row changed. This test pins both halves — zero OWNER_SYNC rows
     * from the application, and exactly one trigger row — so that a future
     * change to either side is caught.
     *
     * syncOwnerFieldsToCars() computes its own $syncTime = date(...) internally
     * and there is no seam to inject a fixed clock, so this test sets the car's
     * mtime to date('Y-m-d H:i:s') immediately before invoking the sync to make
     * the UPDATE's mtime assignment a genuine no-op. This leaves a sub-second
     * race at a wall-clock second boundary (the car's stamped mtime and the
     * sync's computed $syncTime landing in different seconds), which is
     * guarded explicitly below rather than silently assumed away: if the race
     * is lost, mtime legitimately changed, so the "no history row" assertion
     * is skipped rather than producing a flaky failure. See
     * testStaleMtimeSyncReportsSuccessAndWritesOneHistoryRow() below for the
     * deterministic, production-representative counterpart to this test,
     * which does not depend on timing at all.
     */
    public function testTrueNoOpSyncReportsSuccessAndWritesNoHistoryRow(): void
    {
        $userId = $this->createTestUser(['fname' => 'Same', 'lname' => 'Values', 'email' => 'same@example.com']);
        $this->createTestProfile($userId, [
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 45.5231,
            'lon'     => -122.6765,
            'website' => 'https://same.example.com',
        ]);

        $owner = new Owner($userId);
        $data = $owner->data();
        $this->assertNotNull($data);

        // Create the car pre-populated with EXACTLY the owner's current values,
        // so the UPDATE this sync issues changes nothing in the nine synced
        // fields. mtime is stamped as close as possible to the sync call below
        // to make the tenth column (mtime) a no-op too — see the docblock.
        $carId = $this->createTestCar($userId, [
            'fname'   => $data->fname,
            'lname'   => $data->lname,
            'email'   => $data->email,
            'city'    => $data->city,
            'state'   => $data->state,
            'country' => $data->country,
            'lat'     => $data->lat,
            'lon'     => $data->lon,
            'website' => $data->website,
        ]);

        $mtimeBeforeSync = date('Y-m-d H:i:s');
        $this->db->query("UPDATE cars SET mtime = ? WHERE id = ?", [$mtimeBeforeSync, $carId]);

        // Measured as a delta across the sync call: the stamping UPDATE above
        // fires the trigger itself, so the absolute count is not 1.
        $triggerRowsBefore = $this->countTriggerUpdateHistoryRows($carId);

        $result = $owner->syncOwnerFieldsToCars();

        $this->assertSame([$carId], $result->updated, 'A no-op sync must still report the car as updated (success)');
        $this->assertTrue($result->isCompleteSuccess());

        $mtimeAfterSync = (string) $this->db->query("SELECT mtime FROM cars WHERE id = ?", [$carId])->first()->mtime;
        if ($mtimeAfterSync !== $mtimeBeforeSync) {
            // Lost the sub-second race at a wall-clock second boundary: mtime
            // genuinely changed, so a history row is the correct outcome, not
            // a bug. Skip rather than assert 0, to avoid a flaky failure.
            $this->markTestSkipped('mtime crossed a second boundary between stamping and sync; the UPDATE was not a true no-op this run.');
        }
        $this->assertSame(0, $this->countOwnerSyncHistoryRows($carId), 'A true no-op sync (all ten written columns unchanged) must write no OWNER_SYNC history row');

        // The trigger fires on a matched row regardless of whether values
        // changed, so exactly one operation='UPDATE' row is the correct
        // outcome here — not zero. Asserting it keeps the OWNER_SYNC-scoped
        // assertion above from being read as "a no-op writes no history".
        $this->assertSame(
            1,
            $this->countTriggerUpdateHistoryRows($carId) - $triggerRowsBefore,
            'The cars_update trigger fires on a matched row even when no value changed, so a no-op sync still adds exactly one operation=UPDATE row'
        );
    }

    /**
     * Production-representative case: the nine owner fields already match,
     * but the car's mtime is genuinely older (as a real car's almost always
     * is). The UPDATE therefore changes one column (mtime) and IS NOT a
     * no-op — it must still report success, but it DOES write an OWNER_SYNC
     * history row. This is deterministic (no wall-clock race) and is the path
     * actually exercised in production; testTrueNoOpSyncReportsSuccessAndWritesNoHistoryRow()
     * above exists solely to cover the rarer branch where mtime is already
     * exactly current.
     */
    public function testStaleMtimeSyncReportsSuccessAndWritesOneHistoryRow(): void
    {
        $userId = $this->createTestUser(['fname' => 'Same', 'lname' => 'Values', 'email' => 'same@example.com']);
        $this->createTestProfile($userId, [
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 45.5231,
            'lon'     => -122.6765,
            'website' => 'https://same.example.com',
        ]);

        $owner = new Owner($userId);
        $data = $owner->data();
        $this->assertNotNull($data);

        $staleMtime = date('Y-m-d H:i:s', strtotime('-1 day'));
        $carId = $this->createTestCar($userId, [
            'fname'   => $data->fname,
            'lname'   => $data->lname,
            'email'   => $data->email,
            'city'    => $data->city,
            'state'   => $data->state,
            'country' => $data->country,
            'lat'     => $data->lat,
            'lon'     => $data->lon,
            'website' => $data->website,
            'mtime'   => $staleMtime,
        ]);

        $result = $owner->syncOwnerFieldsToCars();

        $this->assertSame([$carId], $result->updated, 'A sync that only bumps mtime must still report the car as updated (success)');
        $this->assertTrue($result->isCompleteSuccess());

        $mtimeAfterSync = (string) $this->db->query("SELECT mtime FROM cars WHERE id = ?", [$carId])->first()->mtime;
        $this->assertGreaterThan($staleMtime, $mtimeAfterSync, 'Precondition: the sync must actually bump mtime forward, or this is not exercising the intended branch');
        $this->assertSame(1, $this->countOwnerSyncHistoryRows($carId), 'A sync that changes mtime (even with all nine owner fields already matching) is not a no-op UPDATE and must write exactly one OWNER_SYNC row');
    }

    /**
     * All three outcomes in a single sync call — the three-way distinction from
     * issue #1954's acceptance criteria.
     *
     * Nothing else pins updated/skipped/failed as mutually exclusive buckets
     * populated from ONE loop: every other test covers only two of the three.
     * This combines their techniques —
     * testPartialSyncReportsUpdatedAndSkippedCarIds() above seeds Owner's
     * private `_carsOwned` cache via Reflection to reproduce the
     * snapshot-vs-write ownership race deterministically, and
     * dbFailingHistoryInsert() proxies DatabaseInterface to fail the history
     * insert. The proxy here fails SELECTIVELY (only the one car's cars_hist
     * row) so the other two cars still travel their real code paths in the
     * same call.
     *
     * failedCarsPhrase() must name only the failed car: reporting a skip as a
     * failure is the exact defect #1954 addresses.
     */
    public function testSingleSyncSortsUpdatedSkippedAndFailedIndependently(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, ['lat' => 45.5231, 'lon' => -122.6765]);

        $carIdUpdated = $this->createTestCar($userId, ['city' => 'UpdatedCity', 'lat' => null, 'lon' => null]);
        $carIdSkipped = $this->createTestCar($userId, ['city' => 'SkippedCity', 'lat' => null, 'lon' => null]);
        $carIdFailed  = $this->createTestCar($userId, ['city' => 'FailedCity',  'lat' => null, 'lon' => null]);

        // Snapshot all three rows BEFORE the reassignment below, so the seeded
        // cache reflects what getCarsOwned() would have returned at snapshot time.
        $carRowUpdated = $this->db->query("SELECT * FROM cars WHERE id = ?", [$carIdUpdated])->first();
        $carRowSkipped = $this->db->query("SELECT * FROM cars WHERE id = ?", [$carIdSkipped])->first();
        $carRowFailed  = $this->db->query("SELECT * FROM cars WHERE id = ?", [$carIdFailed])->first();

        // Ownership changes mid-sync for exactly one car: the write-time check
        // fails for it while the seeded snapshot still lists it.
        $otherUserId = $this->createTestUser();
        $this->db->query("UPDATE cars SET user_id = ? WHERE id = ?", [$otherUserId, $carIdSkipped]);

        $db = $this->dbFailingHistoryInsert($carIdFailed);
        $owner = $this->ownerWithLoadedData($db, [
            'id'      => $userId,
            'fname'   => 'Three',
            'lname'   => 'Way',
            'email'   => 'threeway@example.com',
            'city'    => 'NewCity',
            'state'   => 'NewState',
            'country' => 'New Country',
            'lat'     => '45.5231',
            'lon'     => '-122.6765',
            'website' => 'https://example.com',
        ]);

        $ref = new \ReflectionClass(Owner::class);
        $ref->getProperty('_carsOwned')->setValue($owner, [$carRowUpdated, $carRowSkipped, $carRowFailed]);

        $result = $owner->syncOwnerFieldsToCars();

        $this->assertSame([$carIdUpdated], $result->updated, 'Only the untouched, still-owned car may be reported as updated');
        $this->assertSame([$carIdSkipped], $result->skipped, 'The reassigned car must be skipped, not failed');
        $this->assertSame([$carIdFailed], $result->failed, 'The car whose history insert failed must be reported as failed');
        $this->assertSame(3, $result->totalCount(), 'totalCount() must span all three buckets');
        $this->assertFalse($result->isCompleteSuccess(), 'A real per-car failure must make the result read as incomplete');

        // Exact phrase, not a substring probe: car IDs are auto-increment and
        // one can be a substring of another in a shared test database.
        $this->assertSame(
            "Car {$carIdFailed} could not be updated.",
            $result->failedCarsPhrase(),
            'failedCarsPhrase() must name the failed car and only the failed car (#1954)'
        );
        $this->assertSame(
            "Car {$carIdSkipped} no longer owned; not updated.",
            $result->skippedCarsPhrase(),
            'skippedCarsPhrase() must name the skipped car separately from the failed one'
        );

        // The three-way distinction made concrete at the row level.
        $updatedRow = $this->db->query("SELECT city FROM cars WHERE id = ?", [$carIdUpdated])->first();
        $this->assertSame('NewCity', $updatedRow->city, 'The updated car must hold the owner\'s new values');

        $failedRow = $this->db->query("SELECT city FROM cars WHERE id = ?", [$carIdFailed])->first();
        $this->assertSame('FailedCity', $failedRow->city, 'The failed car\'s transaction must have rolled back to its original values');

        $skippedRow = $this->db->query("SELECT city FROM cars WHERE id = ?", [$carIdSkipped])->first();
        $this->assertSame('SkippedCity', $skippedRow->city, 'The skipped car must not receive the previous owner\'s values');
    }

    /**
     * Regression guard (distinct from the not-loaded-Owner case covered by
     * testSyncOnNeverLoadedOwnerThrowsOwnerDatabaseException()): an Owner that loads
     * successfully but owns zero cars must still return an empty,
     * complete-success OwnerSyncResult — never throw. This is the case
     * getCarsOwned() legitimately returns an empty array for a valid owner,
     * as opposed to the Owner itself failing to load.
     */
    public function testOwnerWithNoCarsReturnsEmptyCompleteSuccessResult(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, ['lat' => 45.5231, 'lon' => -122.6765]);

        $owner = new Owner($userId);
        $this->assertNotNull($owner->data(), 'Precondition: Owner must load successfully');

        $result = $owner->syncOwnerFieldsToCars();

        $this->assertSame([], $result->updated);
        $this->assertSame([], $result->failed);
        $this->assertSame([], $result->skipped);
        $this->assertTrue($result->isCompleteSuccess(), 'A successfully-loaded owner with zero cars must read as complete success');
    }

    /**
     * insertHistory() failing after a successful UPDATE must roll back the
     * whole per-car transaction: the car row reverts to its ORIGINAL values,
     * no OWNER_SYNC row is written, the car is reported in `failed`, and the
     * failure is logged.
     *
     * This supersedes the old syncLocationToCars()-era
     * testInsertHistoryFailureStillCountsCarAsUpdatedAndLogsSeparately(),
     * which asserted the OPPOSITE — that the update persisted despite the
     * history failure — encoding the pre-transaction semantics #1873 replaced.
     */
    public function testHistoryInsertFailureRollsBackCarUpdateAndReportsFailed(): void
    {
        $userId = $this->createTestUser();
        $carId = $this->createTestCar($userId, [
            'city' => 'OriginalCity',
            'lat'  => null,
            'lon'  => null,
        ]);

        $logPattern = "syncOwnerFieldsToCars: failed to insert history record for car ID {$carId}%";
        $before = $this->countMatchingLogs('OwnerActions', $logPattern);

        $db = $this->dbFailingHistoryInsert();
        $owner = $this->ownerWithLoadedData($db, [
            'id'      => $userId,
            'fname'   => 'Synced',
            'lname'   => 'Owner',
            'email'   => 'synced@example.com',
            'city'    => 'NewCity',
            'state'   => 'NewState',
            'country' => 'New Country',
            'lat'     => '45.5231',
            'lon'     => '-122.6765',
            'website' => 'https://example.com',
        ]);

        $result = $owner->syncOwnerFieldsToCars();

        $this->assertSame([], $result->updated, 'A car whose history insert fails must not appear in updated');
        $this->assertSame([$carId], $result->failed, 'A car whose history insert fails must appear in failed');
        $this->assertFalse($result->isCompleteSuccess());

        // The UPDATE must have been rolled back — original values persist.
        $car = $this->db->query("SELECT city, lat FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame('OriginalCity', $car->city, 'The car UPDATE must be rolled back when the history insert fails');
        $this->assertNull($car->lat, 'The car UPDATE must be rolled back when the history insert fails');

        // No OWNER_SYNC row — the whole transaction, insert included, rolled back.
        $this->assertSame(0, $this->countOwnerSyncHistoryRows($carId), 'No OWNER_SYNC history row must exist when its own insert fails and the transaction rolls back');

        $after = $this->countMatchingLogs('OwnerActions', $logPattern);
        $this->assertSame($before + 1, $after, 'The history-insert failure must be logged under LOG_CATEGORY_OWNER_ACTIONS');
    }

    /**
     * Ownership-scoping guard: a car present in the getCarsOwned() snapshot
     * but no longer owned by this user at write time (e.g. transferred to
     * another owner between the snapshot read and the per-car write) must NOT
     * be overwritten, must be reported in `skipped` (not `failed` — this is
     * expected behavior, not an error), and must be logged.
     *
     * Reproduced deterministically rather than via real timing: the car is
     * reassigned to another user for real, up front. dbWithStaleSnapshotIncluding()
     * then makes getCarsOwned()'s own SELECT report that car anyway — as if
     * the reassignment had happened just after the snapshot was taken — while
     * every other query (the per-car UPDATE and the carBelongsToOwner()
     * ownership check) hits the real database and sees the car's actual
     * current owner. Unlike testPartialSyncReportsUpdatedAndSkippedCarIds(),
     * this also pins the car row, the history row and the log row.
     */
    public function testCarNoLongerOwnedIsNotOverwrittenAndSkippedAndLogged(): void
    {
        $userId = $this->createTestUser();
        $otherUserId = $this->createTestUser();
        $carId = $this->createTestCar($userId, [
            'city' => 'OriginalCity',
            'lat'  => null,
            'lon'  => null,
        ]);

        // The car has genuinely already been transferred away from $userId by
        // the time syncOwnerFieldsToCars() runs — only the getCarsOwned()
        // snapshot below is stale.
        $this->db->query("UPDATE cars SET user_id = ? WHERE id = ?", [$otherUserId, $carId]);

        $logPattern = "syncOwnerFieldsToCars: car ID {$carId} is no longer owned by user {$userId}%";
        $before = $this->countMatchingLogs('OwnerActions', $logPattern);

        $db = $this->dbWithStaleSnapshotIncluding($userId, $carId);
        $owner = $this->ownerWithLoadedData($db, [
            'id'      => $userId,
            'fname'   => 'Synced',
            'lname'   => 'Owner',
            'email'   => 'synced@example.com',
            'city'    => 'NewCity',
            'state'   => 'NewState',
            'country' => 'New Country',
            'lat'     => '45.5231',
            'lon'     => '-122.6765',
            'website' => 'https://example.com',
        ]);

        $result = $owner->syncOwnerFieldsToCars();

        $this->assertSame([], $result->updated, 'The reassigned car must not appear in updated');
        $this->assertSame([$carId], $result->skipped, 'The reassigned car must appear in skipped, not failed');
        $this->assertSame([], $result->failed, 'A mid-sync ownership change is not a failure');
        $this->assertTrue($result->isCompleteSuccess(), 'A skip-only result must read as complete success');

        // The car's real, current values must NOT have been overwritten with
        // this (former) owner's data.
        $car = $this->db->query("SELECT city, lat FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame('OriginalCity', $car->city, 'A car no longer owned by this user must not be overwritten');
        $this->assertNull($car->lat, 'A car no longer owned by this user must not be overwritten');

        // No OWNER_SYNC history row for a car whose write was rolled back.
        $this->assertSame(0, $this->countOwnerSyncHistoryRows($carId), 'No OWNER_SYNC history row must be written for a car that left this owner');

        $after = $this->countMatchingLogs('OwnerActions', $logPattern);
        $this->assertSame($before + 1, $after, 'Losing ownership mid-sync must be logged under LOG_CATEGORY_OWNER_ACTIONS');
    }

    /**
     * A genuine UPDATE failure (not a 0-row-matched ambiguity) is an
     * infrastructure failure, not a per-car outcome: it must roll the car's
     * transaction back and propagate as CarDatabaseException rather than be
     * recorded in `failed`. Reporting a DB outage as N individual "car could
     * not be updated" results hides the actual fault from the caller.
     */
    public function testUpdateQueryFailureRollsBackAndPropagates(): void
    {
        $userId = $this->createTestUser();
        $carId = $this->createTestCar($userId, [
            'chassis' => 'SYNCFAIL1',
            'city'    => 'OriginalCity',
            'lat'     => null,
        ]);

        $db = $this->dbFailingOwnerScopedUpdate();
        $owner = $this->ownerWithLoadedData($db, [
            'id'      => $userId,
            'fname'   => 'Synced',
            'lname'   => 'Owner',
            'email'   => 'synced@example.com',
            'city'    => 'NewCity',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => '45.5231',
            'lon'     => '-122.6765',
            'website' => 'https://example.com',
        ]);

        $thrown = null;
        try {
            $owner->syncOwnerFieldsToCars();
        } catch (CarDatabaseException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'A DB-level UPDATE failure must propagate, not be recorded as a per-car failure');
        $this->assertStringContainsString(
            'simulated deadlock',
            $thrown->getMessage(),
            'The propagating exception must carry the underlying MySQL error string'
        );

        // Confirm the car's values were NOT actually changed in the real DB:
        // the per-car transaction is rolled back before the exception propagates.
        $car = $this->db->query("SELECT city, lat FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame('OriginalCity', $car->city, 'Car city must remain unchanged when the UPDATE query fails');
        $this->assertNull($car->lat, 'Car lat must remain unchanged when the UPDATE query fails');
    }

    /**
     * Gap A (#1873 round-two review): the outer-transaction guard at the top
     * of syncOwnerFieldsToCars() has no test pinning it. CarRepository's
     * beginTransaction()/commit()/rollback() are nesting-aware no-ops when an
     * outer transaction is already open on the shared connection — so if this
     * guard were ever deleted, a per-car rollback inside an outer transaction
     * would silently do nothing, committing a car row without its audit row
     * once the outer transaction later commits. That is the exact bug #1873
     * exists to fix, inverted. This test proves the guard actually fires, and
     * that it fires BEFORE any work — no car row touched, no OWNER_SYNC
     * history row written — by opening a real outer transaction on the
     * connection Owner holds before calling syncOwnerFieldsToCars().
     */
    public function testSyncInsideOuterTransactionThrowsBeforeAnyWork(): void
    {
        $userId = $this->createTestUser();
        $carId = $this->createTestCar($userId, [
            'chassis' => 'OUTERTXN1',
            'city'    => 'OriginalCity',
            'lat'     => null,
        ]);

        $owner = $this->ownerWithLoadedData($this->db, [
            'id'      => $userId,
            'fname'   => 'Synced',
            'lname'   => 'Owner',
            'email'   => 'synced@example.com',
            'city'    => 'NewCity',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => '45.5231',
            'lon'     => '-122.6765',
            'website' => 'https://example.com',
        ]);

        $this->assertFalse(
            $this->db->inTransaction(),
            'Precondition: the shared connection must not already be inside a transaction'
        );

        $thrown = null;
        $this->db->beginTransaction();
        try {
            $owner->syncOwnerFieldsToCars();
        } catch (OwnerDatabaseException $e) {
            $thrown = $e;
        } finally {
            // Unconditionally roll back the transaction opened above so a
            // failing assertion below cannot leave the suite's shared
            // connection stuck inside a transaction and cascade failures
            // into unrelated tests. syncOwnerFieldsToCars() never commits or
            // rolls back this outer transaction itself (it only throws), so
            // it is always still open here.
            $this->db->rollBack();
        }

        $this->assertNotNull(
            $thrown,
            'syncOwnerFieldsToCars() must throw OwnerDatabaseException when called inside an outer transaction'
        );
        $this->assertStringContainsString(
            'outer transaction',
            $thrown->getMessage(),
            'The exception message must name the outer-transaction problem'
        );

        // Guard must fire before any per-car work — the car must be untouched.
        $car = $this->db->query("SELECT city, lat FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame('OriginalCity', $car->city, 'The guard must fire before any car row is modified');
        $this->assertNull($car->lat, 'The guard must fire before any car row is modified');

        $this->assertSame(
            0,
            $this->countOwnerSyncHistoryRows($carId),
            'The guard must fire before any OWNER_SYNC history row is written'
        );
    }

    /**
     * Gap B (#1873 round-two review): the one branch where diagnosability was
     * genuinely fragile. CarRepository::updateCarForOwner() throws
     * CarDatabaseException on a genuine UPDATE failure from inside the per-car
     * transaction. It deliberately writes no log row of its own — one written
     * there would be destroyed by the rollback before the exception escapes
     * (InnoDB `logs` table; a row inserted in a transaction does not survive
     * ROLLBACK). The propagating catch in syncOwnerFieldsToCars() therefore logs
     * AFTER the rollback, recording the partial state (which cars already
     * committed, and where the abort happened).
     *
     * This test forces the failure on the LATER of two cars, so one car has
     * already committed by the time the failure hits, and asserts:
     *  - CarDatabaseException propagates to the caller
     *  - a log row under LOG_CATEGORY_DATABASE_ERROR survives (proving the
     *    post-rollback logging placement actually works, not just that a
     *    logger() call exists in the source)
     *  - that surviving log names both the aborted car ID and the
     *    already-committed car ID
     *  - the already-committed car's synced values are genuinely present in
     *    the DB, proving the partial state the log describes is real
     */
    public function testUpdateQueryFailureOnLaterCarLogsPartialStateAfterRollback(): void
    {
        $userId = $this->createTestUser();
        $committedCarId = $this->createTestCar($userId, [
            'chassis' => 'PARTIAL01',
            'city'    => 'OriginalCity',
            'lat'     => null,
        ]);
        $abortedCarId = $this->createTestCar($userId, [
            'chassis' => 'PARTIAL02',
            'city'    => 'OriginalCity',
            'lat'     => null,
        ]);

        // getCarsOwned() orders by model, year — both test cars share the
        // default model/year, so insertion order (committedCarId first) is
        // the tie-break MySQL uses in practice for otherwise-equal sort keys
        // on a single-table scan. Assert that ordering explicitly so the
        // "later car" premise is verified rather than assumed.
        $ownedIds = array_map(
            static fn ($c) => (int) $c->id,
            (new Owner($userId))->getCarsOwned()
        );
        $this->assertSame(
            [$committedCarId, $abortedCarId],
            $ownedIds,
            'Precondition: committedCarId must be processed before abortedCarId'
        );

        $logCountBefore = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_DATABASE_ERROR,
            "syncOwnerFieldsToCars: aborted at car ID {$abortedCarId}%"
        );

        $db = $this->dbFailingOwnerScopedUpdate($abortedCarId);
        $owner = $this->ownerWithLoadedData($db, [
            'id'      => $userId,
            'fname'   => 'Synced',
            'lname'   => 'Owner',
            'email'   => 'synced@example.com',
            'city'    => 'NewCity',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => '45.5231',
            'lon'     => '-122.6765',
            'website' => 'https://example.com',
        ]);

        $thrown = null;
        try {
            $owner->syncOwnerFieldsToCars();
        } catch (CarDatabaseException $e) {
            $thrown = $e;
        }

        $this->assertNotNull(
            $thrown,
            'CarDatabaseException must propagate when a later car\'s UPDATE fails'
        );

        $logCountAfter = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_DATABASE_ERROR,
            "syncOwnerFieldsToCars: aborted at car ID {$abortedCarId}%"
        );
        $this->assertSame(
            $logCountBefore + 1,
            $logCountAfter,
            'A log row recording the abort must survive the per-car rollback — the row is '
            . 'written after rollback() returns, not inside the failed transaction'
        );

        $survivingLog = $this->db->query(
            "SELECT lognote FROM logs WHERE logtype = ? AND lognote LIKE ? ORDER BY id DESC LIMIT 1",
            [LogCategories::LOG_CATEGORY_DATABASE_ERROR, "syncOwnerFieldsToCars: aborted at car ID {$abortedCarId}%"]
        )->first();
        $this->assertNotNull($survivingLog, 'The surviving log row must be readable back from the DB');
        $this->assertStringContainsString(
            (string) $abortedCarId,
            $survivingLog->lognote,
            'The surviving log must name the car ID where the abort happened'
        );
        $this->assertStringContainsString(
            (string) $committedCarId,
            $survivingLog->lognote,
            'The surviving log must name the already-committed car ID(s), proving the partial '
            . 'state is recorded, not just the failure point'
        );

        // Prove the partial state the log describes is real: the earlier car
        // really did commit its synced values before the later car aborted.
        $committedCar = $this->db->query(
            "SELECT city, lat FROM cars WHERE id = ?",
            [$committedCarId]
        )->first();
        $this->assertNotNull($committedCar);
        $this->assertSame(
            'NewCity',
            $committedCar->city,
            'The already-committed car must genuinely hold the synced value in the DB'
        );
        $this->assertSame(
            '45.5231',
            $committedCar->lat,
            'The already-committed car must genuinely hold the synced value in the DB'
        );
        $this->assertSame(
            1,
            $this->countOwnerSyncHistoryRows($committedCarId),
            'The already-committed car must have its OWNER_SYNC history row too — the commit was whole'
        );

        // And the aborted car must show no trace of the attempted update.
        $abortedCar = $this->db->query(
            "SELECT city, lat FROM cars WHERE id = ?",
            [$abortedCarId]
        )->first();
        $this->assertNotNull($abortedCar);
        $this->assertSame('OriginalCity', $abortedCar->city, 'The aborted car must remain unchanged');
        $this->assertNull($abortedCar->lat, 'The aborted car must remain unchanged');
        $this->assertSame(
            0,
            $this->countOwnerSyncHistoryRows($abortedCarId),
            'The aborted car must have no OWNER_SYNC history row — its transaction was rolled back'
        );
    }

    /**
     * An Owner constructed with a user ID whose row no longer exists (find()
     * runs, queries the database, and returns false, so $this->_data stays
     * null) must throw OwnerDatabaseException from syncOwnerFieldsToCars()
     * rather than silently returning an empty, complete-success
     * OwnerSyncResult. Silently succeeding here would hide a genuine
     * precondition failure — the caller asked to sync a nonexistent owner —
     * behind a result indistinguishable from "owner has zero cars".
     *
     * The user is created then deleted so find() actually executes its query
     * and returns false for a real, once-valid ID — not merely skipped via
     * the constructor's `if ($id)` guard, which a userId of 0 or null would
     * trigger without ever calling find() at all.
     */
    public function testSyncOnNeverLoadedOwnerThrowsOwnerDatabaseException(): void
    {
        $userId = $this->createTestUser();
        $this->db->delete('users', ['id', '=', $userId]);

        $owner = new Owner($userId);
        $this->assertNull($owner->data(), 'Precondition: Owner must have failed to load');

        $this->expectException(OwnerDatabaseException::class);
        $this->expectExceptionMessage('called on an Owner that failed to load');

        $owner->syncOwnerFieldsToCars();
    }

    /**
     * A proxy whose insert() fails for cars_hist rows, forcing
     * CarRepository::insertHistory() to fail after the real UPDATE succeeded.
     *
     * With $failingCarId, only that car's history row fails (insertHistory()
     * passes the target car in $fields['car_id']), so sibling cars in the same
     * sync call commit normally — the mixed outcome the three-way test needs.
     * With null, every car's history insert fails.
     */
    private function dbFailingHistoryInsert(?int $failingCarId = null): DatabaseInterface
    {
        return new class ($this->db, $failingCarId) extends PassThroughDatabase {
            public function __construct(DatabaseInterface $real, private ?int $failingCarId)
            {
                parent::__construct($real);
            }

            public function insert(string $table, array $fields = [], bool $update = false): bool
            {
                if (
                    $table === 'cars_hist'
                    && ($this->failingCarId === null || (int) ($fields['car_id'] ?? 0) === $this->failingCarId)
                ) {
                    return false;
                }
                return parent::insert($table, $fields, $update);
            }
        };
    }

    /**
     * A proxy that reports a database error ('simulated deadlock') for the
     * `UPDATE cars SET ... WHERE id = ? AND user_id = ?` call issued by
     * CarRepository::updateCarForOwner() — forcing it to throw
     * CarDatabaseException, exactly as a genuine deadlock or constraint
     * violation would. Every other query (including getCarsOwned()'s own
     * read) passes through untouched.
     *
     * With $targetCarId, only that car's UPDATE fails, so cars processed
     * before it commit for real and the failure lands after partial progress
     * (Gap B). With null, every such UPDATE fails.
     */
    private function dbFailingOwnerScopedUpdate(?int $targetCarId = null): DatabaseInterface
    {
        return new class ($this->db, $targetCarId) extends PassThroughDatabase {
            private bool $lastCallFailed = false;

            public function __construct(DatabaseInterface $real, private ?int $targetCarId)
            {
                parent::__construct($real);
            }

            public function query(string $sql, array $params = []): static
            {
                // updateCarForOwner() binds the car id second-to-last, before user_id.
                $this->lastCallFailed = str_starts_with($sql, 'UPDATE cars SET')
                    && str_ends_with($sql, 'WHERE id = ? AND user_id = ?')
                    && ($this->targetCarId === null || (int) ($params[count($params) - 2] ?? 0) === $this->targetCarId);

                return $this->lastCallFailed ? $this : parent::query($sql, $params);
            }

            public function error(): bool
            {
                return $this->lastCallFailed || $this->real->error();
            }

            public function errorString(): string
            {
                return $this->lastCallFailed ? 'simulated deadlock' : $this->real->errorString();
            }
        };
    }

    /**
     * A proxy that appends one extra row — for $staleCarId, a car already
     * reassigned away from $ownerId in the real database — to the FIRST
     * result set of getCarsOwned()'s own
     * `SELECT c.* FROM cars c WHERE c.user_id = ?` query for $ownerId. Only
     * that first matching call is affected, so a later read of the same shape
     * does not also get the stale row.
     */
    private function dbWithStaleSnapshotIncluding(int $ownerId, int $staleCarId): DatabaseInterface
    {
        return new class ($this->db, $ownerId, $staleCarId) extends PassThroughDatabase {
            private bool $pendingInjection = false;

            public function __construct(
                DatabaseInterface $real,
                private int $ownerId,
                private int $staleCarId
            ) {
                parent::__construct($real);
            }

            public function query(string $sql, array $params = []): static
            {
                parent::query($sql, $params);

                $this->pendingInjection = str_starts_with($sql, 'SELECT c.* FROM cars c WHERE c.user_id = ?')
                    && ($params[0] ?? null) === $this->ownerId;

                return $this;
            }

            public function count(): int
            {
                return $this->real->count() + ($this->pendingInjection ? 1 : 0);
            }

            public function results(bool $assoc = false): array
            {
                $rows = $this->real->results($assoc);
                if ($this->pendingInjection) {
                    $this->pendingInjection = false;
                    $rows[] = $this->real->query("SELECT * FROM cars WHERE id = ?", [$this->staleCarId])->first();
                }
                return $rows;
            }
        };
    }
}
