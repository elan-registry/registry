<?php

declare(strict_types=1);

/**
 * CarTransferException
 *
 * Exception thrown when car ownership transfer operations fail.
 * Used during the car transfer workflow when database updates,
 * validation, or user permission checks fail.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class CarTransferException extends ElanRegistryException
{
    /**
     * Get default user message for car transfer failures
     *
     * @return string User-friendly error message
     */
    protected function getDefaultUserMessage(): string
    {
        return "Unable to transfer the car ownership. Please try again or contact support.";
    }

    /**
     * Get log category for car transfer exceptions
     *
     * @return string Log category constant
     */
    protected function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_CAR_TRANSFER_ERROR;
    }
}
