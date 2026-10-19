<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

/**
 * ExceptionLogSummary - a log-safe summary of an exception
 *
 * An exception message can hold an email address. CarRepository puts the
 * address in its messages, and driver text can echo it. An address is
 * personal data that a log line must not hold, so the summary never includes
 * the message. It gives the class, the code, the SQLSTATE of a
 * CarDatabaseException, and the place of the throw. Most of these exceptions have code 0, so the SQLSTATE
 * and the place are the details that tell one failure from another.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.30.8
 */
final class ExceptionLogSummary
{
    /**
     * Summarize an exception for a log line, without its message
     *
     * @param \Throwable $e The caught exception
     * @return string "<Class> (code <code>[, SQLSTATE <x>]) at <file>:<line>"
     */
    public static function of(\Throwable $e): string
    {
        $sqlState = $e instanceof CarDatabaseException ? $e->sqlState() : null;

        return sprintf(
            '%s (code %s%s) at %s:%d',
            get_class($e),
            (string) $e->getCode(),
            $sqlState === null ? '' : ", SQLSTATE {$sqlState}",
            basename($e->getFile()),
            $e->getLine()
        );
    }
}
