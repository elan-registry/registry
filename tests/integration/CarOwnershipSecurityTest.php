<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Security regression tests for car ownership authorization (H1 bug #970).
 *
 * Pins the contract that only the car owner (or an admin) may update a car.
 * The ownership guard lives in app/api/cars/save.php — these tests verify
 * the underlying data-model conditions the guard depends on.
 *
 * Complements: tests/playwright/security/car-update-ownership.spec.js (HTTP-level 403)
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

    /**
     * H1 regression anchor: the ownership guard blocks a non-owner.
     *
     * Replicates the boolean expression from app/api/cars/save.php:154 using
     * the same global $user and hasPerm([2, 3]) that the production guard uses.
     */
    public function testOwnershipGuardBlocksNonOwner(): void
    {
        $ownerUserId = $this->createTestUser();
        $nonOwnerUserId = $this->createTestUser();
        $carId = $this->createTestCar($ownerUserId);

        global $user;
        $originalUser = $user;

        $nonOwner = new User();
        $nonOwner->find($nonOwnerUserId);

        $reflection = new ReflectionClass($nonOwner);
        $prop = $reflection->getProperty('_isLoggedIn');
        $prop->setValue($nonOwner, true);

        $user = $nonOwner;
        $GLOBALS['user'] = $nonOwner;

        // hasPerm() reads the $master_account global, which may be null when
        // users/init.php aborts early in the integration test bootstrap.
        global $master_account;
        $masterAccountBackup = $master_account;
        $master_account = $master_account ?? [];

        try {
            $car = new Car($carId);

            $isNotOwner = ((int) $user->data()->id !== (int) $car->data()->user_id);
            $isNotAdmin = !hasPerm([2, 3]);

            $this->assertTrue($isNotOwner && $isNotAdmin, 'Guard must block non-owner');
        } finally {
            $master_account = $masterAccountBackup;
            $user = $originalUser;
            $GLOBALS['user'] = $originalUser;
        }
    }

    /**
     * The ownership guard passes when the session user is the car owner.
     */
    public function testOwnershipGuardAllowsOwner(): void
    {
        $ownerUserId = $this->createTestUser();
        $carId = $this->createTestCar($ownerUserId);

        global $user;
        $originalUser = $user;

        $owner = new User();
        $owner->find($ownerUserId);

        $reflection = new ReflectionClass($owner);
        $prop = $reflection->getProperty('_isLoggedIn');
        $prop->setValue($owner, true);

        $user = $owner;
        $GLOBALS['user'] = $owner;

        try {
            $car = new Car($carId);

            $isNotOwner = ((int) $user->data()->id !== (int) $car->data()->user_id);

            $this->assertFalse($isNotOwner, 'Guard must allow the owner through');
        } finally {
            $user = $originalUser;
            $GLOBALS['user'] = $originalUser;
        }
    }
}
