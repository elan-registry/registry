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
     * Note: PHP constructors cannot have return type declarations
     *
     * @param string $message Exception message
     * @param int $code Exception code (optional)
     * @param Exception|null $previous Previous exception for chaining (optional)
     */
    // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
    public function __construct(string $message, int $code = 0, ?Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}
