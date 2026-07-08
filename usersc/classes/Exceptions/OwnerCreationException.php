<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use ElanRegistry\LogCategories;

/**
 * OwnerCreationException
 *
 * Exception thrown when owner creation operations fail.
 * Used when database insertion or validation errors occur during
 * new owner record creation.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class OwnerCreationException extends ElanRegistryException
{
    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "Unable to create the owner record. Please try again.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_OWNER_ACTIONS;
    }
}
