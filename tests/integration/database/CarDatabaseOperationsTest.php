<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Exceptions\CarCreationException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ImageProcessingException;
use ElanRegistry\Input;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for Car database operations
 *
 * Tests actual database operations with real connections, transactions,
 * triggers, and audit trails. These tests verify that the database layer
 * works correctly with the Car class.
 *
 * Uses fixtures created via IntegrationTestCase::createTestUser()/createTestCar().
 */
#[Group('integration')]
final class CarDatabaseOperationsTest extends IntegrationTestCase
{
    private $testCarId;
    private $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->testUserId = $this->createTestUser();

        // Set up authenticated user context for tests
        $this->loginAsTestUser($this->testUserId);

        // Create unique test car for this test
        try {
            $this->testCarId = $this->createTestCar($this->testUserId, [
                'chassis' => 'DB' . uniqid()
            ]);
        } catch (RuntimeException $e) {
            $this->markTestSkipped('Could not create test car: ' . $e->getMessage());
        }
    }

    /**
     * Test car creation persists to database
     */
    #[Group('integration')]
    public function testCarCreationPersistsToDatabases(): void
    {
        $carData = [
            'token' => Token::generate(),
            'user_id' => $this->testUserId,
            'year' => '1973',
            'model' => 'Sprint|FHC|36',  // Updated to new pipe-delimited format
            'series' => 'Sprint',
            'variant' => 'FHC',
            'type' => '36',
            'chassis' => 'TST' . substr(uniqid(), -9),  // Keep within 15 char limit
            'color' => 'Integration Red',
            'engine' => 'ENG' . substr(uniqid(), -9)  // Keep within 15 char limit
        ];

        $car = new Car();
        $result = $car->create($carData);

        $this->assertTrue($result);
        $createdId = $car->data()->id;
        $this->trackCarId((int) $createdId);

        // Verify data was persisted
        $query = $this->db->query('SELECT * FROM cars WHERE id = ?', [$createdId]);
        $this->assertGreaterThan(0, $query->count());

        $persistedCar = $query->first();
        $this->assertEquals('Integration Red', $persistedCar->color);
        $this->assertEquals($carData['chassis'], $persistedCar->chassis);
    }

    /**
     * Test car update persists to database
     */
    #[Group('integration')]
    public function testCarUpdatePersiststoDatabase(): void
    {
        $car = new Car($this->testCarId);
        $originalYear = $car->data()->year;

        $updateData = [
            'id' => $this->testCarId,
            'token' => Token::generate(),
            'year' => '1973',
            'color' => 'Integration Blue'
        ];

        $result = $car->update($updateData);

        $this->assertTrue($result);

        // Verify data was persisted
        $query = $this->db->query('SELECT * FROM cars WHERE id = ?', [$this->testCarId]);
        $updatedCar = $query->first();

        $this->assertEquals('1973', $updatedCar->year);
        $this->assertEquals('Integration Blue', $updatedCar->color);
    }

    /**
     * Test car deletion removes from database
     */
    #[Group('integration')]
    public function testCarDeletionRemovesFromDatabase(): void
    {
        $car = new Car($this->testCarId);
        $result = $car->delete('Integration test deletion', $this->testUserId);

        $this->assertTrue($result);

        // Verify car no longer exists in database
        $query = $this->db->query('SELECT * FROM cars WHERE id = ?', [$this->testCarId]);
        $this->assertEquals(0, $query->count());
    }

    /**
     * Test car deletion creates audit trail
     */
    #[Group('integration')]
    public function testCarDeletionCreatesAuditTrail(): void
    {
        $car = new Car($this->testCarId);
        $result = $car->delete('Integration test audit trail', $this->testUserId);

        $this->assertTrue($result);

        // Verify audit trail record exists
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'DELETE'",
            [$this->testCarId]
        );
        $this->assertGreaterThan(0, $historyQuery->count());
    }

    /**
     * Test car transfer updates relationships
     */
    #[Group('integration')]
    public function testCarTransferUpdatesRelationships(): void
    {
        $car = new Car($this->testCarId);
        $targetUserId = $this->createTestUser();

        // Verify original owner
        $origRelation = $this->db->query(
            'SELECT user_id FROM cars WHERE id = ?',
            [$this->testCarId]
        )->first();
        $this->assertSame($this->testUserId, (int) $origRelation->user_id);

        // Transfer car — return type is literal `true` (throws on any failure), so no
        // assertion is needed on the return value itself; the DB checks below are what matter.
        $car->transfer($targetUserId, 'Integration test transfer', 'NEWOWNER', $this->testUserId);

        // Verify owner was updated
        $newRelation = $this->db->query(
            'SELECT user_id FROM cars WHERE id = ?',
            [$this->testCarId]
        )->first();
        $this->assertSame($targetUserId, (int) $newRelation->user_id);
    }

    /**
     * Test car transfer creates history record
     */
    #[Group('integration')]
    public function testCarTransferCreatesHistoryRecord(): void
    {
        $car = new Car($this->testCarId);
        $targetUserId = $this->createTestUser();

        // Return type is literal `true` (throws on any failure); the history check below is the real assertion.
        $car->transfer($targetUserId, 'Integration test transfer history', 'NEWOWNER', $this->testUserId);

        // Verify history record exists
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'NEWOWNER'",
            [$this->testCarId]
        );
        $this->assertGreaterThan(0, $historyQuery->count());
    }

    /**
     * Test car merge transfers history records
     */
    #[Group('integration')]
    public function testCarMergeTransfersHistoryRecords(): void
    {
        // Create a second test car to merge from
        $mergeCarId = $this->createTestCar($this->testUserId, [
            'chassis' => 'DB' . uniqid()
        ]);

        $car = new Car($this->testCarId);
        $result = $car->merge($mergeCarId, 'Integration test merge', $this->testUserId);

        $this->assertTrue($result);

        // Verify merge audit trail exists
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'MERGE'",
            [$this->testCarId]
        );
        $this->assertGreaterThan(0, $historyQuery->count());
    }

    /**
     * Test database trigger creates update history on car update
     */
    #[Group('integration')]
    public function testDatabaseTriggerCreatesUpdateHistory(): void
    {
        $car = new Car($this->testCarId);
        $originalColor = $car->data()->color;

        // Count existing history entries
        $beforeQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'UPDATE'",
            [$this->testCarId]
        );
        $beforeCount = $beforeQuery->count();

        // Update car
        $updateData = [
            'id' => $this->testCarId,
            'token' => Token::generate(),
            'color' => 'Trigger Test Color'
        ];

        $result = $car->update($updateData);

        $this->assertTrue($result);

        // Verify trigger created history entry
        $afterQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'UPDATE'",
            [$this->testCarId]
        );
        $afterCount = $afterQuery->count();

        // Should have created new history record
        $this->assertGreaterThan($beforeCount, $afterCount);
    }

    /**
     * Test car ownership integrity
     */
    #[Group('integration')]
    public function testCarOwnershipIntegrity(): void
    {
        // Verify car has a valid owner assigned
        $row = $this->db->query(
            'SELECT id, user_id FROM cars WHERE id = ?',
            [$this->testCarId]
        )->first();

        $this->assertNotNull($row);
        $this->assertEquals($this->testUserId, $row->user_id);
    }

    /**
     * Test verification code is set and retrieved
     */
    #[Group('integration')]
    public function testVerificationCodeSetAndRetrieved(): void
    {
        $car = new Car($this->testCarId);
        $verificationCode = 'INT-VERIFY-' . uniqid();

        $result = $car->setVerificationCode($verificationCode);

        $this->assertTrue($result);

        // Retrieve from database
        // Note: Database column is 'vericode', not 'verification_code'.
        // The column stores hashVericode($verificationCode), not the plaintext
        // (issue #1928) — the plaintext round-trip is exercised by
        // CarVerificationTest::testFindByVerificationCodeSuccess().
        $query = $this->db->query(
            'SELECT vericode FROM cars WHERE id = ?',
            [$this->testCarId]
        );
        $result = $query->first();

        $this->assertEquals(hashVericode($verificationCode), $result->vericode);
        $this->assertNotEquals($verificationCode, $result->vericode);
    }

    /**
     * Test mark sold updates database
     */
    #[Group('integration')]
    public function testMarkSoldUpdatesDatabase(): void
    {
        // Create a test car for sold marking
        $carData = [
            'token' => Token::generate(),
            'user_id' => $this->testUserId,
            'year' => '1968',
            'model' => 'S4|FHC|36',  // Updated to new pipe-delimited format
            'series' => 'S4',
            'variant' => 'FHC',
            'type' => '36',
            'chassis' => 'SLD' . substr(uniqid(), -9),  // Keep within 15 char limit
            'color' => 'Red'
        ];

        $car = new Car();
        $car->create($carData);
        $carId = $car->data()->id;
        $this->trackCarId((int) $carId);

        // Mark as sold
        $soldDate = '2023-06-15';
        $result = $car->markSold($soldDate);

        $this->assertTrue($result);

        // Verify in database
        $query = $this->db->query('SELECT solddate FROM cars WHERE id = ?', [$carId]);
        $carData = $query->first();

        $this->assertStringStartsWith($soldDate, $carData->solddate);
    }

    /**
     * Test concurrent car operations maintain integrity
     */
    #[Group('integration')]
    public function testConcurrentCarOperationsMaintainIntegrity(): void
    {
        // Load same car twice
        $car1 = new Car($this->testCarId);
        $car2 = new Car($this->testCarId);

        // Both should have same data
        $this->assertEquals($car1->data()->id, $car2->data()->id);
        $this->assertEquals($car1->data()->chassis, $car2->data()->chassis);

        // Update with one instance
        $updateData = [
            'id' => $this->testCarId,
            'token' => Token::generate(),
            'color' => 'Concurrent Test'
        ];

        $result = $car1->update($updateData);

        $this->assertTrue($result);

        // Reload second instance
        $car2->find($this->testCarId);

        // Should reflect update
        $this->assertEquals('Concurrent Test', $car2->data()->color);
    }

    /**
     * Car text field updates must store plain text, not HTML-encoded values.
     *
     * Simulates the full action layer: Input::raw() retrieves the POST value,
     * the Car class stores it, and the DB row contains plain text — no entities.
     */
    #[Group('integration')]
    public function testCarUpdateStoresTextFieldsAsPlainText(): void
    {
        $specialChars = "O'Brien & Co <élan>";

        // Simulate what edit.php does: get raw POST values → update Car
        $_POST['color']    = $specialChars;
        $_POST['comments'] = $specialChars;
        try {
            $storedColor    = Input::raw('color');
            $storedComments = Input::raw('comments');

            $car = new Car($this->testCarId);
            $car->update([
                'id'       => $this->testCarId,
                'token'    => Token::generate(),
                'color'    => $storedColor,
                'comments' => $storedComments,
            ]);

            // Read directly from DB to verify no entity encoding in either field
            // (color and comments go through separate update functions in edit.php)
            $query = $this->db->query(
                'SELECT color, comments FROM cars WHERE id = ?',
                [$this->testCarId]
            );
            $row = $query->first();

            foreach (['color' => $row->color, 'comments' => $row->comments] as $col => $dbValue) {
                $this->assertSame(
                    $specialChars,
                    $dbValue,
                    "DB column '{$col}' must store plain text, not HTML-encoded value"
                );
                $this->assertStringNotContainsString('&amp;',  $dbValue, "DB column '{$col}' must not contain &amp;");
                $this->assertStringNotContainsString('&#039;', $dbValue, "DB column '{$col}' must not contain &#039;");
                $this->assertStringNotContainsString('&lt;',   $dbValue, "DB column '{$col}' must not contain &lt;");
            }
        } finally {
            unset($_POST['color'], $_POST['comments']);
        }
    }

    /**
     * Saving a car text field twice must not accumulate HTML encoding.
     *
     * Verifies the idempotency invariant: read from DB → save again → DB value unchanged.
     * This is the core regression for the double-encoding defect.
     */
    #[Group('integration')]
    public function testCarUpdateIsIdempotentOnResave(): void
    {
        $specialChars = "O'Brien & Co";

        // First save
        $car = new Car($this->testCarId);
        $car->update([
            'id'    => $this->testCarId,
            'token' => Token::generate(),
            'color' => $specialChars,
        ]);

        // Read back from DB
        $query1   = $this->db->query('SELECT color FROM cars WHERE id = ?', [$this->testCarId]);
        $afterFirstSave = $query1->first()->color;

        // Second save (re-save with the DB-read value — simulates edit-without-change)
        $car->update([
            'id'    => $this->testCarId,
            'token' => Token::generate(),
            'color' => $afterFirstSave,
        ]);

        $query2   = $this->db->query('SELECT color FROM cars WHERE id = ?', [$this->testCarId]);
        $afterSecondSave = $query2->first()->color;

        $this->assertSame(
            $afterFirstSave,
            $afterSecondSave,
            'DB value must be identical after a second save — no encoding accumulation'
        );
        $this->assertSame(
            $specialChars,
            $afterSecondSave,
            'DB must contain the original plain-text value after two saves'
        );
    }

    /**
     * Test car creation logs exactly one CarActions entry for the owner
     */
    #[Group('integration')]
    public function testCreateCarLogsCreationToCarActionsCategory(): void
    {
        $carData = [
            'token' => Token::generate(),
            'user_id' => $this->testUserId,
            'year' => '1971',
            'model' => 'Sprint|FHC|36',
            'series' => 'Sprint',
            'variant' => 'FHC',
            'type' => '36',
            'chassis' => 'LOG' . substr(uniqid(), -9),
            'color' => 'Log Test Yellow',
        ];

        $car = new Car();
        $result = $car->create($carData);
        $this->assertTrue($result);
        $carId = (int) $car->data()->id;
        $this->trackCarId($carId);

        $lognotePattern = "Car ID {$carId} created%";

        $count = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_CAR_ACTIONS, $lognotePattern);
        $this->assertSame(1, $count, 'Car::create() must log exactly one CarActions entry for this car');

        $logRow = $this->db->query(
            'SELECT user_id FROM logs WHERE logtype = ? AND lognote LIKE ? LIMIT 1',
            [LogCategories::LOG_CATEGORY_CAR_ACTIONS, $lognotePattern]
        )->first();
        $this->assertSame($this->testUserId, (int) $logRow->user_id, 'Log entry must be attributed to the owner');
    }

    /**
     * Test create() leaves the Car instance holding the persisted record's data
     *
     * create() re-reads the row via find() after insert, so data() is the round-tripped
     * database record — asserting every submitted business field on it verifies the values
     * were actually stored, not merely that a row appeared.
     */
    #[Group('integration')]
    public function testCreateCarReturnsPersistedCarDetails(): void
    {
        $carData = [
            'token' => Token::generate(),
            'user_id' => $this->testUserId,
            'year' => '1969',
            'model' => 'S4|FHC|36',
            'series' => 'S4',
            'variant' => 'FHC',
            'type' => '36',
            'chassis' => 'RTN' . substr(uniqid(), -9),  // Keep within 15 char limit
            'color' => 'Persisted Green',
            'engine' => 'ENG' . substr(uniqid(), -9),   // Keep within 15 char limit
        ];

        $car = new Car();
        $result = $car->create($carData);

        $this->assertTrue($result);

        $created = $car->data();
        $this->trackCarId((int) $created->id);

        $this->assertGreaterThan(0, (int) $created->id, 'Created car must have a database ID');
        $this->assertSame($this->testUserId, (int) $created->user_id, 'Created car must belong to the submitting owner');
        $this->assertSame((int) $carData['year'], (int) $created->year);
        $this->assertSame($carData['model'], $created->model);
        $this->assertSame($carData['series'], $created->series);
        $this->assertSame($carData['variant'], $created->variant);
        $this->assertSame($carData['type'], $created->type);
        $this->assertSame($carData['chassis'], $created->chassis);
        $this->assertSame($carData['color'], $created->color);
        $this->assertSame($carData['engine'], $created->engine);
    }

    /**
     * Test create() with an empty fields array throws CarCreationException
     */
    #[Group('integration')]
    public function testCreateCarFailsWithEmptyFields(): void
    {
        $this->expectException(CarCreationException::class);
        $this->expectExceptionMessage('No data provided for car creation');

        (new Car())->create([]);
    }

    /**
     * Regression test for issue #1519: CSRF validation moved out of Car::create()
     * to the HTTP layer (app/api/cars/save.php). Car::create() now silently
     * strips a 'token' key via unset() rather than validating it — an invalid
     * or garbage token value must no longer cause a failure.
     */
    #[Group('integration')]
    public function testCreateCarIgnoresInvalidTokenField(): void
    {
        $carData = [
            'token'   => 'not-a-valid-token',
            'user_id' => $this->testUserId,
            'year'    => '1971',
            'model'   => 'Sprint|FHC|36',
            'chassis' => 'CSRF' . substr(uniqid(), -8),
        ];

        $car = new Car();
        $result = $car->create($carData);

        $this->assertTrue($result, 'Car::create() must succeed regardless of the token field value');

        $createdId = (int) $car->data()->id;
        $this->trackCarId($createdId);

        $row = $this->db->query('SELECT * FROM cars WHERE id = ?', [$createdId])->first();
        $this->assertNotNull($row, 'Car must be persisted to the database');
        $this->assertSame($carData['chassis'], $row->chassis);
    }

    /**
     * Test create() with images that fail JSON encoding throws ImageProcessingException
     */
    #[Group('integration')]
    public function testCreateCarFailsWithImageEncodingError(): void
    {
        $this->expectException(ImageProcessingException::class);
        $this->expectExceptionMessage('Error processing car images: Failed to encode images as JSON');

        (new Car())->create([
            'token'   => Token::generate(),
            'user_id' => $this->testUserId,
            'year'    => '1971',
            'model'   => 'Sprint|FHC|36',
            'chassis' => 'IMG' . substr(uniqid(), -9),
            // Malformed UTF-8 makes json_encode() return false in CarImageProcessor::encodeImages().
            'images'  => ["\xB1\x31"],
        ]);
    }

    /**
     * Regression test for issue #1519: CSRF validation moved out of Car::update()
     * to the HTTP layer (app/api/cars/save.php). Car::update() now silently
     * strips a 'token' key via unset() rather than validating it — an invalid
     * or garbage token value must no longer prevent the update from persisting.
     */
    #[Group('integration')]
    public function testUpdateCarIgnoresInvalidTokenField(): void
    {
        $result = (new Car($this->testCarId))->update([
            'id'    => $this->testCarId,
            'token' => 'not-a-valid-token',
            'color' => 'Should Persist',
        ]);

        $this->assertTrue($result, 'Car::update() must succeed regardless of the token field value');

        $row = $this->db->query('SELECT color FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertSame('Should Persist', $row->color, 'Update must reach the database even with an invalid token field');
    }

    /**
     * Test update with an invalid car ID throws CarValidationException and does not persist
     */
    #[Group('integration')]
    public function testUpdateCarFailsWithInvalidId(): void
    {
        try {
            (new Car($this->testCarId))->update([
                'id'    => 0,
                'token' => Token::generate(),
                'color' => 'Should Not Persist',
            ]);
            $this->fail('Expected CarValidationException for an invalid car ID');
        } catch (CarValidationException $e) {
            $this->assertSame('Invalid car ID provided for update', $e->getMessage());
        }

        $row = $this->db->query('SELECT color FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertNotSame('Should Not Persist', $row->color, 'Rejected update must not reach the database');
    }

    /**
     * Test update() with an empty fields array throws CarValidationException
     */
    #[Group('integration')]
    public function testUpdateCarFailsWithEmptyFields(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('No data or ID provided for car update');

        (new Car($this->testCarId))->update([]);
    }

    /**
     * Test update() with fields but no id key throws CarValidationException
     */
    #[Group('integration')]
    public function testUpdateCarFailsWithMissingId(): void
    {
        $this->expectException(CarValidationException::class);
        $this->expectExceptionMessage('No data or ID provided for car update');

        (new Car($this->testCarId))->update([
            'token' => Token::generate(),
            'color' => 'Should Not Persist',
        ]);
    }

    /**
     * Test update with only id+token (no other fields) succeeds and leaves other fields unchanged.
     *
     * Doesn't assert mtime: Car::update() always stamps mtime, and array_filter() never
     * strips it, so mtime is what makes this "no-op" update reach the DB as a non-empty
     * SET at all — but mtime has only second-level precision, and a fast test's
     * create+update can land in the same second, so asserting it changed would be flaky.
     */
    #[Group('integration')]
    public function testUpdateCarWithNoChangesSucceeds(): void
    {
        $car = new Car($this->testCarId);
        $originalColor = $car->data()->color;
        $originalYear = $car->data()->year;

        $result = $car->update([
            'id'    => $this->testCarId,
            'token' => Token::generate(),
        ]);

        $this->assertTrue($result, 'Car::update() with only id+token must still succeed');

        $row = $this->db->query('SELECT color, year FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertEquals($originalColor, $row->color, 'color must be unchanged when no fields are updated');
        $this->assertEquals($originalYear, $row->year, 'year must be unchanged when no fields are updated');
    }

    /**
     * Test purchasedate persists through update()
     */
    #[Group('integration')]
    public function testUpdateCarPersistsPurchaseDate(): void
    {
        $car = new Car($this->testCarId);
        $purchaseDate = '2020-05-15';

        $result = $car->update([
            'id'           => $this->testCarId,
            'token'        => Token::generate(),
            'purchasedate' => $purchaseDate,
        ]);

        $this->assertTrue($result);

        $row = $this->db->query('SELECT purchasedate FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertStringStartsWith($purchaseDate, $row->purchasedate);
    }

    /**
     * Test factory() returns matching elan_factory_info data when chassis matches
     */
    #[Group('integration')]
    public function testFactoryReturnsFactoryDataWhenChassisMatches(): void
    {
        $chassis = 'F' . substr(uniqid(), -4); // 5 chars, matches elan_factory_info.serial varchar(5)
        $carId = $this->createTestCar($this->testUserId, ['chassis' => $chassis]);

        $factoryRowId = null;
        try {
            $inserted = $this->db->insert('elan_factory_info', [
                'year'         => '1971',
                'month'        => '03',
                'batch'        => '002',
                'type'         => '',
                'serial'       => $chassis,
                'suffix'       => 'a',
                'engineletter' => '',
                'enginenumber' => '',
                'gearbox'      => '',
                'color'        => '',
                'builddate'    => '1971-03-01',
                'note'         => '',
            ]);
            $this->assertTrue($inserted, 'Could not insert test factory row: ' . $this->db->errorString());
            $factoryRowId = (int) $this->db->lastId();

            $car = new Car($carId);
            $factory = $car->factory();

            $this->assertNotNull($factory, 'Expected factory() to return matching elan_factory_info row');
            $this->assertSame($chassis, $factory->serial);
            $this->assertSame('a (S4 FHC UK Market)', $factory->suffix, 'suffix must have descriptive text appended');
        } finally {
            if ($factoryRowId !== null) {
                $this->db->delete('elan_factory_info', ['id', '=', $factoryRowId]);
            }
        }
    }

    /**
     * Test factory() returns null when no elan_factory_info row matches the chassis
     */
    #[Group('integration')]
    public function testFactoryReturnsNullWhenNoChassisMatch(): void
    {
        // Fixed 'ZZZZZ' tail — CarRepository::getFactoryInfo() falls back to the last
        // 5 chassis chars, and real elan_factory_info serials are all-numeric (e.g.
        // '26060'), so a deterministic non-numeric tail can never collide there.
        $chassis = 'NOFAC' . substr(uniqid(), 0, 5) . 'ZZZZZ';
        $carId = $this->createTestCar($this->testUserId, ['chassis' => $chassis]);

        // A decoy row with a different serial proves the lookup actually discriminates,
        // rather than the assertion passing merely because the table is empty.
        $decoyRowId = null;
        try {
            $decoyInserted = $this->db->insert('elan_factory_info', [
                'year' => '1972', 'month' => '01', 'batch' => '001', 'type' => '',
                'serial' => 'DECOY', 'suffix' => '', 'engineletter' => '', 'enginenumber' => '',
                'gearbox' => '', 'color' => '', 'builddate' => '1972-01-01', 'note' => '',
            ]);
            $this->assertTrue($decoyInserted, 'Could not insert decoy factory row: ' . $this->db->errorString());
            $decoyRowId = (int) $this->db->lastId();

            $car = new Car($carId);
            $this->assertNull($car->factory());
        } finally {
            if ($decoyRowId !== null) {
                $this->db->delete('elan_factory_info', ['id', '=', $decoyRowId]);
            }
        }
    }

    /**
     * Test removeImage() throws CarNotFoundException when the car doesn't exist
     */
    #[Group('integration')]
    public function testRemoveImageFailsWhenCarNotExists(): void
    {
        $this->expectException(CarNotFoundException::class);

        $car = new Car(999999999); // nonexistent id — find() fails, exists() is false
        $car->removeImage('somefile.jpg');
    }

    /**
     * Data provider for the six CLEARABLE_FIELDS (issue #1448): each maps a field
     * name to a real starting value and the "clear" value sent on update (empty
     * string, matching how app/api/cars/save.php's null-passthrough reaches here
     * after CarValidator normalizes '' to null).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function clearableFieldProvider(): array
    {
        return [
            'color'        => ['color', 'Racing Green'],
            'engine'       => ['engine', 'ENG12345'],
            'purchasedate' => ['purchasedate', '2020-05-15'],
            'solddate'     => ['solddate', '2022-06-20'],
            'website'      => ['website', 'https://example.com'],
            'comments'     => ['comments', 'Some comments about this car'],
        ];
    }

    /**
     * Regression test for issue #1448: Car::update() must allow explicitly
     * clearing any of the six CLEARABLE_FIELDS by sending '' — the field's DB
     * column must become NULL, not silently retain its previous value.
     *
     * Uses the real ElanRegistry\Car\Car class end-to-end (per #1440, no mock).
     */
    #[Group('integration')]
    #[DataProvider('clearableFieldProvider')]
    public function testCarUpdateClearsField(string $field, string $initialValue): void
    {
        // solddate requires purchasedate to already be set for a valid, sensible
        // starting state (car previously sold); other fields are independent.
        $seed = ['purchasedate' => '2019-01-01'];
        $carId = $this->createTestCar($this->testUserId, array_merge($seed, [$field => $initialValue]));

        // Sanity check: the field actually has the seeded value before clearing.
        $before = $this->db->query("SELECT {$field} FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($before->{$field}, "Precondition failed: {$field} must be non-null before clearing");

        $car = new Car($carId);
        $result = $car->update([
            'id'    => $carId,
            'token' => Token::generate(),
            $field  => '',
        ]);

        $this->assertTrue($result, "Car::update() clearing {$field} must succeed");

        $after = $this->db->query("SELECT {$field} FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNull($after->{$field}, "DB column '{$field}' must be NULL after clearing");
    }

    /**
     * Regression test for issue #1448: clearing solddate while purchasedate
     * remains set (the "car still owned again" case) must succeed without
     * tripping the solddate >= purchasedate cross-field validation check —
     * that check must be skipped whenever either side is null, not treated
     * as sold-before-purchased.
     */
    #[Group('integration')]
    public function testCarUpdateClearsSoldDateWhileKeepingPurchaseDate(): void
    {
        $purchaseDate = '2018-03-10';
        $soldDate = '2021-07-04';
        $carId = $this->createTestCar($this->testUserId, [
            'purchasedate' => $purchaseDate,
            'solddate'     => $soldDate,
        ]);

        $car = new Car($carId);
        $result = $car->update([
            'id'       => $carId,
            'token'    => Token::generate(),
            'solddate' => '',
        ]);

        $this->assertTrue($result, 'Clearing solddate while purchasedate remains set must not throw a cross-field validation error');

        $row = $this->db->query('SELECT purchasedate, solddate FROM cars WHERE id = ?', [$carId])->first();
        $this->assertNull($row->solddate, 'solddate must be NULL after clearing');
        $this->assertStringStartsWith($purchaseDate, $row->purchasedate, 'purchasedate must remain unchanged');
    }

    /**
     * Regression test for issue #1448: clearing both purchasedate and solddate
     * in the same update() call must succeed. The cross-field
     * solddate >= purchasedate check relies on isset() returning false for a
     * null value — this must hold when BOTH fields are null simultaneously,
     * not just when one is cleared while the other remains set (covered by
     * testCarUpdateClearsSoldDateWhileKeepingPurchaseDate above).
     */
    #[Group('integration')]
    public function testCarUpdateClearsBothPurchaseAndSoldDateSimultaneously(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'purchasedate' => '2018-03-10',
            'solddate'     => '2021-07-04',
        ]);

        $car = new Car($carId);
        $result = $car->update([
            'id'           => $carId,
            'token'        => Token::generate(),
            'purchasedate' => '',
            'solddate'     => '',
        ]);

        $this->assertTrue($result, 'Clearing both purchasedate and solddate together must not throw a cross-field validation error');

        $row = $this->db->query('SELECT purchasedate, solddate FROM cars WHERE id = ?', [$carId])->first();
        $this->assertNull($row->purchasedate, 'purchasedate must be NULL after clearing');
        $this->assertNull($row->solddate, 'solddate must be NULL after clearing');
    }

    /**
     * Regression test for issue #1448: Car::create() also runs the six
     * clearable fields through CarValidator, but unlike update(), create()
     * has no array_filter step at all — the validated fields go straight to
     * CarRepository::insertCar(). Submitting an explicitly empty clearable
     * field on creation must insert NULL (matching the column's own
     * DEFAULT NULL), not throw and not insert an empty string.
     */
    #[Group('integration')]
    public function testCarCreateWithEmptyClearableFieldInsertsNull(): void
    {
        $car = new Car();
        $result = $car->create([
            'token'   => Token::generate(),
            'user_id' => $this->testUserId,
            'year'    => '1971',
            'model'   => 'Sprint|FHC|36',
            'chassis' => 'CRT' . substr(uniqid(), -9),
            'color'   => '',
            'website' => '',
        ]);

        $this->assertTrue($result, 'Car::create() with empty clearable fields must succeed');

        $carId = (int) $car->data()->id;
        $this->trackCarId($carId);

        $row = $this->db->query('SELECT color, website FROM cars WHERE id = ?', [$carId])->first();
        $this->assertNull($row->color, 'color must be NULL, not an empty string, on create');
        $this->assertNull($row->website, 'website must be NULL, not an empty string, on create');
    }
}
