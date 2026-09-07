<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for Owner::syncOwnerFieldsToCars()'s happy path and
 * business rules (#1873).
 *
 * Supersedes AdminOwnerManagementTest::testSyncLocationToCarsCopiesCoordinatesToOwnedCar(),
 * which only checked lat/lon. This suite covers all nine synced fields
 * (fname, lname, email, city, state, country, lat, lon, website), the
 * OWNER_SYNC history-row semantics, the fold-in fix for the NOT NULL car
 * identity columns on that history row, and the no-op case.
 *
 * `operation='OWNER_SYNC'` is the assertion target everywhere a history row
 * is counted — the `cars_update` AFTER UPDATE trigger writes its own
 * `operation='UPDATE'` row on every changed car, so a changed car has TWO
 * cars_hist rows and only one of them is the application's.
 *
 * @see usersc/classes/Owner.php Owner::syncOwnerFieldsToCars()
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
     * time" without depending on timing. The dedicated
     * OwnerSyncOwnerFieldsToCarsOwnershipScopingTest suite covers this
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
     * populated from ONE loop: each sibling suite covers only two of the three.
     * This combines their techniques —
     * testPartialSyncReportsUpdatedAndSkippedCarIds() above seeds Owner's
     * private `_carsOwned` cache via Reflection to reproduce the
     * snapshot-vs-write ownership race deterministically, and
     * OwnerSyncOwnerFieldsToCarsRollbackTest::dbFailingHistoryInsert() proxies
     * DatabaseInterface to fail the history insert. The proxy here fails
     * SELECTIVELY (only the one car's cars_hist row) so the other two cars
     * still travel their real code paths in the same call.
     *
     * failedCarsPhrase() must name only the failed car: reporting a skip as a
     * failure is the exact defect #1954 addresses.
     *
     * @see OwnerSyncOwnerFieldsToCarsRollbackTest::dbFailingHistoryInsert()
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

        $db = $this->dbFailingHistoryInsertForCar($carIdFailed);
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
     * Regression guard (distinct from the not-loaded-Owner case covered in
     * OwnerSyncOwnerFieldsToCarsFailureTest): an Owner that loads
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
     * A DatabaseInterface proxy over the real connection that fails insert()
     * ONLY for the cars_hist row belonging to $failingCarId.
     *
     * OwnerSyncOwnerFieldsToCarsRollbackTest::dbFailingHistoryInsert() fails
     * every insert, which cannot express a mixed outcome. CarRepository::
     * insertHistory() passes the target car in $fields['car_id'], so keying on
     * that lets one car fail while its siblings commit normally in the same
     * sync call.
     */
    private function dbFailingHistoryInsertForCar(int $failingCarId): \ElanRegistry\DatabaseInterface
    {
        return new class ($this->db, $failingCarId) implements \ElanRegistry\DatabaseInterface {
            public function __construct(
                private \ElanRegistry\DatabaseInterface $real,
                private int $failingCarId
            ) {
            }
            public function query(string $sql, array $params = []): self
            {
                $this->real->query($sql, $params);
                return $this;
            }
            public function get(string $table, array $where): self|false
            {
                return $this->real->get($table, $where) === false ? false : $this;
            }
            public function insert(string $table, array $fields = [], bool $update = false): bool
            {
                if ($table === 'cars_hist' && (int) ($fields['car_id'] ?? 0) === $this->failingCarId) {
                    return false;
                }
                return $this->real->insert($table, $fields, $update);
            }
            public function update(string $table, array|int $id, array $fields): bool
            {
                return $this->real->update($table, $id, $fields);
            }
            public function delete(string $table, array|int $where): self|false
            {
                return $this->real->delete($table, $where) === false ? false : $this;
            }
            public function error(): bool
            {
                return $this->real->error();
            }
            public function errorString(): string
            {
                return $this->real->errorString();
            }
            public function errorInfo(): array
            {
                return $this->real->errorInfo();
            }
            public function count(): int
            {
                return $this->real->count();
            }
            public function first(bool $assoc = false): array|object
            {
                return $this->real->first($assoc);
            }
            public function results(bool $assoc = false): array
            {
                return $this->real->results($assoc);
            }
            public function lastId(): int
            {
                return $this->real->lastId();
            }
            public function beginTransaction(): bool
            {
                return $this->real->beginTransaction();
            }
            public function commit(): bool
            {
                return $this->real->commit();
            }
            public function rollBack(): bool
            {
                return $this->real->rollBack();
            }
            public function inTransaction(): bool
            {
                return $this->real->inTransaction();
            }
        };
    }
}
