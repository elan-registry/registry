<?php

declare(strict_types=1);

use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\Exceptions\OwnerCreationException;
use ElanRegistry\Exceptions\OwnerDatabaseException;
use ElanRegistry\Exceptions\OwnerException;
use ElanRegistry\Exceptions\OwnerSearchException;
use ElanRegistry\Exceptions\OwnerUpdateException;
use ElanRegistry\Exceptions\OwnerValidationException;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** Existing catch (OwnerException) blocks depend on this hierarchy. */
#[Group('unit')]
#[Group('exceptions')]
class OwnerExceptionHierarchyTest extends TestCase
{
    private const OWNER_EXCEPTION_CLASSES = [
        OwnerCreationException::class,
        OwnerSearchException::class,
        OwnerUpdateException::class,
        OwnerValidationException::class,
        OwnerDatabaseException::class,
    ];

    public function testOwnerExceptionIsAbstract(): void
    {
        $reflection = new ReflectionClass(OwnerException::class);
        $this->assertTrue(
            $reflection->isAbstract(),
            'OwnerException must be abstract'
        );
    }

    public function testOwnerExceptionExtendsElanRegistryException(): void
    {
        $this->assertTrue(
            is_subclass_of(OwnerException::class, ElanRegistryException::class),
            'OwnerException should extend ElanRegistryException'
        );
    }

    #[DataProvider('ownerExceptionClassProvider')]
    public function testOwnerExceptionExtendsOwnerException(string $className): void
    {
        $this->assertTrue(
            is_subclass_of($className, OwnerException::class),
            "{$className} should extend OwnerException"
        );
    }

    #[DataProvider('ownerExceptionClassProvider')]
    public function testAllOwnerExceptionsAreInstanceOfOwnerException(string $className): void
    {
        $exception = new $className();
        $this->assertInstanceOf(
            OwnerException::class,
            $exception,
            "{$className} should be instanceof OwnerException"
        );
    }

    public function testOwnerExceptionCatchBlockCatchesAllOwnerExceptions(): void
    {
        foreach (self::OWNER_EXCEPTION_CLASSES as $className) {
            $caught = false;
            try {
                throw new $className('Test');
            } catch (OwnerException $e) {
                $caught = true;
            }
            $this->assertTrue(
                $caught,
                "{$className} should be caught by catch (OwnerException)"
            );
        }
    }

    /** @return array<string, array<int, string>> */
    public static function ownerExceptionClassProvider(): array
    {
        $data = [];
        foreach (self::OWNER_EXCEPTION_CLASSES as $class) {
            $data[$class] = [$class];
        }
        return $data;
    }
}
