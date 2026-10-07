<?php

declare(strict_types=1);

use ElanRegistry\Exceptions\CarCreationException;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarDeletionException;
use ElanRegistry\Exceptions\CarException;
use ElanRegistry\Exceptions\CarMergeException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarPermissionException;
use ElanRegistry\Exceptions\CarTransferException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ElanRegistryException;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** Existing catch (CarException) blocks depend on this hierarchy. */
#[Group('unit')]
#[Group('exceptions')]
class CarExceptionHierarchyTest extends TestCase
{
    private const CAR_EXCEPTION_CLASSES = [
        CarNotFoundException::class,
        CarCreationException::class,
        CarValidationException::class,
        CarDeletionException::class,
        CarMergeException::class,
        CarTransferException::class,
        CarDatabaseException::class,
        CarPermissionException::class,
    ];

    public function testCarExceptionIsAbstract(): void
    {
        $reflection = new ReflectionClass(CarException::class);
        $this->assertTrue(
            $reflection->isAbstract(),
            'CarException must be abstract'
        );
    }

    public function testCarExceptionExtendsElanRegistryException(): void
    {
        $this->assertTrue(
            is_subclass_of(CarException::class, ElanRegistryException::class),
            'CarException should extend ElanRegistryException'
        );
    }

    #[DataProvider('carExceptionClassProvider')]
    public function testCarExceptionExtendsCarException(string $className): void
    {
        $this->assertTrue(
            is_subclass_of($className, CarException::class),
            "{$className} should extend CarException"
        );
    }

    #[DataProvider('carExceptionClassProvider')]
    public function testAllCarExceptionsAreInstanceOfCarException(string $className): void
    {
        $exception = new $className();
        $this->assertInstanceOf(
            CarException::class,
            $exception,
            "{$className} should be instanceof CarException"
        );
    }

    public function testCarExceptionCatchBlockCatchesAllCarExceptions(): void
    {
        foreach (self::CAR_EXCEPTION_CLASSES as $className) {
            $caught = false;
            try {
                throw new $className('Test');
            } catch (CarException $e) {
                $caught = true;
            }
            $this->assertTrue(
                $caught,
                "{$className} should be caught by catch (CarException)"
            );
        }
    }

    /** @return array<string, array<int, string>> */
    public static function carExceptionClassProvider(): array
    {
        $data = [];
        foreach (self::CAR_EXCEPTION_CLASSES as $class) {
            $data[$class] = [$class];
        }
        return $data;
    }
}
