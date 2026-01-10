<?php

/**
 * Exception thrown when image processing operations fail
 */
class ImageProcessingException extends Exception
{
    public function __construct(string $message = "Image processing failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}