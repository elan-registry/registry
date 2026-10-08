<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use PHPUnit\Framework\Attributes\Group;

/**
 * #970: the data-model conditions that save.php's ownership guard depends on.
 * HTTP-level checks: tests/playwright/security/car-update-ownership.spec.js.
 */
#[Group('integration')]
#[Group('security')]
final class CarOwnershipSecurityTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /** If Car stops persisting or returning user_id, the ownership guard cannot work. */
    public function testCarOwnershipStoredCorrectly(): void
    {
        $ownerUserId = $this->createTestUser();
        $carId = $this->createTestCar($ownerUserId);

        $car = new Car($carId);

        $this->assertEquals($ownerUserId, (int) $car->data()->user_id);
    }

    public function testNonOwnerIsIdentifiedAsNotOwner(): void
    {
        $ownerUserId = $this->createTestUser();
        $nonOwnerUserId = $this->createTestUser();
        $carId = $this->createTestCar($ownerUserId);

        $car = new Car($carId);

        $this->assertNotEquals($nonOwnerUserId, (int) $car->data()->user_id);
    }
}
