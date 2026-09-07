<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use ElanRegistry\LogCategories;

/**
 * OwnerDatabaseException
 *
 * Exception thrown when owner-related database operations fail.
 * Used for query, transaction, and rollback failures across owner-related
 * data access operations. Mirrors CarDatabaseException's shape, extending
 * the OwnerException abstract base (see #1654). Also used by
 * Owner::syncOwnerFieldsToCars() to signal a required owner record that
 * was never loaded (#1979) — not itself a failed query, but a precondition
 * violation every caller already handles identically to a real DB failure.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.29.5
 */
class OwnerDatabaseException extends OwnerException
{
    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "A database error occurred while processing the owner record.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_DATABASE_ERROR;
    }
}
