<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use LogCategories;

/**
 * CarNotFoundException
 *
 * Exception thrown when a requested car cannot be found in the database.
 * Used throughout the Car class and related components when operations
 * are attempted on non-existent car records.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class CarNotFoundException extends CarException
{
    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "The requested car could not be found.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_CAR_ERRORS;
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultHttpStatusCode(): int
    {
        return 404;
    }
}
