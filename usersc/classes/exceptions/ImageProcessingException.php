<?php

declare(strict_types=1);

/**
 * ImageProcessingException
 *
 * Exception thrown when image processing operations fail.
 * Used when image upload, resize, or manipulation operations
 * encounter errors.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class ImageProcessingException extends ElanRegistryException
{
    /**
     * Get default user message for image processing failures
     *
     * @return string User-friendly error message
     */
    protected function getDefaultUserMessage(): string
    {
        return "Unable to process the image. Please ensure it's a valid image file and try again.";
    }

    /**
     * Get log category for image processing exceptions
     *
     * @return string Log category constant
     */
    protected function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_FILE_ERROR;
    }
}
