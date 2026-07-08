<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use ElanRegistry\LogCategories;

/**
 * BackupException
 *
 * Exception thrown when backup operations fail.
 * Used when backup creation, restoration, or cleanup operations encounter errors.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.9.2
 */
class BackupException extends ElanRegistryException
{
    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "A backup operation failed. Please contact support.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_BACKUP_ERROR;
    }
}
