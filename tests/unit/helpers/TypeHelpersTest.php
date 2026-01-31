<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for type helper functions (dbInt, dbIntOrNull, currentUserId)
 *
 * @group fast
 */
final class TypeHelpersTest extends TestCase
{
    // ============================================================
    // dbInt() tests
    // ============================================================

    public function testDbIntWithObjectProperty(): void
    {
        $obj = (object) ['id' => '42', 'name' => 'test'];
        $this->assertSame(42, dbInt($obj));
    }

    public function testDbIntWithObjectCustomProperty(): void
    {
        $obj = (object) ['user_id' => '7', 'name' => 'test'];
        $this->assertSame(7, dbInt($obj, 'user_id'));
    }

    public function testDbIntWithIntegerValue(): void
    {
        $this->assertSame(5, dbInt(5));
    }

    public function testDbIntWithNumericString(): void
    {
        $this->assertSame(123, dbInt('123'));
    }

    public function testDbIntWithObjectIntProperty(): void
    {
        $obj = (object) ['id' => 99];
        $this->assertSame(99, dbInt($obj));
    }

    public function testDbIntThrowsOnNull(): void
    {
        $this->expectException(InvalidArgumentException::class);
        dbInt(null);
    }

    public function testDbIntThrowsOnEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        dbInt('');
    }

    public function testDbIntThrowsOnNonNumericString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        dbInt('abc');
    }

    public function testDbIntThrowsOnMissingProperty(): void
    {
        $obj = (object) ['name' => 'test'];
        $this->expectException(InvalidArgumentException::class);
        dbInt($obj, 'id');
    }

    // ============================================================
    // dbIntOrNull() tests
    // ============================================================

    public function testDbIntOrNullWithObjectProperty(): void
    {
        $obj = (object) ['id' => '42'];
        $this->assertSame(42, dbIntOrNull($obj));
    }

    public function testDbIntOrNullWithNull(): void
    {
        $this->assertNull(dbIntOrNull(null));
    }

    public function testDbIntOrNullWithEmptyString(): void
    {
        $this->assertNull(dbIntOrNull(''));
    }

    public function testDbIntOrNullWithMissingProperty(): void
    {
        $obj = (object) ['name' => 'test'];
        $this->assertNull(dbIntOrNull($obj, 'id'));
    }

    public function testDbIntOrNullWithNumericString(): void
    {
        $this->assertSame(456, dbIntOrNull('456'));
    }

    public function testDbIntOrNullThrowsOnNonNumeric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        dbIntOrNull('abc');
    }

    // ============================================================
    // currentUserId() tests
    // ============================================================

    public function testCurrentUserIdThrowsWhenNoUser(): void
    {
        // Ensure $user global is not set
        global $user;
        $previousUser = $user ?? null;
        $user = null;

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('No user is currently logged in');
            currentUserId();
        } finally {
            $user = $previousUser;
        }
    }
}
