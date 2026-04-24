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

/**
 * Test cases for the CarException hierarchy
 *
 * Verifies that all car-related exceptions properly extend CarException,
 * which in turn extends ElanRegistryException, maintaining backward
 * compatibility with existing catch blocks.
 */
#[Group('unit')]
#[Group('exceptions')]
class CarExceptionHierarchyTest extends TestCase
{
    /**
     * All car exception classes that should extend CarException
     */
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

    /**
     * Test that CarException is abstract and cannot be instantiated
     */
    public function testCarExceptionIsAbstract(): void
    {
        $reflection = new ReflectionClass(CarException::class);
        $this->assertTrue(
            $reflection->isAbstract(),
            'CarException must be abstract'
        );
    }

    /**
     * Test that CarException extends ElanRegistryException
     */
    public function testCarExceptionExtendsElanRegistryException(): void
    {
        $this->assertTrue(
            is_subclass_of(CarException::class, ElanRegistryException::class),
            'CarException should extend ElanRegistryException'
        );
    }

    /**
     * Test that all car exceptions extend CarException
     *
     * @param string $className Exception class name to test
     */
    #[DataProvider('carExceptionClassProvider')]
    public function testCarExceptionExtendsCarException(string $className): void
    {
        $this->assertTrue(
            is_subclass_of($className, CarException::class),
            "{$className} should extend CarException"
        );
    }

    /**
     * Test backward compatibility - all car exceptions are still instanceof ElanRegistryException
     *
     * @param string $className Exception class name to test
     */
    #[DataProvider('carExceptionClassProvider')]
    public function testBackwardCompatibilityWithElanRegistryException(string $className): void
    {
        $exception = new $className();
        $this->assertInstanceOf(
            ElanRegistryException::class,
            $exception,
            "{$className} should be instanceof ElanRegistryException for backward compatibility"
        );
    }

    /**
     * Test backward compatibility - all car exceptions are instanceof CarException
     *
     * @param string $className Exception class name to test
     */
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

    /**
     * Test CarDatabaseException defaults
     */
    public function testCarDatabaseExceptionDefaults(): void
    {
        $exception = new CarDatabaseException();

        $this->assertEquals(500, $exception->getHttpStatusCode());
        $this->assertEquals('DatabaseError', $exception->getLogCategory());
        $this->assertNotEmpty($exception->getUserMessage());
    }

    /**
     * Test CarPermissionException defaults
     */
    public function testCarPermissionExceptionDefaults(): void
    {
        $exception = new CarPermissionException();

        $this->assertEquals(403, $exception->getHttpStatusCode());
        $this->assertEquals('AccessDenied', $exception->getLogCategory());
        $this->assertNotEmpty($exception->getUserMessage());
    }

    /**
     * Test that CarException catch block catches all car exceptions
     */
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

    /**
     * Data provider for car exception classes
     *
     * @return array<string, array<int, string>>
     */
    public static function carExceptionClassProvider(): array
    {
        $data = [];
        foreach (self::CAR_EXCEPTION_CLASSES as $class) {
            $data[$class] = [$class];
        }
        return $data;
    }
}
