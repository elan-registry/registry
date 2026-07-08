<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use ElanRegistry\LogCategories;

/**
 * OwnerUpdateException
 *
 * Exception thrown when owner update operations fail.
 * Used when database update errors occur or validation fails during
 * owner record modifications.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class OwnerUpdateException extends ElanRegistryException
{
    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "Unable to update the owner record. Please try again.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_OWNER_ACTIONS;
    }
}
