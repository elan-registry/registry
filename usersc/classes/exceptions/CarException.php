<?php

declare(strict_types=1);

/**
 * CarException - Abstract base class for all Car domain exceptions
 *
 * Provides a domain-specific base for car-related exceptions,
 * enabling catch blocks to handle all car errors uniformly.
 * All car-specific exceptions MUST extend this class.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.14.0
 * @abstract
 */
abstract class CarException extends ElanRegistryException
{
    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "A car operation error occurred.";
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
        return 500;
    }
}
