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
class ImageProcessingException extends Exception
{
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code (optional)
     * @param Throwable|null $previous Previous exception for chaining (optional)
     */
    public function __construct(string $message = "Image processing failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
