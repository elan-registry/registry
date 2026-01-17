<?php

declare(strict_types=1);

/**
 * BackupException
 *
 * Exception thrown when backup operations fail.
 * Used when database backups, schema operations, or backup-related tasks
 * encounter errors.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class BackupException extends ElanRegistryException
{
    /**
     * Get default user message for backup failures
     *
     * @return string User-friendly error message
     */
    protected function getDefaultUserMessage(): string
    {
        return "A backup operation failed. Please try again or contact support.";
    }

    /**
     * Get log category for backup exceptions
     *
     * @return string Log category constant
     */
    protected function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_BACKUP_ERROR;
    }
}
