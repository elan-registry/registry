<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use ElanRegistry\LogCategories;

/**
 * SessionTimeZoneException
 *
 * Thrown when the MySQL session time_zone cannot be set to PHP's UTC offset.
 * Without it, MySQL NOW() and PHP time can differ by one hour or more.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.31.1
 * @see https://github.com/elan-registry/registry/issues/2589
 */
class SessionTimeZoneException extends ElanRegistryException
{
    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "A database configuration error occurred. Please contact support.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_DATABASE_ERROR;
    }
}
