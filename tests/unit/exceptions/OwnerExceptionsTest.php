<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use ElanRegistry\Exceptions\OwnerCreationException;
use ElanRegistry\Exceptions\OwnerUpdateException;
use ElanRegistry\Exceptions\OwnerValidationException;
use PHPUnit\Framework\TestCase;

/** #927: catch blocks that used getMessage() showed technical text to users. */
class OwnerExceptionsTest extends TestCase
{
    // OwnerValidationException

    public function testOwnerValidationExceptionWithUserMessageReturnsSpecificText(): void
    {
        $technical = 'Invalid email format';
        $user = 'Invalid email format';

        $e = OwnerValidationException::withUserMessage($technical, $user);

        $this->assertEquals($technical, $e->getMessage());
        $this->assertEquals($user, $e->getUserMessage());
    }

    /** The CSRF case: the technical message holds internal state. */
    public function testOwnerValidationExceptionWithUserMessageDiffersFromTechnical(): void
    {
        $technical = 'Invalid CSRF token provided';
        $user = 'Your session may have expired. Please refresh the page and try again.';

        $e = OwnerValidationException::withUserMessage($technical, $user);

        $this->assertEquals($technical, $e->getMessage());
        $this->assertEquals($user, $e->getUserMessage());
        $this->assertNotEquals($e->getMessage(), $e->getUserMessage());
    }

    /** Old throw sites without withUserMessage() must not leak technical text. */
    public function testOwnerValidationExceptionDefaultFallback(): void
    {
        $e = new OwnerValidationException('Invalid email format');

        $this->assertEquals('Invalid email format', $e->getMessage());
        $this->assertEquals(
            'The owner information provided is invalid. Please check your input.',
            $e->getUserMessage()
        );
        $this->assertNotEquals($e->getMessage(), $e->getUserMessage());
    }

    public function testOwnerValidationExceptionProperties(): void
    {
        $e = new OwnerValidationException('Test validation error');

        $this->assertEquals(422, $e->getHttpStatusCode());
        $this->assertEquals('ValidationError', $e->getLogCategory());
    }

    // OwnerUpdateException

    /** A raw database message must not reach the user. */
    public function testOwnerUpdateExceptionWithUserMessageReturnsSpecificText(): void
    {
        $technical = 'DB::update() returned false for users table, ID 42';
        $user = 'Unable to save your profile changes. Please try again.';

        $e = OwnerUpdateException::withUserMessage($technical, $user);

        $this->assertEquals($technical, $e->getMessage());
        $this->assertEquals($user, $e->getUserMessage());
        $this->assertNotEquals($e->getMessage(), $e->getUserMessage());
    }

    public function testOwnerUpdateExceptionDefaultFallback(): void
    {
        $e = new OwnerUpdateException('DB::update() returned false');

        $this->assertEquals('DB::update() returned false', $e->getMessage());
        $this->assertEquals(
            'Unable to update the owner record. Please try again.',
            $e->getUserMessage()
        );
        $this->assertNotEquals($e->getMessage(), $e->getUserMessage());
    }

    public function testOwnerUpdateExceptionProperties(): void
    {
        $e = new OwnerUpdateException('Test update error');

        $this->assertEquals(500, $e->getHttpStatusCode());
        $this->assertEquals('OwnerActions', $e->getLogCategory());
    }

    // OwnerCreationException

    public function testOwnerCreationExceptionWithUserMessageReturnsSpecificText(): void
    {
        $technical = 'DB::insert() failed: duplicate key on email column';
        $user = 'Unable to create your account. Please contact support.';

        $e = OwnerCreationException::withUserMessage($technical, $user);

        $this->assertEquals($technical, $e->getMessage());
        $this->assertEquals($user, $e->getUserMessage());
        $this->assertNotEquals($e->getMessage(), $e->getUserMessage());
    }

    public function testOwnerCreationExceptionDefaultFallback(): void
    {
        $e = new OwnerCreationException('DB::insert() failed');

        $this->assertEquals('DB::insert() failed', $e->getMessage());
        $this->assertEquals(
            'Unable to create the owner record. Please try again.',
            $e->getUserMessage()
        );
        $this->assertNotEquals($e->getMessage(), $e->getUserMessage());
    }

    public function testOwnerCreationExceptionProperties(): void
    {
        $e = new OwnerCreationException('Test creation error');

        $this->assertEquals(500, $e->getHttpStatusCode());
        $this->assertEquals('OwnerActions', $e->getLogCategory());
    }

    // All owner exception types

    public function testExceptionChainingWithWithUserMessage(): void
    {
        $previous = new \RuntimeException('Original DB error');

        $validationEx = OwnerValidationException::withUserMessage(
            'Validation failed',
            'Please check your input.',
            0,
            $previous
        );
        $this->assertSame($previous, $validationEx->getPrevious());

        $updateEx = OwnerUpdateException::withUserMessage(
            'Update failed',
            'Unable to save changes.',
            0,
            $previous
        );
        $this->assertSame($previous, $updateEx->getPrevious());

        $creationEx = OwnerCreationException::withUserMessage(
            'Creation failed',
            'Unable to create record.',
            0,
            $previous
        );
        $this->assertSame($previous, $creationEx->getPrevious());
    }
}
