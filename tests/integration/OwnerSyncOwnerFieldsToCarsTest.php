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
 * Integration tests for Owner::syncOwnerFieldsToCars() (#1873).
 *
 * Count history rows by operation='OWNER_SYNC': the cars_update trigger also
 * writes an operation='UPDATE' row for every matched car.
 *
 * Owner has no CarRepository injection point, so failure tests pass a
 * PassThroughDatabase subclass that breaks one call and passes the rest.
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

    /** Tracked for cleanup in tearDown(). */
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

    /** The cars_update trigger also writes operation='UPDATE' rows, so scope to OWNER_SYNC. */
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

    /** The trigger fires per row MATCHED, so a no-op UPDATE also adds one. */
    private function countTriggerUpdateHistoryRows(int $carId): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = 'UPDATE'",
            [$carId]
        )->first()->cnt;
    }

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
     * updateCarForOwner() does no validation, so an invalid profile website
     * would make the next unrelated edit of the car fail CarValidator.
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

    /** A changed car has 2 rows: the trigger's UPDATE and the application's OWNER_SYNC. */
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
     * The car starts stale on owner_last_updated and mtime, so eligibility
     * depends on freshnessSql() not reading mtime (#1953).
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
     * #1953: owner_last_updated is NOT NULL; an omitted value takes the column default.
     * Skips when the #1953 migration has not run locally.
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

        // Omitted, not null: a null fails the INSERT and proves nothing.
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
     * cars_hist identity columns are NOT NULL with no default; empty values failed
     * the insert under STRICT_TRANS_TABLES.
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
     * Privacy (cb6b1745): app/api/cars/history.php returns comments verbatim to
     * members, so the comment must not hold the last name or location.
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
            'The OWNER_SYNC comment must be a fixed, non-identifying sentence — app/api/cars/history.php '
                . 'still returns it to any logged-in member, so it must carry no owner-identifying data'
        );
        $this->assertStringNotContainsString('Surname', $histRow->comments);
        $this->assertStringNotContainsString('UnlikelyCityName', $histRow->comments);
        $this->assertStringNotContainsString('UnlikelyStateName', $histRow->comments);
        $this->assertStringNotContainsString('UnlikelyCountryName', $histRow->comments);
    }

    /**
     * Seeds the _carsOwned cache via Reflection to reproduce the
     * snapshot-versus-write ownership race without timing.
     */
    public function testPartialSyncReportsUpdatedAndSkippedCarIds(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, ['lat' => 45.5231, 'lon' => -122.6765]);
        $carId1 = $this->createTestCar($userId);
        $carId2 = $this->createTestCar($userId);

        $carRow1 = $this->db->query("SELECT * FROM cars WHERE id = ?", [$carId1])->first();
        $carRow2 = $this->db->query("SELECT * FROM cars WHERE id = ?", [$carId2])->first();

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
     * No OWNER_SYNC row when nothing changed. The trigger still writes one
     * UPDATE row because it fires per row matched.
     *
     * No clock seam: if mtime and $syncTime fall in different seconds, the
     * test skips instead of failing. The deterministic counterpart is
     * testStaleMtimeSyncReportsSuccessAndWritesOneHistoryRow().
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

        // A delta: the mtime stamp above also fires the trigger.
        $triggerRowsBefore = $this->countTriggerUpdateHistoryRows($carId);

        $result = $owner->syncOwnerFieldsToCars();

        $this->assertSame([$carId], $result->updated, 'A no-op sync must still report the car as updated (success)');
        $this->assertTrue($result->isCompleteSuccess());

        $mtimeAfterSync = (string) $this->db->query("SELECT mtime FROM cars WHERE id = ?", [$carId])->first()->mtime;
        if ($mtimeAfterSync !== $mtimeBeforeSync) {
            // Lost the second-boundary race: mtime really changed.
            $this->markTestSkipped('mtime crossed a second boundary between stamping and sync; the UPDATE was not a true no-op this run.');
        }
        $this->assertSame(0, $this->countOwnerSyncHistoryRows($carId), 'A true no-op sync (all ten written columns unchanged) must write no OWNER_SYNC history row');

        $this->assertSame(
            1,
            $this->countTriggerUpdateHistoryRows($carId) - $triggerRowsBefore,
            'The cars_update trigger fires on a matched row even when no value changed, so a no-op sync still adds exactly one operation=UPDATE row'
        );
    }

    /** Fields match but mtime is older (the usual production case), so a row is written. */
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
     * #1954: updated, skipped, and failed from one loop. failedCarsPhrase() must
     * name only the failed car.
     */
    public function testSingleSyncSortsUpdatedSkippedAndFailedIndependently(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, ['lat' => 45.5231, 'lon' => -122.6765]);

        $carIdUpdated = $this->createTestCar($userId, ['city' => 'UpdatedCity', 'lat' => null, 'lon' => null]);
        $carIdSkipped = $this->createTestCar($userId, ['city' => 'SkippedCity', 'lat' => null, 'lon' => null]);
        $carIdFailed  = $this->createTestCar($userId, ['city' => 'FailedCity',  'lat' => null, 'lon' => null]);

        $carRowUpdated = $this->db->query("SELECT * FROM cars WHERE id = ?", [$carIdUpdated])->first();
        $carRowSkipped = $this->db->query("SELECT * FROM cars WHERE id = ?", [$carIdSkipped])->first();
        $carRowFailed  = $this->db->query("SELECT * FROM cars WHERE id = ?", [$carIdFailed])->first();

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

        // Exact phrase: auto-increment IDs can be substrings of each other.
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

        $updatedRow = $this->db->query("SELECT city FROM cars WHERE id = ?", [$carIdUpdated])->first();
        $this->assertSame('NewCity', $updatedRow->city, 'The updated car must hold the owner\'s new values');

        $failedRow = $this->db->query("SELECT city FROM cars WHERE id = ?", [$carIdFailed])->first();
        $this->assertSame('FailedCity', $failedRow->city, 'The failed car\'s transaction must have rolled back to its original values');

        $skippedRow = $this->db->query("SELECT city FROM cars WHERE id = ?", [$carIdSkipped])->first();
        $this->assertSame('SkippedCity', $skippedRow->city, 'The skipped car must not receive the previous owner\'s values');
    }

    /** Loaded owner with zero cars returns success, not an exception. */
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

    /** A history insert failure rolls back the UPDATE (#1873 per-car transaction). */
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

        $car = $this->db->query("SELECT city, lat FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame('OriginalCity', $car->city, 'The car UPDATE must be rolled back when the history insert fails');
        $this->assertNull($car->lat, 'The car UPDATE must be rolled back when the history insert fails');

        $this->assertSame(0, $this->countOwnerSyncHistoryRows($carId), 'No OWNER_SYNC history row must exist when its own insert fails and the transaction rolls back');

        $after = $this->countMatchingLogs('OwnerActions', $logPattern);
        $this->assertSame($before + 1, $after, 'The history-insert failure must be logged under LOG_CATEGORY_OWNER_ACTIONS');
    }

    /**
     * A car transferred after the snapshot is not overwritten, is reported as
     * skipped (not failed), and is logged.
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

        $car = $this->db->query("SELECT city, lat FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame('OriginalCity', $car->city, 'A car no longer owned by this user must not be overwritten');
        $this->assertNull($car->lat, 'A car no longer owned by this user must not be overwritten');

        $this->assertSame(0, $this->countOwnerSyncHistoryRows($carId), 'No OWNER_SYNC history row must be written for a car that left this owner');

        $after = $this->countMatchingLogs('OwnerActions', $logPattern);
        $this->assertSame($before + 1, $after, 'Losing ownership mid-sync must be logged under LOG_CATEGORY_OWNER_ACTIONS');
    }

    /**
     * A real UPDATE failure is an infrastructure fault: it propagates, it is not
     * reported per car in `failed`.
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

        $car = $this->db->query("SELECT city, lat FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame('OriginalCity', $car->city, 'Car city must remain unchanged when the UPDATE query fails');
        $this->assertNull($car->lat, 'Car lat must remain unchanged when the UPDATE query fails');
    }

    /**
     * #1873: CarRepository transactions are no-ops inside an outer transaction,
     * so without this guard a per-car rollback would do nothing.
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
            // Always roll back: a failed assertion must not leave the shared connection
            // inside a transaction.
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
     * #1873: the log row is written after the rollback (InnoDB would discard it
     * otherwise). The failure hits the second car, so the log must name the
     * committed car and the aborted car.
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

        // Both cars share model/year, so insertion order breaks the tie. Asserted, not assumed.
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
     * A deleted user ID makes find() return false. That must throw, not look like
     * "owner has zero cars". Created then deleted so find() really runs.
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

    /** Fails cars_hist inserts (only $failingCarId when given) after a real UPDATE. */
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

    /** Fails updateCarForOwner()'s UPDATE (only $targetCarId when given). */
    private function dbFailingOwnerScopedUpdate(?int $targetCarId = null): DatabaseInterface
    {
        return new class ($this->db, $targetCarId) extends PassThroughDatabase {
            public function __construct(DatabaseInterface $real, private ?int $targetCarId)
            {
                parent::__construct($real);
            }

            public function query(string $sql, array $params = []): static
            {
                // updateCarForOwner() binds the car id second-to-last, before user_id.
                $fails = str_starts_with($sql, 'UPDATE cars SET')
                    && str_ends_with($sql, 'WHERE id = ? AND user_id = ?')
                    && ($this->targetCarId === null || (int) ($params[count($params) - 2] ?? 0) === $this->targetCarId);

                return $fails ? $this->simulateFailure('simulated deadlock') : parent::query($sql, $params);
            }
        };
    }

    /**
     * Adds $staleCarId, already reassigned in the real DB, to getCarsOwned()'s
     * results. Owner caches the list, so the SELECT runs once per sync.
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
                    && (int) ($params[0] ?? 0) === $this->ownerId;

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
