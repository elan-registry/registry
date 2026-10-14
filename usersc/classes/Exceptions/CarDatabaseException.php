<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use ElanRegistry\LogCategories;

/**
 * CarDatabaseException
 *
 * Exception thrown when car-related database operations fail.
 * Used for query, insert, update, delete, and transaction failures
 * across car-related data access operations.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.14.0
 */
class CarDatabaseException extends CarException
{
    /**
     * Get the SQLSTATE from the message
     *
     * DB::errorString() writes "ERROR #<sqlstate>: <driver text>". The driver
     * text can hold an email address, but the SQLSTATE cannot. A log line can
     * so name the database error without the message (#2359).
     *
     * @return string|null The 5-character SQLSTATE, or null when the message has none
     */
    public function sqlState(): ?string
    {
        return preg_match('/ERROR #([0-9A-Z]{5})\b/', $this->getMessage(), $matches) === 1 ? $matches[1] : null;
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "A database error occurred while processing the car record.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_DATABASE_ERROR;
    }
}
