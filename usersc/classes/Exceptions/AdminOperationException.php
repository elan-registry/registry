<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use LogCategories;

/**
 * AdminOperationException
 *
 * Generic exception for admin system operation failures that don't fit other
 * specific exception categories. Used as a catch-all for unexpected errors
 * in admin interfaces including profile loading, data retrieval, and sync operations.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.12.0
 */
class AdminOperationException extends ElanRegistryException
{
    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "An error occurred during the operation. Please try again.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_SYSTEM_ERROR;
    }
}
