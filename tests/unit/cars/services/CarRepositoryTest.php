<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CarRepository service class
 *
 * @group fast
 */
final class CarRepositoryTest extends TestCase
{
    private CarRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new CarRepository(DB::getInstance());
    }

    public function testFindByIdReturnsObjectForExistingCar(): void
    {
        $result = $this->repo->findById(1);
        $this->assertIsObject($result);
        $this->assertEquals(1, $result->id);
    }

    public function testInsertReturnsTrue(): void
    {
        $result = $this->repo->insert('cars', ['chassis' => 'TEST99999', 'model' => 'Elan']);
        $this->assertTrue($result);
    }

    public function testUpdateReturnsTrue(): void
    {
        $result = $this->repo->update('cars', 1, ['color' => 'Blue']);
        $this->assertTrue($result);
    }

    public function testLastIdReturnsInt(): void
    {
        $this->repo->insert('cars', ['chassis' => 'TEST']);
        $lastId = $this->repo->lastId();
        $this->assertIsInt($lastId);
    }

    public function testGetDbReturnsDbInstance(): void
    {
        $db = $this->repo->getDb();
        $this->assertInstanceOf(DB::class, $db);
    }

    public function testTransactionMethodsDoNotThrow(): void
    {
        $this->repo->beginTransaction();
        $this->repo->commit();
        $this->assertTrue(true);
    }

    public function testRollbackDoesNotThrow(): void
    {
        $this->repo->beginTransaction();
        $this->repo->rollback();
        $this->assertTrue(true);
    }

    public function testInsertHistoryReturnsTrue(): void
    {
        $result = $this->repo->insertHistory([
            'operation' => 'TEST',
            'car_id' => 1,
            'comments' => 'Test history'
        ]);
        $this->assertTrue($result);
    }

    public function testInsertCarUserReturnsTrue(): void
    {
        $result = $this->repo->insertCarUser(1, 100);
        $this->assertTrue($result);
    }
}
