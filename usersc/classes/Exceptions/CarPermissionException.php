<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use ElanRegistry\LogCategories;

/**
 * CarPermissionException
 *
 * Exception thrown when a car operation is denied due to
 * insufficient permissions or authentication failures.
 *
 * NOTE: No production code throws this directly after v2.28.0 removed the
 * isLoggedIn() guard from Car methods. Retained for hierarchy completeness
 * (provides the 403 slot in CarException) and to avoid breaking callers that
 * catch CarPermissionException specifically.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.14.0
 */
class CarPermissionException extends CarException
{
    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "You do not have permission to perform this car operation.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_ACCESS_DENIED;
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultHttpStatusCode(): int
    {
        return 403;
    }
}
