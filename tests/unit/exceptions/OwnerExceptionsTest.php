<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\Exceptions\OwnerCreationException;
use ElanRegistry\Exceptions\OwnerUpdateException;
use ElanRegistry\Exceptions\OwnerValidationException;
use PHPUnit\Framework\TestCase;

/**
 * OwnerExceptionsTest
 *
 * Tests for owner-specific exception classes to verify that getUserMessage()
 * returns specific text when constructed via withUserMessage(), and falls back
 * to the class-level generic default when the 4th constructor arg is omitted.
 *
 * Regression coverage for GitHub issue #927: catch blocks that called getMessage()
 * instead of getUserMessage() were exposing internal technical messages to users.
 * The fix converts all owner exception throws to use withUserMessage() so that
 * getUserMessage() always returns the specific, user-safe text for each throw site.
 *
 * @issue 927
 * @link https://github.com/unibrain1/elanregistry/issues/927
 */
class OwnerExceptionsTest extends TestCase
{
    // =========================================================================
    // OwnerValidationException
    // =========================================================================

    /**
     * Regression test for #927: withUserMessage() must propagate the specific
     * text through getUserMessage() — not the class-level generic default.
     *
     * Tests the case where technical and user messages are identical (e.g.
     * field-level validation where the message is already user-safe).
     */
    public function testOwnerValidationExceptionWithUserMessageReturnsSpecificText(): void
    {
        $technical = 'Invalid email format';
        $user = 'Invalid email format';

        $e = OwnerValidationException::withUserMessage($technical, $user);

        $this->assertEquals($technical, $e->getMessage());
        $this->assertEquals($user, $e->getUserMessage());
    }

    /**
     * Regression test for #927: withUserMessage() must return the specific
     * user text even when technical and user messages differ.
     *
     * Mirrors the CSRF scenario where the technical message contains internal
     * state that must not reach the browser.
     */
    public function testOwnerValidationExceptionWithUserMessageDiffersFromTechnical(): void
    {
        $technical = 'Invalid CSRF token provided';
        $user = 'Your session may have expired. Please refresh the page and try again.';

        $e = OwnerValidationException::withUserMessage($technical, $user);

        $this->assertEquals($technical, $e->getMessage());
        $this->assertEquals($user, $e->getUserMessage());
        $this->assertNotEquals($e->getMessage(), $e->getUserMessage());
    }

    /**
     * Documents the pre-#927 fallback behaviour: when the 4th constructor arg
     * is omitted, getUserMessage() must return the class-level generic default,
     * NOT the technical message passed as the first arg.
     *
     * This ensures that old throw sites (which omit withUserMessage) cannot
     * accidentally leak technical detail to the user.
     */
    public function testOwnerValidationExceptionDefaultFallback(): void
    {
        $e = new OwnerValidationException('Invalid email format');

        $this->assertEquals('Invalid email format', $e->getMessage());
        $this->assertEquals(
            'The owner information provided is invalid. Please check your input.',
            $e->getUserMessage()
        );
        // The technical message is NOT exposed as the user message when 4th arg is omitted
        $this->assertNotEquals($e->getMessage(), $e->getUserMessage());
    }

    /**
     * Test OwnerValidationException class properties and hierarchy.
     */
    public function testOwnerValidationExceptionProperties(): void
    {
        $e = new OwnerValidationException('Test validation error');

        $this->assertInstanceOf(OwnerValidationException::class, $e);
        $this->assertInstanceOf(ElanRegistryException::class, $e);
        $this->assertEquals(422, $e->getHttpStatusCode());
        $this->assertEquals('ValidationError', $e->getLogCategory());
    }

    // =========================================================================
    // OwnerUpdateException
    // =========================================================================

    /**
     * Regression test for #927: withUserMessage() must return the specific
     * user text for OwnerUpdateException.
     *
     * Mirrors the scenario where a database error occurs and the raw DB message
     * must not be shown to the user.
     */
    public function testOwnerUpdateExceptionWithUserMessageReturnsSpecificText(): void
    {
        $technical = 'DB::update() returned false for users table, ID 42';
        $user = 'Unable to save your profile changes. Please try again.';

        $e = OwnerUpdateException::withUserMessage($technical, $user);

        $this->assertEquals($technical, $e->getMessage());
        $this->assertEquals($user, $e->getUserMessage());
        $this->assertNotEquals($e->getMessage(), $e->getUserMessage());
    }

    /**
     * Documents the fallback behaviour for OwnerUpdateException when
     * the 4th constructor arg is omitted.
     */
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

    /**
     * Test OwnerUpdateException class properties and hierarchy.
     */
    public function testOwnerUpdateExceptionProperties(): void
    {
        $e = new OwnerUpdateException('Test update error');

        $this->assertInstanceOf(OwnerUpdateException::class, $e);
        $this->assertInstanceOf(ElanRegistryException::class, $e);
        $this->assertEquals(500, $e->getHttpStatusCode());
        $this->assertEquals('OwnerActions', $e->getLogCategory());
    }

    // =========================================================================
    // OwnerCreationException
    // =========================================================================

    /**
     * Regression test for #927: withUserMessage() must return the specific
     * user text for OwnerCreationException.
     */
    public function testOwnerCreationExceptionWithUserMessageReturnsSpecificText(): void
    {
        $technical = 'DB::insert() failed: duplicate key on email column';
        $user = 'Unable to create your account. Please contact support.';

        $e = OwnerCreationException::withUserMessage($technical, $user);

        $this->assertEquals($technical, $e->getMessage());
        $this->assertEquals($user, $e->getUserMessage());
        $this->assertNotEquals($e->getMessage(), $e->getUserMessage());
    }

    /**
     * Documents the fallback behaviour for OwnerCreationException when
     * the 4th constructor arg is omitted.
     */
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

    /**
     * Test OwnerCreationException class properties and hierarchy.
     */
    public function testOwnerCreationExceptionProperties(): void
    {
        $e = new OwnerCreationException('Test creation error');

        $this->assertInstanceOf(OwnerCreationException::class, $e);
        $this->assertInstanceOf(ElanRegistryException::class, $e);
        $this->assertEquals(500, $e->getHttpStatusCode());
        $this->assertEquals('OwnerActions', $e->getLogCategory());
    }

    // =========================================================================
    // Cross-cutting: withUserMessage() factory is available on all owner types
    // =========================================================================

    /**
     * Verify exception chaining works correctly with withUserMessage() for all
     * three owner exception types.
     */
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

    /**
     * Verify all three owner exception types are catchable as ElanRegistryException,
     * which is required for the shared error-handling catch blocks in action files.
     */
    public function testAllOwnerExceptionsAreCatchableAsElanRegistryException(): void
    {
        $classes = [
            OwnerValidationException::class,
            OwnerUpdateException::class,
            OwnerCreationException::class,
        ];

        foreach ($classes as $class) {
            $caught = false;
            try {
                throw new $class('Test error');
            } catch (ElanRegistryException $e) {
                $caught = true;
                $this->assertInstanceOf($class, $e);
            }
            $this->assertTrue($caught, "{$class} should be catchable as ElanRegistryException");
        }
    }
}
