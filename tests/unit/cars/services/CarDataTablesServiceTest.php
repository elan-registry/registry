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

    /**
     * Invoke the private validateColumnName() method via reflection.
     */
    private function invokeValidateColumnName(string $columnName, string $tableName): string|false
    {
        $method = new ReflectionMethod(CarDataTablesService::class, 'validateColumnName');
        return $method->invoke($this->service, $columnName, $tableName);
    }

    // ============================================================
    // validateColumnName tests
    // ============================================================

    public function testValidateColumnNameAcceptsValidCarsColumn(): void
    {
        $result = $this->invokeValidateColumnName('chassis', 'cars');
        $this->assertEquals('chassis', $result);
    }

    public function testValidateColumnNameAcceptsValidFactoryColumn(): void
    {
        $result = $this->invokeValidateColumnName('serial', 'elan_factory_info');
        $this->assertEquals('serial', $result);
    }

    public function testValidateColumnNameRejectsInvalidColumn(): void
    {
        $result = $this->invokeValidateColumnName('password', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsInvalidTable(): void
    {
        $result = $this->invokeValidateColumnName('id', 'users');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsSqlInjection(): void
    {
        $result = $this->invokeValidateColumnName('id; DROP TABLE cars', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsRemovedModifiedByColumn(): void
    {
        $result = $this->invokeValidateColumnName('ModifiedBy', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsEmail(): void
    {
        $result = $this->invokeValidateColumnName('email', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsLname(): void
    {
        $result = $this->invokeValidateColumnName('lname', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsVericode(): void
    {
        $result = $this->invokeValidateColumnName('vericode', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsLastVerified(): void
    {
        $result = $this->invokeValidateColumnName('last_verified', 'cars');
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
