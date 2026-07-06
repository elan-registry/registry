<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use LogCategories;

/**
 * CarDatabaseException
 *
 * Exception thrown when car-related database operations fail.
 * Used for insert, update, delete, and transaction failures
 * in the Car class.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.14.0
 */
class CarDatabaseException extends CarException
{
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
