<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use ElanRegistry\LogCategories;

/**
 * CarConcurrentModificationException
 *
 * Thrown when a compare-and-swap write is rejected because the stored value
 * changed between the read and the write (lost-update / TOCTOU race).
 * Callers should treat this as retriable rather than a server error.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.27.0
 */
class CarConcurrentModificationException extends CarDatabaseException
{
    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "The record was modified by another request. Please refresh and try again.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_CAR_ERRORS;
    }
}
