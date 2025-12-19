<?php
declare(strict_types=1);

/**
 * SchemaException
 *
 * Custom exception class for schema management errors
 *
 * @package ElanRegistry
 * @subpackage Schema
 * @since v2.9.2
 */
class SchemaException extends Exception {
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code (optional)
     * @param Exception|null $previous Previous exception for chaining (optional)
     * @return void
     */
    public function __construct(string $message, int $code = 0, ?Exception $previous = null): void {
        parent::__construct($message, $code, $previous);
    }
}
