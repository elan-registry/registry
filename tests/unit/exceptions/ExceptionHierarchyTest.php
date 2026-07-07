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
use ElanRegistry\Exceptions\OwnerNotFoundException;
use ElanRegistry\Exceptions\OwnerUpdateException;
use ElanRegistry\Exceptions\OwnerValidationException;
use ElanRegistry\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Test cases for the ElanRegistry exception hierarchy
 *
 * Verifies that all exceptions properly extend ElanRegistryException
 * and implement required methods correctly.
 */
#[Group('unit')]
#[Group('exceptions')]
class ExceptionHierarchyTest extends TestCase
{
    /**
     * All exception classes that should extend ElanRegistryException
     */
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
        OwnerNotFoundException::class,
        OwnerCreationException::class,
        OwnerValidationException::class,
        OwnerUpdateException::class,
        ImageProcessingException::class,
        BackupException::class,
        ValidationException::class,
        LocationServiceException::class,
    ];

    /**
     * Test that ElanRegistryException is abstract and cannot be instantiated
     */
    public function testBaseClassIsAbstract(): void
    {
        $reflection = new ReflectionClass(ElanRegistryException::class);
        $this->assertTrue(
            $reflection->isAbstract(),
            'ElanRegistryException must be abstract'
        );
    }

    /**
     * Test that all exceptions extend ElanRegistryException
     *
     * @param string $className Exception class name to test
     */
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

    /**
     * Test that all exceptions have required methods
     *
     * @param string $className Exception class name to test
     */
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

    /**
     * Test that HTTP status codes are valid
     *
     * @param string $className Exception class name to test
     */
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

    /**
     * Test that user messages are non-empty and user-friendly
     *
     * @param string $className Exception class name to test
     */
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

    /**
     * Test that log categories match expected values
     */
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

    /**
     * Test that HTTP status codes match expected values
     */
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

    /**
     * Test backward compatibility - existing constructor signature works
     *
     * @param string $className Exception class name to test
     */
    #[DataProvider('exceptionClassProvider')]
    public function testBackwardCompatibility(string $className): void
    {
        // Test with message only
        $e1 = new $className('Custom message');
        $this->assertEquals('Custom message', $e1->getMessage());

        // Test with message and code
        $e2 = new $className('Custom message', 42);
        $this->assertEquals(42, $e2->getCode());

        // Test with message, code, and previous
        $previous = new Exception('Previous');
        $e3 = new $className('Custom message', 42, $previous);
        $this->assertSame($previous, $e3->getPrevious());
    }

    /**
     * Test withUserMessage factory method
     *
     * @param string $className Exception class name to test
     */
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

    /**
     * Test that previous exception uses Throwable type (not just Exception)
     *
     * @param string $className Exception class name to test
     */
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

    /**
     * Test that specific exceptions have correct status codes
     */
    public function testStatusCodesAre404ForNotFound(): void
    {
        $this->assertEquals(404, (new CarNotFoundException())->getHttpStatusCode());
        $this->assertEquals(404, (new OwnerNotFoundException())->getHttpStatusCode());
    }

    /**
     * Test that validation exceptions have 422 status code
     */
    public function testStatusCodesAre422ForValidation(): void
    {
        $this->assertEquals(422, (new CarValidationException())->getHttpStatusCode());
        $this->assertEquals(422, (new OwnerValidationException())->getHttpStatusCode());
        $this->assertEquals(422, (new ValidationException())->getHttpStatusCode());
    }

    /**
     * Test that security exceptions have correct status codes
     */
    public function testStatusCodesForSecurityExceptions(): void
    {
        $this->assertEquals(403, (new CarPermissionException())->getHttpStatusCode());
    }

    /**
     * Test that all server errors have 500 status code
     */
    public function testStatusCodesAre500ForServerErrors(): void
    {
        $this->assertEquals(500, (new CarCreationException())->getHttpStatusCode());
        $this->assertEquals(500, (new CarDeletionException())->getHttpStatusCode());
        $this->assertEquals(500, (new CarMergeException())->getHttpStatusCode());
        $this->assertEquals(500, (new CarDatabaseException())->getHttpStatusCode());
        $this->assertEquals(500, (new OwnerCreationException())->getHttpStatusCode());
        $this->assertEquals(500, (new OwnerUpdateException())->getHttpStatusCode());
        $this->assertEquals(500, (new ImageProcessingException())->getHttpStatusCode());
        $this->assertEquals(500, (new BackupException('msg'))->getHttpStatusCode());
        $this->assertEquals(500, (new LocationServiceException())->getHttpStatusCode());
    }

    /**
     * Test that CarTransferException returns 409 Conflict
     *
     * Transfer failures are conflict-class errors (concurrent admin actions,
     * state conflicts) rather than server faults.
     */
    public function testCarTransferExceptionReturns409(): void
    {
        $this->assertEquals(409, (new CarTransferException())->getHttpStatusCode());
    }

    /**
     * Test that default user message is used when no message provided
     */
    public function testDefaultUserMessageUsedWhenNoMessageProvided(): void
    {
        $exception = new CarNotFoundException();

        $this->assertEquals(
            'The requested car could not be found.',
            $exception->getUserMessage()
        );
    }

    /**
     * Test that custom user message can be provided
     */
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

    /**
     * Data provider for exception classes
     *
     * @return array<string, array<int, string>>
     */
    public static function exceptionClassProvider(): array
    {
        $data = [];
        foreach (self::EXCEPTION_CLASSES as $class) {
            $data[$class] = [$class];
        }
        return $data;
    }

    /**
     * Data provider for exception classes with expected log categories
     *
     * @return array<string, array<int, string>>
     */
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
            'OwnerNotFoundException' => [OwnerNotFoundException::class, 'OwnerActions'],
            'OwnerCreationException' => [OwnerCreationException::class, 'OwnerActions'],
            'OwnerValidationException' => [OwnerValidationException::class, 'ValidationError'],
            'OwnerUpdateException' => [OwnerUpdateException::class, 'OwnerActions'],
            'ImageProcessingException' => [ImageProcessingException::class, 'FileError'],
            'AdminContactException' => [AdminContactException::class, 'CarActions'],
            'AdminOperationException' => [AdminOperationException::class, 'SystemError'],
            'BackupException' => [BackupException::class, 'BackupError'],
            'ValidationException' => [ValidationException::class, 'ValidationError'],
            'LocationServiceException' => [LocationServiceException::class, 'SystemError'],
        ];
    }

    /**
     * Data provider for exception classes with expected HTTP status codes
     *
     * @return array<string, array<int, int|string>>
     */
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
            'OwnerNotFoundException' => [OwnerNotFoundException::class, 404],
            'OwnerCreationException' => [OwnerCreationException::class, 500],
            'OwnerValidationException' => [OwnerValidationException::class, 422],
            'OwnerUpdateException' => [OwnerUpdateException::class, 500],
            'ImageProcessingException' => [ImageProcessingException::class, 500],
            'AdminContactException' => [AdminContactException::class, 500],
            'AdminOperationException' => [AdminOperationException::class, 500],
            'BackupException' => [BackupException::class, 500],
            'ValidationException' => [ValidationException::class, 422],
            'LocationServiceException' => [LocationServiceException::class, 500],
        ];
    }
}
