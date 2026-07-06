<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use LogCategories;

/**
 * CarMergeException
 *
 * Exception thrown when car merge operations fail.
 * Used when attempting to merge duplicate car records encounters
 * errors or data conflicts.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class CarMergeException extends CarException
{
    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "Unable to merge the car records. Please try again.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_CAR_MERGE;
    }
}
