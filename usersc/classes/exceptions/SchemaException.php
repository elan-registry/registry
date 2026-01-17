<?php

declare(strict_types=1);

/**
 * SchemaException
 *
 * Exception thrown when database schema operations fail.
 * Used when table creation, modification, or schema-related operations
 * encounter errors.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class SchemaException extends ElanRegistryException
{
    /**
     * Get default user message for schema operation failures
     *
     * @return string User-friendly error message
     */
    protected function getDefaultUserMessage(): string
    {
        return "A database operation failed. Please try again or contact support.";
    }

    /**
     * Get log category for schema exceptions
     *
     * @return string Log category constant
     */
    protected function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_DATABASE_ERROR;
    }
}
