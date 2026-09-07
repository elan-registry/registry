<?php

declare(strict_types=1);

namespace ElanRegistry;

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

    /**
     * Brevo tag attached to every transactional verification email
     *
     * Used by the Brevo webhook receiver to filter inbound delivery-status
     * events down to those relevant to the car verification system.
     *
     * @since v2.30.2
     * @see https://github.com/elan-registry/registry/issues/1887
     */
    public const VERIFICATION_EMAIL_TAG = 'car_verification';
}
