<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use ElanRegistry\Exceptions\AdminContactException;
use ElanRegistry\Exceptions\AdminOperationException;
use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\Exceptions\OwnerSearchException;
use PHPUnit\Framework\TestCase;

/**
 * AdminExceptionsTest
 *
 * Tests for admin-specific exception classes to verify proper functionality
 * including user messages, log categories, and HTTP status codes.
 */
class AdminExceptionsTest extends TestCase
{
    /**
     * Test AdminContactException instantiation and properties
     */
    public function testAdminContactException(): void
    {
        $message = 'Admin user not found';
        $exception = new AdminContactException($message);

        $this->assertInstanceOf(AdminContactException::class, $exception);
        $this->assertInstanceOf(ElanRegistryException::class, $exception);
        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals(
            'An error occurred while sending the message. Please try again.',
            $exception->getUserMessage()
        );
        $this->assertEquals('CarActions', $exception->getLogCategory());
        $this->assertEquals(500, $exception->getHttpStatusCode());
    }

    /**
     * Test AdminContactException with custom user message
     */
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

    /**
     * Test AdminOperationException instantiation and properties
     */
    public function testAdminOperationException(): void
    {
        $message = 'Failed to load owner profile';
        $exception = new AdminOperationException($message);

        $this->assertInstanceOf(AdminOperationException::class, $exception);
        $this->assertInstanceOf(ElanRegistryException::class, $exception);
        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals(
            'An error occurred during the operation. Please try again.',
            $exception->getUserMessage()
        );
        $this->assertEquals('SystemError', $exception->getLogCategory());
        $this->assertEquals(500, $exception->getHttpStatusCode());
    }

    /**
     * Test OwnerSearchException instantiation and properties
     */
    public function testOwnerSearchException(): void
    {
        $message = 'Search query too short';
        $exception = new OwnerSearchException($message);

        $this->assertInstanceOf(OwnerSearchException::class, $exception);
        $this->assertInstanceOf(ElanRegistryException::class, $exception);
        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals('Search failed. Please try again.', $exception->getUserMessage());
        $this->assertEquals('OwnerActions', $exception->getLogCategory());
        $this->assertEquals(500, $exception->getHttpStatusCode());
    }

    /**
     * Test that exceptions can be caught by their specific types
     */
    public function testExceptionCatchingByType(): void
    {
        $caughtCorrectly = false;

        try {
            throw new AdminContactException('Test error');
        } catch (AdminContactException $e) {
            $caughtCorrectly = true;
        }

        $this->assertTrue($caughtCorrectly, 'AdminContactException should be catchable by its type');
    }

    /**
     * Test that exceptions can be caught by their parent type
     */
    public function testExceptionCatchingByParentType(): void
    {
        $caughtCorrectly = false;

        try {
            throw new AdminOperationException('Test error');
        } catch (ElanRegistryException $e) {
            $caughtCorrectly = true;
            $this->assertInstanceOf(AdminOperationException::class, $e);
        }

        $this->assertTrue($caughtCorrectly, 'AdminOperationException should be catchable as ElanRegistryException');
    }

    /**
     * Test exception chaining with previous exception
     */
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
