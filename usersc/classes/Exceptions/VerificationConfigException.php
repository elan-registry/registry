<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use ElanRegistry\LogCategories;

/**
 * VerificationConfigException
 *
 * Thrown when the verification system cannot be enabled because a required
 * prerequisite is not configured (currently the Brevo email integration).
 *
 * Only raised by the "enable" gate — disabling verification never throws.
 * Consumed by ApiResponse::validationError() on the admin settings surface.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.30.2
 */
class VerificationConfigException extends ElanRegistryException
{
    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "Verification cannot be enabled: the Brevo email integration is not configured.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING;
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultHttpStatusCode(): int
    {
        return 422;
    }
}
