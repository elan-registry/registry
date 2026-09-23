<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use PHPUnit\Framework\Attributes\Group;

/**
 * Security regression tests for car ownership authorization (H1 bug #970).
 *
 * Pins the contract that only the car owner (or an admin) may update a car.
 * The ownership guard lives in app/api/cars/save.php (updateCar, :149) — these
 * tests verify the underlying data-model conditions the guard depends on,
 * not the guard itself.
 *
 * Complements: tests/playwright/security/car-update-ownership.spec.js
 * (HTTP-level 403 for a non-existent car_id, and CSRF rejection)
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

    /**
     * cars.user_id is stored and returned correctly by the Car model.
     *
     * If the Car class stops persisting or returning user_id, this breaks first
     * and makes the ownership guard inoperable.
     */
    public function testCarOwnershipStoredCorrectly(): void
    {
        $ownerUserId = $this->createTestUser();
        $carId = $this->createTestCar($ownerUserId);

        $car = new Car($carId);

        $this->assertEquals($ownerUserId, (int) $car->data()->user_id);
    }

    /**
     * Two distinct users have distinct IDs, so non-owner detection is reliable.
     */
    public function testNonOwnerIsIdentifiedAsNotOwner(): void
    {
        $ownerUserId = $this->createTestUser();
        $nonOwnerUserId = $this->createTestUser();
        $carId = $this->createTestCar($ownerUserId);

        $car = new Car($carId);

        $this->assertNotEquals($nonOwnerUserId, (int) $car->data()->user_id);
    }
}
