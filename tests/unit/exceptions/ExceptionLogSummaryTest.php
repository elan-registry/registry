<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\ExceptionLogSummary;
use PHPUnit\Framework\TestCase;

/**
 * #2359: a log line must not hold an email address, so it cannot hold an
 * exception message. The summary must still tell one failure from another,
 * because CarRepository throws CarDatabaseException with code 0 everywhere.
 */
class ExceptionLogSummaryTest extends TestCase
{
    private const ADDRESS = 'leak-2359@example.org';

    /** The form of DB::errorString(), with an address in the driver text. */
    private const DUPLICATE_KEY_MESSAGE = 'Failed to save the event: ERROR #23000: Duplicate entry \''
        . self::ADDRESS . '\' for key \'users.email\'';

    public function testSqlStateReadsTheStateFromTheDbErrorPrefix(): void
    {
        $e = new CarDatabaseException(self::DUPLICATE_KEY_MESSAGE);

        $this->assertSame('23000', $e->sqlState());
    }

    public function testSqlStateReadsAStateWithLetters(): void
    {
        $e = new CarDatabaseException('ERROR #HY000: General error');

        $this->assertSame('HY000', $e->sqlState());
    }

    public function testSqlStateIsNullWithoutTheDbErrorPrefix(): void
    {
        $e = new CarDatabaseException('Failed to update the car for ' . self::ADDRESS);

        $this->assertNull($e->sqlState());
    }

    public function testSqlStateIsNullForAPrefixThatIsNotFiveCharacters(): void
    {
        $this->assertNull((new CarDatabaseException('ERROR #2300: text'))->sqlState());
        $this->assertNull((new CarDatabaseException('ERROR #230001: text'))->sqlState());
    }

    public function testSqlStateDoesNotHoldTheDriverText(): void
    {
        $e = new CarDatabaseException(self::DUPLICATE_KEY_MESSAGE);

        $this->assertStringNotContainsString(self::ADDRESS, (string) $e->sqlState());
    }

    public function testSummaryOfACarDatabaseExceptionHasClassCodeStateAndPlace(): void
    {
        $line = __LINE__ + 1;
        $e = new CarDatabaseException(self::DUPLICATE_KEY_MESSAGE);

        $this->assertSame(
            CarDatabaseException::class . " (code 0, SQLSTATE 23000) at ExceptionLogSummaryTest.php:{$line}",
            ExceptionLogSummary::of($e)
        );
    }

    public function testSummaryHasNoMessageText(): void
    {
        $summary = ExceptionLogSummary::of(new CarDatabaseException(self::DUPLICATE_KEY_MESSAGE));

        $this->assertStringNotContainsString(self::ADDRESS, $summary);
        $this->assertStringNotContainsString('Duplicate entry', $summary);
        $this->assertStringNotContainsString('Failed to save', $summary);
    }

    public function testSummaryOfAnotherThrowableHasNoState(): void
    {
        $line = __LINE__ + 1;
        $e = new \RuntimeException('ERROR #23000: ' . self::ADDRESS, 42);

        $this->assertSame(
            "RuntimeException (code 42) at ExceptionLogSummaryTest.php:{$line}",
            ExceptionLogSummary::of($e)
        );
    }

    public function testSummaryKeepsAStringCode(): void
    {
        // PDOException carries the SQLSTATE as a string code.
        $e = new \PDOException('SQLSTATE[HY000]: ' . self::ADDRESS);
        $reflection = new \ReflectionProperty(\Exception::class, 'code');
        $reflection->setValue($e, 'HY000');

        $summary = ExceptionLogSummary::of($e);

        $this->assertStringStartsWith('PDOException (code HY000) at ExceptionLogSummaryTest.php:', $summary);
        $this->assertStringNotContainsString(self::ADDRESS, $summary);
    }
}
