<?php

declare(strict_types=1);

/**
 * Centralized Application Constants
 *
 * Provides shared constants used across multiple classes and pages.
 * For log category constants, see LogCategories.
 *
 * @package    ElanRegistry
 * @subpackage Classes
 * @since      v2.14.0
 */
class AppConstants
{
    /**
     * Standard datetime format used for database timestamps
     *
     * Used consistently across Car, Owner, and admin pages
     * for ctime/mtime fields and other datetime columns.
     */
    public const DATETIME_FORMAT = 'Y-m-d H:i:s';
}
