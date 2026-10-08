<?php

declare(strict_types=1);

use ElanRegistry\Exceptions\AdminContactException;
use ElanRegistry\Exceptions\AdminOperationException;
use ElanRegistry\Exceptions\BackupException;
use ElanRegistry\Exceptions\CarCreationException;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarDeletionException;
use ElanRegistry\Exceptions\CarMergeException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarPermissionException;
use ElanRegistry\Exceptions\CarTransferException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\Exceptions\ImageProcessingException;
use ElanRegistry\Exceptions\LocationServiceException;
use ElanRegistry\Exceptions\OwnerCreationException;
use ElanRegistry\Exceptions\OwnerDatabaseException;
use ElanRegistry\Exceptions\OwnerUpdateException;
use ElanRegistry\Exceptions\OwnerValidationException;
use ElanRegistry\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('unit')]
#[Group('exceptions')]
class ExceptionHierarchyTest extends TestCase
{
    private const EXCEPTION_CLASSES = [
        AdminContactException::class,
        AdminOperationException::class,
        CarNotFoundException::class,
        CarCreationException::class,
        CarValidationException::class,
        CarDeletionException::class,
        CarMergeException::class,
        CarTransferException::class,
        CarDatabaseException::class,
        CarPermissionException::class,
        OwnerCreationException::class,
        OwnerValidationException::class,
        OwnerUpdateException::class,
        OwnerDatabaseException::class,
        ImageProcessingException::class,
        BackupException::class,
        ValidationException::class,
        LocationServiceException::class,
    ];

    public function testBaseClassIsAbstract(): void
    {
        $reflection = new ReflectionClass(ElanRegistryException::class);
        $this->assertTrue(
            $reflection->isAbstract(),
            'ElanRegistryException must be abstract'
        );
    }

    #[DataProvider('exceptionClassProvider')]
    public function testExceptionExtendsBase(string $className): void
    {
        $this->assertTrue(
            class_exists($className),
            "{$className} class should exist"
        );

        $this->assertTrue(
            is_subclass_of($className, ElanRegistryException::class),
            "{$className} should extend ElanRegistryException"
        );
    }

    #[DataProvider('exceptionClassProvider')]
    public function testExceptionHasRequiredMethods(string $className): void
    {
        $exception = new $className();

        $this->assertIsString(
            $exception->getUserMessage(),
            "{$className}::getUserMessage() should return string"
        );

        $this->assertIsString(
            $exception->getLogCategory(),
            "{$className}::getLogCategory() should return string"
        );

        $this->assertIsInt(
            $exception->getHttpStatusCode(),
            "{$className}::getHttpStatusCode() should return int"
        );
    }

    #[DataProvider('exceptionClassProvider')]
    public function testHttpStatusCodeIsValid(string $className): void
    {
        $exception = new $className();
        $statusCode = $exception->getHttpStatusCode();

        $this->assertGreaterThanOrEqual(
            400,
            $statusCode,
            "{$className} HTTP status should be >= 400"
        );

        $this->assertLessThan(
            600,
            $statusCode,
            "{$className} HTTP status should be < 600"
        );
    }

    #[DataProvider('exceptionClassProvider')]
    public function testUserMessageIsUserFriendly(string $className): void
    {
        $exception = new $className();
        $userMessage = $exception->getUserMessage();

        $this->assertNotEmpty(
            $userMessage,
            "{$className} should have a non-empty user message"
        );

        // User messages should end with proper punctuation
        $this->assertMatchesRegularExpression(
            '/[.!]$/',
            $userMessage,
            "{$className} user message should end with punctuation"
        );

        // User messages should not contain technical terms
        $this->assertStringNotContainsStringIgnoringCase(
            'exception',
            $userMessage,
            "{$className} user message should not contain 'exception'"
        );

        $this->assertStringNotContainsStringIgnoringCase(
            'error code',
            $userMessage,
            "{$className} user message should not contain 'error code'"
        );
    }

    #[DataProvider('exceptionWithCategoryProvider')]
    public function testLogCategoryMatchesExpected(
        string $className,
        string $expectedCategory
    ): void {
        $exception = new $className();

        $this->assertEquals(
            $expectedCategory,
            $exception->getLogCategory(),
            "{$className} should have log category '{$expectedCategory}'"
        );
    }

    #[DataProvider('exceptionWithStatusProvider')]
    public function testHttpStatusMatchesExpected(
        string $className,
        int $expectedStatus
    ): void {
        $exception = new $className();

        $this->assertEquals(
            $expectedStatus,
            $exception->getHttpStatusCode(),
            "{$className} should have HTTP status {$expectedStatus}"
        );
    }

    #[DataProvider('exceptionClassProvider')]
    public function testBackwardCompatibility(string $className): void
    {
        $e1 = new $className('Custom message');
        $this->assertEquals('Custom message', $e1->getMessage());

        $e2 = new $className('Custom message', 42);
        $this->assertEquals(42, $e2->getCode());

        $previous = new Exception('Previous');
        $e3 = new $className('Custom message', 42, $previous);
        $this->assertSame($previous, $e3->getPrevious());
    }

    #[DataProvider('exceptionClassProvider')]
    public function testWithUserMessageFactory(string $className): void
    {
        $exception = $className::withUserMessage(
            'Technical details for logs',
            'User-friendly message.',
            500,
            null
        );

        $this->assertEquals(
            'Technical details for logs',
            $exception->getMessage()
        );

        $this->assertEquals(
            'User-friendly message.',
            $exception->getUserMessage()
        );
    }

    #[DataProvider('exceptionClassProvider')]
    public function testPreviousAcceptsThrowable(string $className): void
    {
        $error = new Error('An error');
        $exception = new $className('Message', 0, $error);

        $this->assertSame(
            $error,
            $exception->getPrevious(),
            "{$className} should accept Error as previous exception"
        );
    }

    public function testStatusCodesAre404ForNotFound(): void
    {
        $this->assertEquals(404, (new CarNotFoundException())->getHttpStatusCode());
    }

    public function testStatusCodesAre422ForValidation(): void
    {
        $this->assertEquals(422, (new CarValidationException())->getHttpStatusCode());
        $this->assertEquals(422, (new OwnerValidationException())->getHttpStatusCode());
        $this->assertEquals(422, (new ValidationException())->getHttpStatusCode());
    }

    public function testStatusCodesForSecurityExceptions(): void
    {
        $this->assertEquals(403, (new CarPermissionException())->getHttpStatusCode());
    }

    public function testStatusCodesAre500ForServerErrors(): void
    {
        $this->assertEquals(500, (new CarCreationException())->getHttpStatusCode());
        $this->assertEquals(500, (new CarDeletionException())->getHttpStatusCode());
        $this->assertEquals(500, (new CarMergeException())->getHttpStatusCode());
        $this->assertEquals(500, (new CarDatabaseException())->getHttpStatusCode());
        $this->assertEquals(500, (new OwnerCreationException())->getHttpStatusCode());
        $this->assertEquals(500, (new OwnerUpdateException())->getHttpStatusCode());
        $this->assertEquals(500, (new OwnerDatabaseException())->getHttpStatusCode());
        $this->assertEquals(500, (new ImageProcessingException())->getHttpStatusCode());
        $this->assertEquals(500, (new BackupException('msg'))->getHttpStatusCode());
        $this->assertEquals(500, (new LocationServiceException())->getHttpStatusCode());
    }

    /**
     * Transfer failures are conflict-class errors (concurrent admin actions,
     * state conflicts) rather than server faults.
     */
    public function testCarTransferExceptionReturns409(): void
    {
        $this->assertEquals(409, (new CarTransferException())->getHttpStatusCode());
    }

    public function testDefaultUserMessageUsedWhenNoMessageProvided(): void
    {
        $exception = new CarNotFoundException();

        $this->assertEquals(
            'The requested car could not be found.',
            $exception->getUserMessage()
        );
    }

    public function testCustomUserMessageCanBeProvided(): void
    {
        $exception = CarNotFoundException::withUserMessage(
            'Technical: Car ID 123 not found',
            'Custom user message.'
        );

        $this->assertEquals(
            'Custom user message.',
            $exception->getUserMessage()
        );
    }

    /** @return array<string, array<int, string>> */
    public static function exceptionClassProvider(): array
    {
        $data = [];
        foreach (self::EXCEPTION_CLASSES as $class) {
            $data[$class] = [$class];
        }
        return $data;
    }

    /** @return array<string, array<int, string>> */
    public static function exceptionWithCategoryProvider(): array
    {
        return [
            'CarNotFoundException' => [CarNotFoundException::class, 'CarErrors'],
            'CarCreationException' => [CarCreationException::class, 'CarCreation'],
            'CarValidationException' => [CarValidationException::class, 'ValidationError'],
            'CarDeletionException' => [CarDeletionException::class, 'CarDeletion'],
            'CarMergeException' => [CarMergeException::class, 'CarMerge'],
            'CarTransferException' => [CarTransferException::class, 'CarTransferError'],
            'CarDatabaseException' => [CarDatabaseException::class, 'DatabaseError'],
            'CarPermissionException' => [CarPermissionException::class, 'AccessDenied'],
            'OwnerCreationException' => [OwnerCreationException::class, 'OwnerActions'],
            'OwnerValidationException' => [OwnerValidationException::class, 'ValidationError'],
            'OwnerUpdateException' => [OwnerUpdateException::class, 'OwnerActions'],
            'OwnerDatabaseException' => [OwnerDatabaseException::class, 'DatabaseError'],
            'ImageProcessingException' => [ImageProcessingException::class, 'FileError'],
            'AdminContactException' => [AdminContactException::class, 'CarActions'],
            'AdminOperationException' => [AdminOperationException::class, 'SystemError'],
            'BackupException' => [BackupException::class, 'BackupError'],
            'ValidationException' => [ValidationException::class, 'ValidationError'],
            'LocationServiceException' => [LocationServiceException::class, 'SystemError'],
        ];
    }

    /** @return array<string, array<int, int|string>> */
    public static function exceptionWithStatusProvider(): array
    {
        return [
            'CarNotFoundException' => [CarNotFoundException::class, 404],
            'CarCreationException' => [CarCreationException::class, 500],
            'CarValidationException' => [CarValidationException::class, 422],
            'CarDeletionException' => [CarDeletionException::class, 500],
            'CarMergeException' => [CarMergeException::class, 500],
            'CarTransferException' => [CarTransferException::class, 409],
            'CarDatabaseException' => [CarDatabaseException::class, 500],
            'CarPermissionException' => [CarPermissionException::class, 403],
            'OwnerCreationException' => [OwnerCreationException::class, 500],
            'OwnerValidationException' => [OwnerValidationException::class, 422],
            'OwnerUpdateException' => [OwnerUpdateException::class, 500],
            'OwnerDatabaseException' => [OwnerDatabaseException::class, 500],
            'ImageProcessingException' => [ImageProcessingException::class, 500],
            'AdminContactException' => [AdminContactException::class, 500],
            'AdminOperationException' => [AdminOperationException::class, 500],
            'BackupException' => [BackupException::class, 500],
            'ValidationException' => [ValidationException::class, 422],
            'LocationServiceException' => [LocationServiceException::class, 500],
        ];
    }
}
