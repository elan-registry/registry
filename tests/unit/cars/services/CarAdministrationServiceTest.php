<?php

declare(strict_types=1);

use ElanRegistry\Car\CarAdministrationService;
use ElanRegistry\Car\CarImageRelocator;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarValidationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Uses the real CarRepository over a DatabaseInterface double: a mocked
 * repository would hide behavior such as CarNotFoundException on 0-row deletes.
 */
#[Group('fast')]
final class CarAdministrationServiceTest extends TestCase
{
    private CarAdministrationService $service;
    private CarRepository $repo;

    protected function setUp(): void
    {
        $this->service = new CarAdministrationService();
        $this->repo = new CarRepository($this->createStub(DatabaseInterface::class));
    }

    /**
     * Models inTransaction() state with a flag, not a fixed call sequence.
     * The callbacks return true because the interface methods return bool.
     */
    private function configureTransaction(MockObject $db, bool $expectCommit): void
    {
        $inTransaction = false;
        $db->method('inTransaction')->willReturnCallback(function () use (&$inTransaction): bool {
            return $inTransaction;
        });
        $db->expects($this->once())->method('beginTransaction')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                $inTransaction = true;
                return true;
            });
        $db->expects($expectCommit ? $this->once() : $this->never())->method('commit')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                $inTransaction = false;
                return true;
            });
        $db->expects($expectCommit ? $this->never() : $this->once())->method('rollBack')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                $inTransaction = false;
                return true;
            });
    }

    /**
     * @param bool $blankLocation No location or website, like `noowner`. The name stays
     *                            set: the real `noowner` is 'No'/'Owner'.
     * @param string $username 'noowner' selects the system-account solddate path
     */
    private function createOwnerDb(
        int $userId = 1,
        string $email = 'test@example.com',
        bool $blankLocation = false,
        string $username = 'testuser'
    ): DatabaseInterface {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn((object) [
            'id'        => $userId,
            'username'  => $username,
            'email'     => $email,
            'fname'     => 'Test',
            'lname'     => 'User',
            'join_date' => '2024-01-01 00:00:00',
            'city'      => $blankLocation ? '' : 'Test City',
            'state'     => $blankLocation ? '' : 'TS',
            'country'   => $blankLocation ? '' : 'US',
            'lat'       => $blankLocation ? null : 52.4567,
            'lon'       => $blankLocation ? null : 1.0234,
            'website'   => '',
        ]);
        return $db;
    }

    public function testDeleteSucceedsWithValidData(): void
    {
        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999'];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: true);
        $db->method('query')->willReturn($db);
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1); // deleteCar(): count()>0 -> true, no CarNotFoundException
        $repo = new CarRepository($db);

        $result = $this->service->delete($carData, 'Test deletion', 1, $repo);
        $this->assertTrue($result);
    }

    public function testMergeRejectsSelfMerge(): void
    {
        $this->expectException(CarValidationException::class);

        $carData = (object) [
            'id' => 1,
            'chassis' => 'TEST00001'
        ];

        $this->service->merge($carData, 1, 'Test merge', 1, $this->repo);
    }

    public function testTransferThrowsCarValidationExceptionWhenUserNotFound(): void
    {
        $this->expectException(CarValidationException::class);

        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999'];

        $this->service->transfer($carData, 0, 'Test transfer reason', 'NEWOWNER', 1, $this->repo, $this->createOwnerDb());
    }

    public function testTransferSucceeds(): void
    {
        // A separate owner double keeps the Owner lookup apart from $db's expectations.
        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999'];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: true);
        $db->method('update')->willReturn(true);  // CarRepository::updateCar() -> $this->db->update(...)
        $db->method('insert')->willReturn(true);  // CarRepository::insertHistory() -> $this->db->insert(...)
        $repo = new CarRepository($db);

        // Returns literal true; the transaction expectations are the assertion.
        $this->service->transfer($carData, 1, 'Test transfer reason', 'NEWOWNER', 1, $repo, $this->createOwnerDb());
    }

    /** A sale does not survive a change of owner (#1878). */
    public function testTransferClearsSoldDateOnCarsAndHistoryRow(): void
    {
        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999', 'solddate' => '2020-01-01'];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: true);

        $updateFields = null;
        $historyFields = null;
        $db->method('update')->willReturnCallback(
            function (string $table, array|int $id, array $fields) use (&$updateFields): bool {
                $updateFields = $fields;
                return true;
            }
        );
        $db->method('insert')->willReturnCallback(
            function (string $table, array $fields = [], bool $update = false) use (&$historyFields): bool {
                $historyFields = $fields;
                return true;
            }
        );
        $repo = new CarRepository($db);

        $this->service->transfer($carData, 1, 'Test transfer reason', 'NEWOWNER', 1, $repo, $this->createOwnerDb());

        $this->assertArrayHasKey('solddate', $updateFields, 'cars.solddate must be written on transfer, not omitted');
        $this->assertNull($updateFields['solddate'], 'cars.solddate must be cleared on an ordinary transfer');
        $this->assertArrayHasKey('solddate', $historyFields, 'history solddate must be written on transfer, not omitted');
        $this->assertNull($historyFields['solddate'], 'history solddate must be cleared on an ordinary transfer');
    }

    /**
     * The solddate check uses the username, not SYSTEM_ACCOUNT_EMAIL: a real
     * owner with that email is still a change of owner (#1878).
     */
    public function testTransferClearsSoldDateIsKeyedOnUsernameNotEmail(): void
    {
        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999', 'solddate' => '2020-01-01'];

        // Case 1: sentinel email, ordinary username.
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: true);
        $updateFields = null;
        $db->method('update')->willReturnCallback(
            function (string $table, array|int $id, array $fields) use (&$updateFields): bool {
                $updateFields = $fields;
                return true;
            }
        );
        $db->method('insert')->willReturn(true);
        $this->service->transfer(
            $carData,
            1,
            'Test transfer reason',
            'NEWOWNER',
            1,
            new CarRepository($db),
            $this->createOwnerDb(1, 'noowner@invalid')
        );
        $this->assertArrayHasKey('solddate', $updateFields, 'A real owner with the sentinel email is still a change of owner');
        $this->assertNull($updateFields['solddate']);

        // Case 2: target row carries no username key at all — must mean "real owner".
        $ownerDb = $this->createStub(DatabaseInterface::class);
        $ownerDb->method('query')->willReturnSelf();
        $ownerDb->method('error')->willReturn(false);
        $ownerDb->method('count')->willReturn(1);
        $ownerDb->method('first')->willReturn((object) [
            'id'    => 1,
            'email' => 'test@example.com',
            'fname' => 'Test',
            'lname' => 'User',
        ]);
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: true);
        $updateFields = null;
        $db->method('update')->willReturnCallback(
            function (string $table, array|int $id, array $fields) use (&$updateFields): bool {
                $updateFields = $fields;
                return true;
            }
        );
        $db->method('insert')->willReturn(true);
        $this->service->transfer($carData, 1, 'Test transfer reason', 'NEWOWNER', 1, new CarRepository($db), $ownerDb);
        $this->assertArrayHasKey('solddate', $updateFields, 'A target row without a username must be treated as a real owner');
        $this->assertNull($updateFields['solddate']);
    }

    /** Reassignment to `noowner` is not a change of owner, so solddate stays (#1878). */
    public function testTransferToSystemAccountPreservesSoldDate(): void
    {
        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999', 'solddate' => '2020-01-01'];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: true);

        $updateFields = null;
        $historyFields = null;
        $db->method('update')->willReturnCallback(
            function (string $table, array|int $id, array $fields) use (&$updateFields): bool {
                $updateFields = $fields;
                return true;
            }
        );
        $db->method('insert')->willReturnCallback(
            function (string $table, array $fields = [], bool $update = false) use (&$historyFields): bool {
                $historyFields = $fields;
                return true;
            }
        );
        $repo = new CarRepository($db);

        $this->service->transfer(
            $carData,
            1,
            'Account deleted — reassigned to noowner',
            'NEWOWNER',
            1,
            $repo,
            $this->createOwnerDb(1, 'noowner@invalid', username: 'noowner')
        );

        $this->assertArrayNotHasKey(
            'solddate',
            $updateFields,
            'cars.solddate must be left untouched when transferring to the noowner system account'
        );
        $this->assertSame(
            '2020-01-01',
            $historyFields['solddate'],
            "history solddate must pass through the car's existing value for a system-account transfer"
        );
    }

    /**
     * email_bounced describes the old address. Kept, it would exclude the car
     * from findVerificationEligible() for good.
     */
    public function testTransferClearsEmailBouncedOnCarsAndHistoryRow(): void
    {
        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999', 'email_bounced' => 1];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: true);

        $updateFields = null;
        $historyFields = null;
        $db->method('update')->willReturnCallback(
            function (string $table, array|int $id, array $fields) use (&$updateFields): bool {
                $updateFields = $fields;
                return true;
            }
        );
        $db->method('insert')->willReturnCallback(
            function (string $table, array $fields = [], bool $update = false) use (&$historyFields): bool {
                $historyFields = $fields;
                return true;
            }
        );
        $repo = new CarRepository($db);

        $this->service->transfer($carData, 1, 'Test transfer reason', 'NEWOWNER', 1, $repo, $this->createOwnerDb());

        $this->assertArrayHasKey('email_bounced', $updateFields, 'cars.email_bounced must be written on transfer, not omitted');
        $this->assertSame(0, $updateFields['email_bounced'], 'cars.email_bounced must be cleared on an ordinary transfer');
        $this->assertArrayHasKey('email_bounced', $historyFields, 'history email_bounced must be written on transfer, not omitted');
        $this->assertSame(0, $historyFields['email_bounced'], 'history email_bounced must be cleared on an ordinary transfer');
    }

    /** `noowner` is not a change of owner, so email_bounced stays (#1878). */
    public function testTransferToSystemAccountPreservesEmailBounced(): void
    {
        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999', 'email_bounced' => 1];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: true);

        $updateFields = null;
        $historyFields = null;
        $db->method('update')->willReturnCallback(
            function (string $table, array|int $id, array $fields) use (&$updateFields): bool {
                $updateFields = $fields;
                return true;
            }
        );
        $db->method('insert')->willReturnCallback(
            function (string $table, array $fields = [], bool $update = false) use (&$historyFields): bool {
                $historyFields = $fields;
                return true;
            }
        );
        $repo = new CarRepository($db);

        $this->service->transfer(
            $carData,
            1,
            'Account deleted — reassigned to noowner',
            'NEWOWNER',
            1,
            $repo,
            $this->createOwnerDb(1, 'noowner@invalid', username: 'noowner')
        );

        $this->assertArrayNotHasKey(
            'email_bounced',
            $updateFields,
            'cars.email_bounced must be left untouched when transferring to the noowner system account'
        );
        $this->assertSame(
            1,
            $historyFields['email_bounced'],
            "history email_bounced must pass through the car's existing value for a system-account transfer"
        );
    }

    /**
     * `noowner@invalid` fails FILTER_VALIDATE_EMAIL. It once rolled back the
     * transfer and broke GDPR account deletion, which reassigns cars here (#1679).
     */
    public function testTransferToUnroutableSystemAccountBlanksEmailInsteadOfFailing(): void
    {
        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999'];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: true);

        $updateFields = null;
        $historyFields = null;
        $db->method('update')->willReturnCallback(
            function (string $table, array|int $id, array $fields) use (&$updateFields): bool {
                $updateFields = $fields;
                return true;
            }
        );
        $db->method('insert')->willReturnCallback(
            function (string $table, array $fields = [], bool $update = false) use (&$historyFields): bool {
                $historyFields = $fields;
                return true;
            }
        );
        $repo = new CarRepository($db);

        $this->service->transfer(
            $carData,
            1,
            'Account deleted — reassigned to noowner',
            'NEWOWNER',
            1,
            $repo,
            $this->createOwnerDb(1, 'noowner@invalid')
        );

        $this->assertSame('', $updateFields['email'] ?? null, 'cars.email must be blanked, not set to the sentinel address');
        $this->assertSame('', $historyFields['email'] ?? null, 'history email must be blanked, not set to the sentinel address');
    }

    /**
     * This runs inside the deletion transaction, so it must not abort.
     * Unlike the sentinel case, contactableEmail() logs it.
     */
    public function testTransferBlanksMalformedEmailOnRealAccountWithoutFailing(): void
    {
        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999'];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: true);

        $updateFields = null;
        $historyFields = null;
        $db->method('update')->willReturnCallback(
            function (string $table, array|int $id, array $fields) use (&$updateFields): bool {
                $updateFields = $fields;
                return true;
            }
        );
        $db->method('insert')->willReturnCallback(
            function (string $table, array $fields = [], bool $update = false) use (&$historyFields): bool {
                $historyFields = $fields;
                return true;
            }
        );
        $repo = new CarRepository($db);

        $this->service->transfer(
            $carData,
            1,
            'Admin-initiated transfer',
            'NEWOWNER',
            1,
            $repo,
            $this->createOwnerDb(1, 'not-an-email-address')
        );

        $this->assertSame('', $updateFields['email'] ?? null, 'a malformed owner email must be blanked, never copied onto the car');
        $this->assertSame('', $historyFields['email'] ?? null, 'a malformed owner email must be blanked in history too');
    }

    /**
     * CarValidator drops empty keys, so without withBlankedFieldsRestored() the
     * previous owner's location and website stay on the car. That is PII the
     * GDPR deletion path must clear.
     */
    public function testTransferClearsAllOwnerIdentityFieldsWhenTargetHasNone(): void
    {
        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999'];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: true);

        $updateFields = null;
        $historyFields = null;
        $db->method('update')->willReturnCallback(
            function (string $table, array|int $id, array $fields) use (&$updateFields): bool {
                $updateFields = $fields;
                return true;
            }
        );
        $db->method('insert')->willReturnCallback(
            function (string $table, array $fields = [], bool $update = false) use (&$historyFields): bool {
                $historyFields = $fields;
                return true;
            }
        );
        $repo = new CarRepository($db);

        $this->service->transfer(
            $carData,
            1,
            'Account deleted — reassigned to noowner',
            'NEWOWNER',
            1,
            $repo,
            $this->createOwnerDb(1, 'noowner@invalid', blankLocation: true)
        );

        foreach (['email', 'city', 'state', 'country'] as $field) {
            $this->assertArrayHasKey(
                $field,
                $updateFields,
                "cars.{$field} must be written on transfer, not dropped by CarValidator"
            );
            $this->assertSame(
                '',
                $updateFields[$field],
                "cars.{$field} must be cleared so the previous owner's value cannot survive the transfer"
            );
        }

        // The name is overwritten by the target's own name, not blanked.
        $this->assertArrayHasKey('fname', $updateFields, 'cars.fname must be written on transfer, not dropped by CarValidator');
        $this->assertSame('Test', $updateFields['fname'], "cars.fname must be the target owner's own name, overwriting the previous owner's");
        $this->assertArrayHasKey('lname', $updateFields, 'cars.lname must be written on transfer, not dropped by CarValidator');
        $this->assertSame('User', $updateFields['lname'], "cars.lname must be the target owner's own name, overwriting the previous owner's");

        // website clears to null, not '' (#1448).
        $this->assertArrayHasKey(
            'website',
            $updateFields,
            'cars.website must be written on transfer, not dropped by CarValidator'
        );
        $this->assertNull(
            $updateFields['website'],
            "cars.website must be cleared so the previous owner's value cannot survive the transfer"
        );
        foreach (['lat', 'lon'] as $field) {
            $this->assertArrayHasKey(
                $field,
                $updateFields,
                "cars.{$field} must be written on transfer, not dropped by CarValidator"
            );
            $this->assertNull(
                $updateFields[$field],
                "cars.{$field} must be cleared so the previous owner's value cannot survive the transfer"
            );
        }

        foreach (['email', 'city', 'state', 'country', 'website'] as $field) {
            $this->assertArrayHasKey($field, $historyFields, "history {$field} must be written on transfer, not omitted");
            $this->assertSame('', $historyFields[$field] ?? null, "history {$field} must be cleared so the previous owner's value cannot survive the transfer");
        }
        $this->assertArrayHasKey('fname', $historyFields, 'history fname must be written on transfer, not omitted');
        $this->assertSame('Test', $historyFields['fname'] ?? null, "history fname must be the target owner's own name, overwriting the previous owner's");
        $this->assertArrayHasKey('lname', $historyFields, 'history lname must be written on transfer, not omitted');
        $this->assertSame('User', $historyFields['lname'] ?? null, "history lname must be the target owner's own name, overwriting the previous owner's");
        $this->assertArrayHasKey('lat', $historyFields, 'history lat must be written on transfer, not omitted');
        $this->assertNull($historyFields['lat'] ?? null, 'history lat must be cleared so the previous owner\'s value cannot survive the transfer');
        $this->assertArrayHasKey('lon', $historyFields, 'history lon must be written on transfer, not omitted');
        $this->assertNull($historyFields['lon'] ?? null, 'history lon must be cleared so the previous owner\'s value cannot survive the transfer');
    }

    public function testTransferThrowsCarDatabaseExceptionWhenUpdateFails(): void
    {
        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999'];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: false);
        $db->method('update')->willReturn(false); // updateCar() fails
        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->service->transfer($carData, 1, 'Test transfer reason', 'NEWOWNER', 1, $repo, $this->createOwnerDb());
    }

    public function testTransferThrowsCarDatabaseExceptionWhenInsertHistoryFails(): void
    {
        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999'];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: false);
        $db->method('update')->willReturn(true);  // updateCar() succeeds
        $db->method('insert')->willReturn(false); // insertHistory() fails
        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->service->transfer($carData, 1, 'Test transfer reason', 'NEWOWNER', 1, $repo, $this->createOwnerDb());
    }

    // delete() + merge() propagation (#1311)

    /** The service must not swallow CarException subclasses. */
    public function testDeletePropagatesCarNotFoundExceptionFromDeleteCar(): void
    {
        // error()=false, count()=0: deleteCar() throws CarNotFoundException.
        $carData = (object) ['id' => 999, 'chassis' => 'GHOST01'];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: false);
        $db->method('query')->willReturn($db); // query() returns the database object itself
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);
        $repo = new CarRepository($db);

        $this->expectException(CarNotFoundException::class);
        $this->service->delete($carData, 'Test deletion', 1, $repo);
    }

    public function testDeleteThrowsCarDatabaseExceptionWhenDeleteCarReturnsFalse(): void
    {
        // error()=true: deleteCar() returns false before it reads count().
        $carData = (object) ['id' => 999, 'chassis' => 'GHOST02'];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: false);
        $db->method('query')->willReturn($db); // query() returns the database object itself
        $db->method('error')->willReturn(true);
        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->service->delete($carData, 'Test deletion', 1, $repo);
    }

    /** The source car was deleted between the caller's check and the locked read. */
    public function testMergePropagatesCarNotFoundExceptionWhenSourceCarGone(): void
    {
        $targetCarData = (object) ['id' => 1, 'chassis' => 'TARGET01'];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: false);
        $db->method('query')->willReturn($db); // query() returns the database object itself
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);
        $repo = new CarRepository($db);

        $this->expectException(CarNotFoundException::class);
        $this->service->merge($targetCarData, 999, 'Test merge', 1, $repo);
    }

    public function testMergeThrowsCarDatabaseExceptionWhenTransferHistoryFails(): void
    {
        // error() order: lock old car, lock new car, transferHistory (fails).
        $targetCarData = (object) ['id' => 1, 'chassis' => 'TARGET01'];
        $sourceData = (object) ['id' => 999, 'chassis' => 'SOURCE01'];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: false);
        $db->method('query')->willReturn($db); // query() returns the database object itself
        $db->method('error')->willReturnOnConsecutiveCalls(false, false, true);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn($sourceData);
        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->service->merge($targetCarData, 999, 'Test merge', 1, $repo);
    }

    public function testMergeThrowsCarDatabaseExceptionWhenDeleteCarFails(): void
    {
        // error() order: lock old, lock new, transferHistory, deleteCar (fails).
        $targetCarData = (object) ['id' => 1, 'chassis' => 'TARGET01'];
        $sourceData = (object) ['id' => 999, 'chassis' => 'SOURCE01'];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: false);
        $db->method('query')->willReturn($db); // query() returns the database object itself
        $db->method('error')->willReturnOnConsecutiveCalls(false, false, false, true);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn($sourceData);
        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->service->merge($targetCarData, 999, 'Test merge', 1, $repo);
    }

    public function testMergeThrowsCarDatabaseExceptionWhenInsertHistoryFails(): void
    {
        $targetCarData = (object) ['id' => 1, 'chassis' => 'TARGET01'];
        $sourceData = (object) ['id' => 999, 'chassis' => 'SOURCE01'];
        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: false);
        $db->method('query')->willReturn($db); // query() returns the database object itself
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn($sourceData);
        $db->method('insert')->willReturn(false);
        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->service->merge($targetCarData, 999, 'Test merge', 1, $repo);
    }

    /** Records query() calls to prove the #1867 updateImage() CAS write happens. */
    public function testMergeSucceeds(): void
    {
        $targetCarData = (object) ['id' => 1, 'chassis' => 'TARGET01'];
        $sourceData = (object) ['id' => 999, 'chassis' => 'SOURCE01', 'image' => null];
        $lockedTargetData = (object) ['id' => 1, 'chassis' => 'TARGET01', 'image' => null];

        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: true);
        $recordedQueries = [];
        $db->method('query')->willReturnCallback(function (string $sql, array $params = []) use ($db, &$recordedQueries) {
            $recordedQueries[] = ['sql' => $sql, 'params' => $params];
            return $db;
        });
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturnCallback(function () use ($sourceData, $lockedTargetData) {
            static $call = 0;
            $call++;
            return $call === 1 ? $lockedTargetData : $sourceData;
        });
        $db->expects($this->once())->method('insert')->with('cars_hist', $this->anything())->willReturn(true);

        $repo = new CarRepository($db);
        $service = new CarAdministrationService();

        $service->merge($targetCarData, 999, 'Test merge', 1, $repo);

        $updateImageCalls = array_values(array_filter(
            $recordedQueries,
            fn (array $call): bool => str_contains($call['sql'], 'UPDATE cars SET image')
        ));
        $this->assertCount(
            1,
            $updateImageCalls,
            'merge() must call CarRepository::updateImage() to write the surviving car\'s image column'
        );
        $this->assertSame([1], array_slice($updateImageCalls[0]['params'], 1, 1), 'updateImage() must target the surviving car by id');
    }

    public function testMergeCallsRelocatorAndAppendsRenamedFilenamesAfterTargetsExisting(): void
    {
        $targetCarData = (object) ['id' => 1, 'chassis' => 'TARGET01'];
        $sourceData = (object) ['id' => 999, 'chassis' => 'SOURCE01', 'image' => '["src_a.jpg","src_b.jpg"]'];
        $lockedTargetData = (object) ['id' => 1, 'chassis' => 'TARGET01', 'image' => '["tgt_existing.jpg"]'];

        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: true);
        $db->method('query')->willReturn($db);
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturnCallback(function () use ($sourceData, $lockedTargetData) {
            static $call = 0;
            $call++;
            return $call === 1 ? $lockedTargetData : $sourceData;
        });
        $db->method('insert')->willReturn(true);

        $relocator = $this->createMock(CarImageRelocator::class);
        $relocator->expects($this->once())
            ->method('relocate')
            ->with(999, 1, ['src_a.jpg', 'src_b.jpg'])
            ->willReturn(['src_a.jpg' => 'src_a_renamed.jpg', 'src_b.jpg' => 'src_b.jpg']);
        $relocator->expects($this->never())->method('restore');

        $repo = new CarRepository($db);
        $service = new CarAdministrationService($relocator);

        $service->merge($targetCarData, 999, 'Test merge', 1, $repo);
    }

    /**
     * The first cars.image entry is the public thumbnail, so the order is
     * pinned: target images first, then the source's post-rename names.
     */
    public function testMergeWritesImageColumnWithTargetImagesFirstThenRenamedSourceImages(): void
    {
        $targetCarData = (object) ['id' => 1, 'chassis' => 'TARGET01'];
        $sourceData = (object) ['id' => 999, 'chassis' => 'SOURCE01', 'image' => '["src_a.jpg","src_b.jpg"]'];
        $lockedTargetData = (object) ['id' => 1, 'chassis' => 'TARGET01', 'image' => '["tgt_existing.jpg"]'];

        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: true);
        $recordedQueries = [];
        $db->method('query')->willReturnCallback(function (string $sql, array $params = []) use ($db, &$recordedQueries) {
            $recordedQueries[] = ['sql' => $sql, 'params' => $params];
            return $db;
        });
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturnCallback(function () use ($sourceData, $lockedTargetData) {
            static $call = 0;
            $call++;
            return $call === 1 ? $lockedTargetData : $sourceData;
        });
        $db->method('insert')->willReturn(true);

        $relocator = $this->createMock(CarImageRelocator::class);
        $relocator->method('relocate')
            ->with(999, 1, ['src_a.jpg', 'src_b.jpg'])
            // key != value on the first entry catches a swap of keys for values.
            ->willReturn(['src_a.jpg' => 'src_a_renamed.jpg', 'src_b.jpg' => 'src_b.jpg']);

        $repo = new CarRepository($db);
        $service = new CarAdministrationService($relocator);

        $service->merge($targetCarData, 999, 'Test merge', 1, $repo);

        $updateImageCalls = array_values(array_filter(
            $recordedQueries,
            fn (array $call): bool => str_contains($call['sql'], 'UPDATE cars SET image')
        ));
        $this->assertCount(1, $updateImageCalls, 'merge() must write cars.image exactly once');

        $writtenJson = $updateImageCalls[0]['params'][0];
        $this->assertSame(
            '["tgt_existing.jpg","src_a_renamed.jpg","src_b.jpg"]',
            $writtenJson,
            'cars.image must be written with the target\'s existing images first, '
            . 'then the source images under their POST-RENAME names, in that exact order'
        );
        $this->assertSame(
            ['tgt_existing.jpg', 'src_a_renamed.jpg', 'src_b.jpg'],
            json_decode($writtenJson, true),
            'decoded cars.image must preserve exact order — set equality is not sufficient'
        );
    }

    /**
     * A throw from inside commit() must not compensate: the server may have
     * committed, so moving files back would point them at a deleted car.
     * $committed was once set after commit(), so a throw skipped it.
     */
    public function testMergeDoesNotRestoreFilesWhenCommitItselfThrows(): void
    {
        $targetCarData = (object) ['id' => 1, 'chassis' => 'TARGET01'];
        $sourceData = (object) ['id' => 999, 'chassis' => 'SOURCE01', 'image' => '["src_a.jpg"]'];
        $lockedTargetData = (object) ['id' => 1, 'chassis' => 'TARGET01', 'image' => '["tgt_existing.jpg"]'];

        $inTransaction = false;
        $db = $this->createMock(DatabaseInterface::class);
        $db->method('inTransaction')->willReturnCallback(function () use (&$inTransaction): bool {
            return $inTransaction;
        });
        $db->expects($this->once())->method('beginTransaction')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                $inTransaction = true;
                return true;
            });
        $db->expects($this->once())->method('commit')
            ->willThrowException(new \PDOException('server has gone away during commit'));
        $db->method('query')->willReturn($db);
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturnCallback(function () use ($sourceData, $lockedTargetData) {
            static $call = 0;
            $call++;
            return $call === 1 ? $lockedTargetData : $sourceData;
        });
        $db->method('insert')->willReturn(true);

        $relocator = $this->createMock(CarImageRelocator::class);
        $relocator->method('relocate')->willReturn(['src_a.jpg' => 'src_a.jpg']);
        $relocator->expects($this->never())->method('restore');

        $repo = new CarRepository($db);
        $service = new CarAdministrationService($relocator);

        $threw = false;
        try {
            $service->merge($targetCarData, 999, 'Test merge', 1, $repo);
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'merge() must surface the commit failure');
    }

    /** A CAS miss after relocate() must run restore() before rollback. */
    public function testMergeThrowsAndRestoresWhenUpdateImageCasConflicts(): void
    {
        $targetCarData = (object) ['id' => 1, 'chassis' => 'TARGET01'];
        $sourceData = (object) ['id' => 999, 'chassis' => 'SOURCE01', 'image' => '["src_a.jpg"]'];
        $lockedTargetData = (object) ['id' => 1, 'chassis' => 'TARGET01', 'image' => null];

        $db = $this->createMock(DatabaseInterface::class);
        $this->configureTransaction($db, expectCommit: false);
        $db->method('query')->willReturn($db);
        $db->method('error')->willReturn(false);
        // count(): lock target, lock source, deleteCar, updateImage = 0 (CAS miss).
        $countValues = [1, 1, 1, 0];
        $db->method('count')->willReturnCallback(function () use (&$countValues) {
            return array_shift($countValues) ?? 0;
        });
        $db->method('first')->willReturnCallback(function () use ($sourceData, $lockedTargetData) {
            static $call = 0;
            $call++;
            return $call === 1 ? $lockedTargetData : $sourceData;
        });

        $relocator = $this->createMock(CarImageRelocator::class);
        $relocator->expects($this->once())
            ->method('relocate')
            ->with(999, 1, ['src_a.jpg'])
            ->willReturn(['src_a.jpg' => 'src_a.jpg']);
        // restore() gets the exact map relocate() returned (#1867).
        $relocator->expects($this->once())
            ->method('restore')
            ->with(999, 1, ['src_a.jpg' => 'src_a.jpg']);

        $repo = new CarRepository($db);
        $service = new CarAdministrationService($relocator);

        $this->expectException(CarDatabaseException::class);
        $service->merge($targetCarData, 999, 'Test merge', 1, $repo);
    }
}
