<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use ElanRegistry\Exceptions\AdminContactException;
use ElanRegistry\Exceptions\AdminOperationException;
use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\Exceptions\OwnerException;
use ElanRegistry\Exceptions\OwnerSearchException;
use PHPUnit\Framework\TestCase;

class AdminExceptionsTest extends TestCase
{
    public function testAdminContactException(): void
    {
        $message = 'Admin user not found';
        $exception = new AdminContactException($message);

        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals(
            'An error occurred while sending the message. Please try again.',
            $exception->getUserMessage()
        );
        $this->assertEquals('CarActions', $exception->getLogCategory());
        $this->assertEquals(500, $exception->getHttpStatusCode());
    }

    public function testAdminContactExceptionWithCustomUserMessage(): void
    {
        $technicalMessage = 'Database connection timeout';
        $userMessage = 'We encountered a temporary issue. Please try again.';

        $exception = AdminContactException::withUserMessage(
            $technicalMessage,
            $userMessage
        );

        $this->assertEquals($technicalMessage, $exception->getMessage());
        $this->assertEquals($userMessage, $exception->getUserMessage());
        $this->assertEquals('CarActions', $exception->getLogCategory());
    }

    /** #651: the catch block must use getUserMessage(), not getMessage(). */
    public function testGetMessageAndGetUserMessageAreDistinctForIssue651(): void
    {
        $e = new AdminContactException('Admin user not found');

        $this->assertEquals('Admin user not found', $e->getMessage());
        $this->assertEquals(
            'An error occurred while sending the message. Please try again.',
            $e->getUserMessage()
        );
        $this->assertNotEquals($e->getMessage(), $e->getUserMessage());
    }

    public function testAdminOperationException(): void
    {
        $message = 'Failed to load owner profile';
        $exception = new AdminOperationException($message);

        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals(
            'An error occurred during the operation. Please try again.',
            $exception->getUserMessage()
        );
        $this->assertEquals('SystemError', $exception->getLogCategory());
        $this->assertEquals(500, $exception->getHttpStatusCode());
    }

    public function testOwnerSearchException(): void
    {
        $message = 'Search query too short';
        $exception = new OwnerSearchException($message);

        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals('Search failed. Please try again.', $exception->getUserMessage());
        $this->assertEquals('OwnerActions', $exception->getLogCategory());
        $this->assertEquals(500, $exception->getHttpStatusCode());
    }

    /** Action files catch ElanRegistryException; OwnerSearchException is caught as OwnerException. */
    public function testParentClasses(): void
    {
        $this->assertSame(ElanRegistryException::class, get_parent_class(AdminContactException::class));
        $this->assertSame(ElanRegistryException::class, get_parent_class(AdminOperationException::class));
        $this->assertSame(OwnerException::class, get_parent_class(OwnerSearchException::class));
    }

    public function testExceptionChaining(): void
    {
        $previous = new \Exception('Original error');
        $exception = new AdminContactException(
            'Contact operation failed',
            0,
            $previous
        );

        $this->assertSame($previous, $exception->getPrevious());
    }
}
