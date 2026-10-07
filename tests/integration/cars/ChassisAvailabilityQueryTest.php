<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Happy path of CarRepository::findByChassisKey(), which
 * app/api/cars/chassis-availability.php uses (#1604; Playwright covers only
 * error paths). There is no UNIQUE constraint, so "taken" is application-level.
 */
#[Group('integration')]
#[Group('chassis')]
final class ChassisAvailabilityQueryTest extends IntegrationTestCase
{
    private int $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->testUserId = $this->createTestUser();
    }

    /**
     * Mirrors the chassis_check handler in app/api/cars/chassis-availability.php,
     * which treats a match from CarRepository::findByChassisKey() as "taken".
     */
    private function isChassisTaken(string $year, string $type, string $chassis): bool
    {
        return (new CarRepository($this->db))->findByChassisKey($year, $type, $chassis) !== null;
    }

    /**
     * Unique chassis within varchar(15); $suffix is appended after trimming, so length stays constant.
     */
    private function randomChassis(string $suffix = ''): string
    {
        return 'T' . substr(uniqid(), -(10 - strlen($suffix))) . $suffix;
    }

    private function createFixtureCar(string $chassis): int
    {
        return $this->createTestCar($this->testUserId, [
            'year'    => 1973,
            'type'    => '36',
            'chassis' => $chassis,
        ]);
    }

    #[Group('fast')]
    public function testChassisTakenWhenDuplicateExists(): void
    {
        $chassis = $this->randomChassis();
        $this->createFixtureCar($chassis);

        $this->assertTrue($this->isChassisTaken('1973', '36', $chassis));
    }

    #[Group('fast')]
    public function testChassisAvailableWhenNoMatch(): void
    {
        $chassis = $this->randomChassis();

        $this->assertFalse($this->isChassisTaken('1973', '36', $chassis));
    }

    #[Group('fast')]
    public function testChassisAvailableWhenYearOrTypeDiffers(): void
    {
        $chassis = $this->randomChassis();
        $this->createFixtureCar($chassis);

        $this->assertFalse(
            $this->isChassisTaken('1974', '36', $chassis),
            'Same chassis under a different year must not count as taken'
        );
        $this->assertFalse(
            $this->isChassisTaken('1973', '45', $chassis), // Elan S4 DHC
            'Same chassis under a different type must not count as taken'
        );
    }

    /**
     * Case-insensitive collation: owners type the suffix letter in either case.
     */
    #[Group('fast')]
    public function testChassisMatchIsCaseInsensitive(): void
    {
        $chassis = $this->randomChassis('B');
        $this->createFixtureCar($chassis);

        $this->assertTrue($this->isChassisTaken('1973', '36', strtolower($chassis)));
    }

    /**
     * Duplicates can exist (no UNIQUE constraint), so "taken" must be count() > 0.
     */
    #[Group('fast')]
    public function testChassisStillTakenWithDuplicateRowsPresent(): void
    {
        $chassis = $this->randomChassis();
        $this->createFixtureCar($chassis);
        $this->createFixtureCar($chassis);

        $this->assertTrue($this->isChassisTaken('1973', '36', $chassis));
    }

    /**
     * The endpoint rejects chassis longer than 15; exactly 15 must still match.
     */
    #[Group('fast')]
    public function testChassisTakenAtMaxLength(): void
    {
        $chassis = 'TB' . uniqid(); // 'TB' (2) + uniqid() (13) = 15 chars exactly
        $this->assertSame(15, strlen($chassis), 'uniqid() length assumption broke — chassis is no longer at the varchar(15) boundary');
        $this->createFixtureCar($chassis);

        $this->assertTrue($this->isChassisTaken('1973', '36', $chassis));
    }
}
