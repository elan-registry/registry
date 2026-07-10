<?php

declare(strict_types=1);

use ElanRegistry\Car\CarDataTablesService;
use ElanRegistry\Exceptions\CarValidationException;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for CarDataTablesService service class
 */
#[Group('fast')]
final class CarDataTablesServiceTest extends TestCase
{
    private CarDataTablesService $service;

    protected function setUp(): void
    {
        $this->service = new CarDataTablesService();
    }

    // ============================================================
    // validateColumnName tests
    // ============================================================

    public function testValidateColumnNameAcceptsValidCarsColumn(): void
    {
        $result = $this->service->validateColumnName('chassis', 'cars');
        $this->assertEquals('chassis', $result);
    }

    public function testValidateColumnNameAcceptsValidFactoryColumn(): void
    {
        $result = $this->service->validateColumnName('serial', 'elan_factory_info');
        $this->assertEquals('serial', $result);
    }

    public function testValidateColumnNameRejectsInvalidColumn(): void
    {
        $result = $this->service->validateColumnName('password', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsInvalidTable(): void
    {
        $result = $this->service->validateColumnName('id', 'users');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsSqlInjection(): void
    {
        $result = $this->service->validateColumnName('id; DROP TABLE cars', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsRemovedModifiedByColumn(): void
    {
        $result = $this->service->validateColumnName('ModifiedBy', 'cars');
        $this->assertFalse($result);
    }

    // ============================================================
    // getDataTablesData tests
    // ============================================================

    public function testGetDataTablesDataThrowsOnInvalidTable(): void
    {
        $this->expectException(CarValidationException::class);

        $request = ['draw' => 1, 'start' => 0, 'length' => 10];
        $this->service->getDataTablesData($request, 'invalid_table', DB::getInstance());
    }

}
