<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use ElanRegistry\LogCategories;

/**
 * CarTransferException
 *
 * Exception thrown for car transfer conflicts — primarily concurrent-approval
 * (TOCTOU) races where a second admin processes a request that another already
 * claimed. Returns HTTP 409 Conflict. Validation failures use
 * CarValidationException; database/infrastructure failures use CarDatabaseException.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class CarTransferException extends CarException
{
    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "Unable to transfer the car. Please try again.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_CAR_TRANSFER_ERROR;
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultHttpStatusCode(): int
    {
        return 409;
    }
}
