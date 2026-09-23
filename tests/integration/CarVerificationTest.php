<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Test cases for Car verification functionality
 *
 * Tests cover verification code management, verification status tracking,
 * and sold status marking with date validation.
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

        // Create unique test car for this test
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

    /**
     * Test set verification code success
     */
    #[Group('fast')]
    public function testSetVerificationCodeSuccess(): void
    {
        $car = new Car($this->testCarId);
        $verificationCode = 'TEST-VERIFY-CODE-123';

        $result = $car->setVerificationCode($verificationCode);

        $this->assertTrue($result);

        // The in-memory property holds the plaintext for the caller (e.g. the
        // future email composer) even though the DB row stores a hash.
        $this->assertEquals($verificationCode, $car->data()->vericode);

        // Verify the raw DB row stores the HMAC-SHA256 hash, never the
        // plaintext code.
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

    /**
     * Test set verification code fails with short code
     */
    #[Group('fast')]
    public function testSetVerificationCodeFailsWithShortCode(): void
    {
        $this->expectException(Exception::class);

        $car = new Car($this->testCarId);
        $car->setVerificationCode('short');
    }

    /**
     * Test set verification code fails when car does not exist
     */
    #[Group('fast')]
    public function testSetVerificationCodeFailsWhenCarNotExists(): void
    {
        $this->expectException(Exception::class);

        $car = new Car(99999);
        $car->setVerificationCode('TEST-VERIFY-CODE-123');
    }

    /**
     * Test mark verified success
     */
    #[Group('fast')]
    public function testMarkVerifiedSuccess(): void
    {
        $car = new Car($this->testCarId);

        $result = $car->markVerified();

        $this->assertTrue($result);

        // Verify car is marked as verified
        $verifiedCar = new Car($this->testCarId);
        $this->assertNotNull($verifiedCar->data()->last_verified);
    }

    /**
     * Test mark verified updates timestamp
     */
    #[Group('fast')]
    public function testMarkVerifiedUpdatesTimestamp(): void
    {
        // Seed a deterministic past timestamp so no wall-clock wait is needed to
        // guarantee markVerified()'s new value differs from it.
        $this->db->update('cars', $this->testCarId, [
            'last_verified' => date('Y-m-d H:i:s', strtotime('-1 hour')),
        ]);

        $car = new Car($this->testCarId);
        $originalTimestamp = $car->data()->last_verified;

        $result = $car->markVerified();

        $this->assertTrue($result);

        // Verify timestamp was updated
        $verifiedCar = new Car($this->testCarId);
        $newTimestamp = $verifiedCar->data()->last_verified;

        if ($originalTimestamp) {
            $this->assertNotEquals($originalTimestamp, $newTimestamp);
        }
    }

    /**
     * Test mark sold with custom date
     */
    #[Group('fast')]
    public function testMarkSoldWithCustomDate(): void
    {
        $car = new Car($this->testCarId);
        $customDate = '2023-06-15';

        $result = $car->markSold($customDate);

        $this->assertTrue($result);

        // Verify sold date was set
        $soldCar = new Car($this->testCarId);
        $this->assertStringStartsWith($customDate, $soldCar->data()->solddate);
    }

    /**
     * Test mark sold with default date
     */
    #[Group('fast')]
    public function testMarkSoldWithDefaultDate(): void
    {
        $car = new Car($this->testCarId);

        $result = $car->markSold();

        $this->assertTrue($result);

        // Verify sold date was set to today
        $soldCar = new Car($this->testCarId);
        $today = date('Y-m-d');
        $this->assertStringStartsWith($today, $soldCar->data()->solddate);
    }

    /**
     * Test mark sold fails with invalid date
     */
    #[Group('fast')]
    public function testMarkSoldFailsWithInvalidDate(): void
    {
        $this->expectException(Exception::class);

        $car = new Car($this->testCarId);
        $car->markSold('invalid-date-12345');
    }

    /**
     * Test find by verification code success
     */
    #[Group('fast')]
    public function testFindByVerificationCodeSuccess(): void
    {
        $car = new Car($this->testCarId);
        $verificationCode = 'UNIQUE-TEST-CODE-' . uniqid();

        $car->setVerificationCode($verificationCode);

        $foundCar = Car::findByVerificationCode($verificationCode);

        $this->assertInstanceOf(Car::class, $foundCar);
        $this->assertEquals($this->testCarId, $foundCar->data()->id);

        // Mutation guard: this round-trip alone would pass even if hashing
        // were removed from both setVerificationCode() and
        // findByVerificationCode(), since both sides would then use the same
        // (identity) transform. Assert the raw DB row is NOT the plaintext
        // code, proving the lookup actually went through the hash.
        $row = $this->db->query('SELECT vericode FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertNotNull($row, 'Expected to find the test car row after update');
        $this->assertNotSame(
            $verificationCode,
            (string) $row->vericode,
            'Stored cars.vericode must NOT be the plaintext verification code'
        );
    }

    /**
     * Test find by verification code returns null when not found
     */
    #[Group('fast')]
    public function testFindByVerificationCodeReturnsNullWhenNotFound(): void
    {
        $result = Car::findByVerificationCode('NONEXISTENT-CODE-12345');

        $this->assertNull($result);
    }

    /**
     * Regression guard: a wrong/stale code
     * must fail against a populated row, and the raw stored hash itself must
     * never work as a direct lookup key. This proves there is no
     * plaintext-fallback or hash-as-plaintext-match regression path — a
     * leaked DB dump's hash must not be directly usable as a bearer token.
     */
    #[Group('fast')]
    public function testFindByVerificationCodeFailsForWrongOrRawHashCode(): void
    {
        $car = new Car($this->testCarId);
        $correctCode = 'CORRECT-CODE-' . uniqid();
        $wrongCode = 'WRONG-CODE-' . uniqid();

        // Sanity: the two generated codes must actually differ, or this test
        // would pass vacuously.
        $this->assertNotSame($correctCode, $wrongCode);

        $car->setVerificationCode($correctCode);

        // A wrong/stale plaintext code must not resolve.
        $wrongResult = Car::findByVerificationCode($wrongCode);
        $this->assertNull($wrongResult, 'A wrong or stale verification code must not resolve a car');

        // The raw stored hash itself must not work as a direct lookup key —
        // otherwise a leaked DB dump's hash would be directly usable as a
        // bearer token, defeating the purpose of hashing at rest.
        $row = $this->db->query('SELECT vericode FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertNotNull($row, 'Expected to find the test car row after update');
        $storedHash = (string) $row->vericode;

        $hashAsLookupResult = Car::findByVerificationCode($storedHash);
        $this->assertNull(
            $hashAsLookupResult,
            'The raw stored hash must not be usable directly as a verification code lookup key'
        );

        // Sanity: the correct plaintext code still resolves, confirming the
        // failures above are due to the wrong inputs, not a broken lookup.
        $correctResult = Car::findByVerificationCode($correctCode);
        $this->assertInstanceOf(Car::class, $correctResult);
        $this->assertEquals($this->testCarId, $correctResult->data()->id);
    }

    /**
     * CarRepository::updateVerificationCode()'s contract is "callers are
     * responsible for hashing — this method writes the value verbatim" (see
     * its docblock). That is a footgun for any future caller that reaches
     * for the repository directly instead of going through
     * CarVerificationManager::setVerificationCode() (the only production
     * caller today, and the only one that hashes).
     *
     * This pins the failure mode: writing plaintext straight through the
     * repository must NOT be findable via findByVerificationCode(), since
     * that method hashes its input before the lookup. A row written this way
     * is effectively orphaned — proving the repository is hash-in/hash-out
     * and the manager is the only safe plaintext entry point.
     */
    #[Group('fast')]
    public function testUpdateVerificationCodeWritesPlaintextVerbatimAndBreaksTheHashedLookupContract(): void
    {
        // $this->db in this class is the raw DB singleton (see setUp()), not the
        // DbAdapter CarRepository requires — wrap it explicitly, same as
        // IntegrationTestCase's own default $db construction.
        $repo = new CarRepository(new \ElanRegistry\Database\DbAdapter($this->db));
        $plaintextCodeWrittenDirectly = 'DIRECT-PLAINTEXT-CODE-' . uniqid();

        // Bypasses CarVerificationManager::setVerificationCode() — the only
        // production call path, which hashes before calling this method.
        $result = $repo->updateVerificationCode($this->testCarId, $plaintextCodeWrittenDirectly);
        $this->assertTrue($result, 'updateVerificationCode() must succeed at the DB level');

        // The row now holds plaintext, verbatim — the repository performed no
        // transformation, exactly as its docblock claims.
        $row = $this->db->query('SELECT vericode FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertNotNull($row, 'Expected to find the test car row after update');
        $this->assertSame(
            $plaintextCodeWrittenDirectly,
            (string) $row->vericode,
            'updateVerificationCode() must write its argument verbatim — no hashing of its own'
        );

        // findByVerificationCode() hashes its input, so the plaintext written
        // directly above cannot be found by searching for that same plaintext:
        // the lookup hashes it and compares against a value that was never
        // hashed. This is the concrete, observable breakage a future direct
        // caller of updateVerificationCode() would hit.
        $lookupResult = Car::findByVerificationCode($plaintextCodeWrittenDirectly);
        $this->assertNull(
            $lookupResult,
            'A plaintext code written directly via CarRepository::updateVerificationCode() '
            . '(bypassing CarVerificationManager) must NOT be findable via '
            . 'findByVerificationCode() — proving the repository does not hash on write, '
            . 'so callers who skip CarVerificationManager silently break the lookup contract'
        );
    }

    /**
     * Test find by verification code fails with empty code
     */
    #[Group('fast')]
    public function testFindByVerificationCodeFailsWithEmptyCode(): void
    {
        // Empty code returns null, not an exception
        // This is the current behavior of findByVerificationCode()
        $result = Car::findByVerificationCode('');

        $this->assertNull($result);
    }

    /**
     * Test find by verification code with special characters is handled safely
     */
    #[Group('fast')]
    public function testFindByVerificationCodeWithSpecialCharacters(): void
    {
        $result = Car::findByVerificationCode("O'Brien\"; DROP TABLE cars; --<script>");

        $this->assertNull($result, 'No car should match an arbitrary special-character code, and the query must not error');

        // Proves the payload was treated as a literal, bound value — not executed as SQL.
        $row = $this->db->query('SELECT id FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertNotEmpty($row, "cars table (and this test's fixture row) must survive the lookup unharmed");
    }

    /**
     * Pins hashVericode()'s output length against the widened
     * cars.vericode varchar(64) column. Must run against the real
     * hashVericode() (HMAC-SHA256 hex digest, always 64 chars) — the unit
     * bootstrap stub (tests/bootstrap-unit.php) returns 'hashed_' . $code,
     * which is not 64 chars, so this assertion cannot live in the unit tier.
     */
    #[Group('fast')]
    public function testHashVericodeProducesSixtyFourCharacterHash(): void
    {
        $this->assertSame(64, strlen(hashVericode('SOME-VERIFICATION-CODE')));
    }

    /**
     * markSold() must NOT clear vericode. Per the verification-system FRD
     * (docs/plans/car-owner-verification/car-owner-verification-frd.md,
     * "Enforce the 60-day expiry"): the vericode stays live between actions
     * within the 60-day window measured from vericode_sent_at, so an owner
     * who marks a car sold today can still use the same link for a
     * correction next week. Clearing it on markSold() would break that.
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
