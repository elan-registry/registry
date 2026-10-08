<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Car verification: verification codes, verification status, and sold marking.
 */
#[Group('integration')]
final class CarVerificationTest extends IntegrationTestCase
{
    private $testCarId;
    private $testUserId;
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->db = DB::getInstance();

        $this->testUserId = $this->createTestUser();

        try {
            $this->testCarId = $this->createTestCar($this->testUserId, [
                'chassis' => 'VF' . uniqid()
            ]);
        } catch (RuntimeException $e) {
            $this->markTestSkipped('Could not create test car: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    #[Group('fast')]
    public function testSetVerificationCodeSuccess(): void
    {
        $car = new Car($this->testCarId);
        $verificationCode = 'TEST-VERIFY-CODE-123';

        $result = $car->setVerificationCode($verificationCode);

        $this->assertTrue($result);

        // The property holds the plaintext for the caller; the DB row stores a hash.
        $this->assertEquals($verificationCode, $car->data()->vericode);

        $row = $this->db->query('SELECT vericode FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertNotNull($row, 'Expected to find the test car row after update');
        $storedVericode = (string) $row->vericode;

        $this->assertSame(
            hashVericode($verificationCode),
            $storedVericode,
            'Stored cars.vericode must equal hashVericode($plaintext)'
        );
        $this->assertNotSame(
            $verificationCode,
            $storedVericode,
            'Stored cars.vericode must NOT be the plaintext verification code'
        );
    }

    #[Group('fast')]
    public function testSetVerificationCodeFailsWithShortCode(): void
    {
        $this->expectException(Exception::class);

        $car = new Car($this->testCarId);
        $car->setVerificationCode('short');
    }

    #[Group('fast')]
    public function testSetVerificationCodeFailsWhenCarNotExists(): void
    {
        $this->expectException(Exception::class);

        $car = new Car(99999);
        $car->setVerificationCode('TEST-VERIFY-CODE-123');
    }

    #[Group('fast')]
    public function testMarkVerifiedSuccess(): void
    {
        $car = new Car($this->testCarId);

        $result = $car->markVerified();

        $this->assertTrue($result);

        $verifiedCar = new Car($this->testCarId);
        $this->assertNotNull($verifiedCar->data()->last_verified);
    }

    #[Group('fast')]
    public function testMarkVerifiedUpdatesTimestamp(): void
    {
        // A fixed past timestamp, so no wall-clock wait is needed.
        $this->db->update('cars', $this->testCarId, [
            'last_verified' => date('Y-m-d H:i:s', strtotime('-1 hour')),
        ]);

        $car = new Car($this->testCarId);
        $originalTimestamp = $car->data()->last_verified;

        $result = $car->markVerified();

        $this->assertTrue($result);

        $verifiedCar = new Car($this->testCarId);
        $newTimestamp = $verifiedCar->data()->last_verified;

        if ($originalTimestamp) {
            $this->assertNotEquals($originalTimestamp, $newTimestamp);
        }
    }

    /**
     * Put a clearly old owner_last_updated on the test car, so that a reset
     * to "now" is visible in the assertions.
     */
    private function seedOldOwnerLastUpdated(): string
    {
        $old = date('Y-m-d H:i:s', strtotime('-2 years'));
        $this->seedOwnerLastUpdated($this->testCarId, $old);
        return $old;
    }

    /**
     * #1929: the Verify link is an owner action, so markVerified() resets
     * owner_last_updated to now, in one UPDATE (one cars_hist row).
     */
    #[Group('fast')]
    public function testMarkVerifiedResetsOwnerLastUpdatedWithOneHistoryRow(): void
    {
        $old = $this->seedOldOwnerLastUpdated();
        $histBefore = $this->countCarsHistRows($this->testCarId);

        $this->assertTrue((new Car($this->testCarId))->markVerified());

        $after = $this->getOwnerLastUpdated($this->testCarId);
        $this->assertNotSame($old, $after, 'markVerified() must reset owner_last_updated');
        $this->assertEqualsWithDelta(time(), strtotime($after), 60, 'owner_last_updated must be set to now');
        $this->assertSame($histBefore + 1, $this->countCarsHistRows($this->testCarId), 'markVerified() must write exactly one cars_hist row');
    }

    /**
     * #1929: the Sold link is an owner action, so markSold() resets
     * owner_last_updated to now, in one UPDATE (one cars_hist row).
     */
    #[Group('fast')]
    public function testMarkSoldResetsOwnerLastUpdatedWithOneHistoryRow(): void
    {
        $old = $this->seedOldOwnerLastUpdated();
        $histBefore = $this->countCarsHistRows($this->testCarId);

        $this->assertTrue((new Car($this->testCarId))->markSold('2023-06-15'));

        $after = $this->getOwnerLastUpdated($this->testCarId);
        $this->assertNotSame($old, $after, 'markSold() must reset owner_last_updated');
        $this->assertEqualsWithDelta(time(), strtotime($after), 60, 'owner_last_updated must be set to now, not to the sold date');
        $this->assertSame($histBefore + 1, $this->countCarsHistRows($this->testCarId), 'markSold() must write exactly one cars_hist row');
    }

    #[Group('fast')]
    public function testMarkSoldWithCustomDate(): void
    {
        $car = new Car($this->testCarId);
        $customDate = '2023-06-15';

        $result = $car->markSold($customDate);

        $this->assertTrue($result);

        $soldCar = new Car($this->testCarId);
        $this->assertStringStartsWith($customDate, $soldCar->data()->solddate);
    }

    #[Group('fast')]
    public function testMarkSoldWithDefaultDate(): void
    {
        $car = new Car($this->testCarId);

        $result = $car->markSold();

        $this->assertTrue($result);

        $soldCar = new Car($this->testCarId);
        $today = date('Y-m-d');
        $this->assertStringStartsWith($today, $soldCar->data()->solddate);
    }

    #[Group('fast')]
    public function testMarkSoldFailsWithInvalidDate(): void
    {
        $this->expectException(Exception::class);

        $car = new Car($this->testCarId);
        $car->markSold('invalid-date-12345');
    }

    #[Group('fast')]
    public function testFindByVerificationCodeSuccess(): void
    {
        $car = new Car($this->testCarId);
        $verificationCode = 'UNIQUE-TEST-CODE-' . uniqid();

        $car->setVerificationCode($verificationCode);

        $foundCar = Car::findByVerificationCode($verificationCode);

        $this->assertInstanceOf(Car::class, $foundCar);
        $this->assertEquals($this->testCarId, $foundCar->data()->id);

        // Mutation guard: the round-trip alone passes if hashing is removed from
        // both sides, so the raw row must not be the plaintext.
        $row = $this->db->query('SELECT vericode FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertNotNull($row, 'Expected to find the test car row after update');
        $this->assertNotSame(
            $verificationCode,
            (string) $row->vericode,
            'Stored cars.vericode must NOT be the plaintext verification code'
        );
    }

    #[Group('fast')]
    public function testFindByVerificationCodeReturnsNullWhenNotFound(): void
    {
        $result = Car::findByVerificationCode('NONEXISTENT-CODE-12345');

        $this->assertNull($result);
    }

    /**
     * A wrong code and the raw stored hash must both fail: a leaked DB hash
     * must not work as a bearer token.
     */
    #[Group('fast')]
    public function testFindByVerificationCodeFailsForWrongOrRawHashCode(): void
    {
        $car = new Car($this->testCarId);
        $correctCode = 'CORRECT-CODE-' . uniqid();
        $wrongCode = 'WRONG-CODE-' . uniqid();

        // The codes must differ, or this test passes vacuously.
        $this->assertNotSame($correctCode, $wrongCode);

        $car->setVerificationCode($correctCode);

        $wrongResult = Car::findByVerificationCode($wrongCode);
        $this->assertNull($wrongResult, 'A wrong or stale verification code must not resolve a car');

        $row = $this->db->query('SELECT vericode FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertNotNull($row, 'Expected to find the test car row after update');
        $storedHash = (string) $row->vericode;

        $hashAsLookupResult = Car::findByVerificationCode($storedHash);
        $this->assertNull(
            $hashAsLookupResult,
            'The raw stored hash must not be usable directly as a verification code lookup key'
        );

        // The correct code still resolves, so the failures above are not a broken lookup.
        $correctResult = Car::findByVerificationCode($correctCode);
        $this->assertInstanceOf(Car::class, $correctResult);
        $this->assertEquals($this->testCarId, $correctResult->data()->id);
    }

    /**
     * Trap: CarRepository::updateVerificationCode() writes its value verbatim.
     * Plaintext written through it directly cannot be found, because
     * findByVerificationCode() hashes its input. Only
     * CarVerificationManager::setVerificationCode() is a safe plaintext entry point.
     */
    #[Group('fast')]
    public function testUpdateVerificationCodeWritesPlaintextVerbatimAndBreaksTheHashedLookupContract(): void
    {
        // CarRepository needs a DbAdapter, not the raw DB singleton.
        $repo = new CarRepository(new \ElanRegistry\Database\DbAdapter($this->db));
        $plaintextCodeWrittenDirectly = 'DIRECT-PLAINTEXT-CODE-' . uniqid();

        $result = $repo->updateVerificationCode($this->testCarId, $plaintextCodeWrittenDirectly);
        $this->assertTrue($result, 'updateVerificationCode() must succeed at the DB level');

        $row = $this->db->query('SELECT vericode FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertNotNull($row, 'Expected to find the test car row after update');
        $this->assertSame(
            $plaintextCodeWrittenDirectly,
            (string) $row->vericode,
            'updateVerificationCode() must write its argument verbatim — no hashing of its own'
        );

        $lookupResult = Car::findByVerificationCode($plaintextCodeWrittenDirectly);
        $this->assertNull(
            $lookupResult,
            'A plaintext code written directly via CarRepository::updateVerificationCode() '
            . '(bypassing CarVerificationManager) must NOT be findable via '
            . 'findByVerificationCode() — proving the repository does not hash on write, '
            . 'so callers who skip CarVerificationManager silently break the lookup contract'
        );
    }

    #[Group('fast')]
    public function testFindByVerificationCodeFailsWithEmptyCode(): void
    {
        $result = Car::findByVerificationCode('');

        $this->assertNull($result);
    }

    #[Group('fast')]
    public function testFindByVerificationCodeWithSpecialCharacters(): void
    {
        $result = Car::findByVerificationCode("O'Brien\"; DROP TABLE cars; --<script>");

        $this->assertNull($result, 'No car should match an arbitrary special-character code, and the query must not error');

        // The payload was bound as a literal, not executed as SQL.
        $row = $this->db->query('SELECT id FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertNotEmpty($row, "cars table (and this test's fixture row) must survive the lookup unharmed");
    }

    /**
     * hashVericode() output must fit cars.vericode varchar(64). Integration
     * only: the unit bootstrap stub does not return a 64-char hash.
     */
    #[Group('fast')]
    public function testHashVericodeProducesSixtyFourCharacterHash(): void
    {
        $this->assertSame(64, strlen(hashVericode('SOME-VERIFICATION-CODE')));
    }

    /**
     * markSold() must not clear vericode: per the verification FRD, the link
     * stays valid for 60 days from vericode_sent_at, e.g. for a correction.
     */
    #[Group('fast')]
    public function testMarkSoldPreservesVerificationCode(): void
    {
        $car = new Car($this->testCarId);
        $verificationCode = 'TEST-SOLD-CODE-' . uniqid();

        $car->setVerificationCode($verificationCode);
        $storedHashBeforeSold = (string) $this->db->query(
            'SELECT vericode FROM cars WHERE id = ?',
            [$this->testCarId]
        )->first()->vericode;

        $car->markSold();

        $storedHashAfterSold = (string) $this->db->query(
            'SELECT vericode FROM cars WHERE id = ?',
            [$this->testCarId]
        )->first()->vericode;

        $this->assertSame(
            $storedHashBeforeSold,
            $storedHashAfterSold,
            'markSold() must not clear or change cars.vericode — the FRD requires the '
            . 'verification link to stay live for corrections within the 60-day expiry window'
        );
    }
}
