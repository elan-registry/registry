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
     * Brevo tag on every transactional verification email
     *
     * Attached explicitly by `CarVerificationSendService`, which passes it to
     * `email()` as `$opts['tags']`; the sendinblue plugin's override maps that
     * to Brevo `tags`. The Brevo webhook receiver uses it to filter inbound
     * delivery-status events down to those relevant to the car verification
     * system.
     *
     * @since v2.30.2
     * @see https://github.com/elan-registry/registry/issues/1887
     */
    public const VERIFICATION_EMAIL_TAG = 'car_verification';

    /**
     * Brevo tag on every owner contact email
     *
     * `app/api/contact/send-owner-email.php` passes it to `email()` as
     * `$opts['tags']`. The value is the same tag that the sendinblue plugin's
     * `caller` tag mode writes for that file. So the soft-bounce count also
     * skips owner contact mail that was sent before this tag was explicit,
     * if `tag_mode` was `caller` at that time.
     * The explicit tag keeps the tag when `tag_mode` is `off`.
     * {@see \ElanRegistry\Car\CarRepository::countSoftBouncesSinceLastDelivered()}
     * reads it from `plg_sendinblue_messages.tags`. Do not change the value
     * without a data migration, because stored rows keep the old tag.
     *
     * @since v2.30.9
     * @see https://github.com/elan-registry/registry/issues/2460
     */
    public const OWNER_CONTACT_EMAIL_TAG = 'send-owner-email';
}
